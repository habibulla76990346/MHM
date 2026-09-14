<?php

namespace App\Domains\Diagnostics\Services;

use App\Domains\Diagnostics\CheckRegistry;
use App\Domains\Diagnostics\Models\DiagnosticBaseline;
use App\Domains\Diagnostics\Models\DiagnosticResult;
use App\Domains\Diagnostics\Models\DiagnosticRun;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\DeploymentMode;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Notifications\Services\Notifier;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Running the catalogue, remembering the answer, and telling somebody when it
 * CHANGES (Owner Addendum G, Phase 9).
 *
 * THE ALERTING RULE IS THE WHOLE POINT. Sending a message for every red would
 * send the same message every night until it is fixed, which is how an owner
 * learns to file these somewhere they never look — and then misses the one
 * that mattered. So a message goes out when a check TRANSITIONS: healthy to
 * broken, or broken back to healthy. Nothing is sent while a known problem
 * stays known.
 *
 * IT NEVER STORES A SECRET, and not because it filters. `CheckResult` scrubs
 * at construction, so by the time a finding reaches this class there is no
 * unredacted value left to write.
 *
 * A SCHEDULED RUN IS NARROWER THAN A MANUAL ONE. Checks that cost money — an
 * authenticated call to an AI provider — or that have side effects are
 * excluded, because a nightly job that spends the owner's provider budget on
 * diagnostics is a bill they never agreed to.
 */
class DiagnosticRunner
{
    public function __construct(
        private readonly CheckRegistry $registry,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Run, record, and announce anything that changed.
     *
     * @return array{run: DiagnosticRun, results: array<int, CheckResult>, transitions: array<int, array<string, mixed>>}
     */
    public function run(string $trigger = DiagnosticRun::MANUAL, ?User $actor = null, bool $notify = true): array
    {
        $startedAt = now();
        $automaticOnly = $trigger === DiagnosticRun::SCHEDULED;

        $results = $this->registry->run(automaticOnly: $automaticOnly);

        $run = $this->record($trigger, $actor, $results, $startedAt);
        $transitions = $this->updateBaselines($results);

        if ($notify && $transitions !== []) {
            $this->announce($transitions);
        }

        return ['run' => $run, 'results' => $results, 'transitions' => $transitions];
    }

    /**
     * @param  array<int, CheckResult>  $results
     */
    private function record(string $trigger, ?User $actor, array $results, \DateTimeInterface $startedAt): DiagnosticRun
    {
        $counts = ['green' => 0, 'yellow' => 0, 'red' => 0, 'grey' => 0];

        foreach ($results as $result) {
            $counts[$result->status->value] = ($counts[$result->status->value] ?? 0) + 1;
        }

        return DB::transaction(function () use ($trigger, $actor, $results, $startedAt, $counts) {
            $run = DiagnosticRun::create([
                'trigger' => $trigger,
                'deployment_mode' => DeploymentMode::resolve()->value,
                'overall_status' => $this->worst($results)->value,
                'green' => $counts['green'],
                'yellow' => $counts['yellow'],
                'red' => $counts['red'],
                'grey' => $counts['grey'],
                'run_by' => $actor?->getKey(),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            foreach ($results as $result) {
                DiagnosticResult::create([
                    'run_id' => $run->getKey(),
                    'check_key' => $result->key,
                    'title' => mb_substr($result->title, 0, 160),
                    'category' => $result->category->value,
                    'status' => $result->status->value,
                    'severity' => $result->severity->value,
                    'responsibility' => $result->responsibility->value,
                    'technical_reason' => $result->technicalReason,
                    'recommended_action' => $result->recommendedAction,
                    'admin_action' => $result->adminAction,
                    'requires_hosting_support' => $result->requiresHostingSupport,
                    'support_wording' => $result->supportWording,
                    'log_reference' => $result->logReference,
                    'duration_ms' => (int) round($result->durationMs),
                    'checked_at' => now(),
                ]);
            }

            return $run;
        });
    }

    /**
     * Update what each check was last seen doing, and report the changes.
     *
     * A TRANSITION IS A CHANGE OF HEALTH, not of status. Yellow to red is a
     * worsening worth a message; green to grey — a check becoming inapplicable
     * because a feature was switched off — is not, and treating it as one
     * would mean an email every time an administrator turns something off.
     *
     * @param  array<int, CheckResult>  $results
     * @return array<int, array<string, mixed>>
     */
    private function updateBaselines(array $results): array
    {
        $transitions = [];

        foreach ($results as $result) {
            $baseline = DiagnosticBaseline::firstOrNew(['check_key' => $result->key]);
            $previous = $baseline->exists ? (string) $baseline->last_status : null;
            $nowStatus = $result->status->value;

            $wasHealthy = $previous !== null && $this->isHealthy($previous);
            $isHealthy = $this->isHealthy($nowStatus);

            $changed = $previous !== null && $previous !== $nowStatus;
            $healthChanged = $previous !== null && $wasHealthy !== $isHealthy;

            $baseline->fill([
                'last_status' => $nowStatus,
                'last_severity' => $result->severity->value,
                'consecutive_failures' => $isHealthy ? 0 : (int) $baseline->consecutive_failures + 1,
                'last_seen_at' => now(),
            ]);

            if ($changed) {
                $baseline->changed_at = now();
            }

            if ($healthChanged) {
                // Cleared, so the NEXT transition in either direction is
                // announced again rather than suppressed for ever.
                $baseline->notified_at = null;
            }

            $baseline->save();

            if ($healthChanged) {
                $transitions[] = [
                    'key' => $result->key,
                    'title' => $result->title,
                    'from' => $previous,
                    'to' => $nowStatus,
                    'recovered' => $isHealthy,
                    'reason' => $result->technicalReason,
                    'action' => $result->adminAction ?: $result->recommendedAction,
                    'baseline_id' => $baseline->getKey(),
                ];
            }
        }

        return $transitions;
    }

    /**
     * Tell the administrators, once per transition.
     *
     * Through `Notifier`, like everything else — there is no second path, so
     * the wording is editable, the event can be switched off, and the delivery
     * is recorded. Failure here is logged and swallowed: a diagnostics run
     * that dies because the mail server is down would hide the very finding
     * that says the mail server is down.
     *
     * @param  array<int, array<string, mixed>>  $transitions
     */
    private function announce(array $transitions): void
    {
        $unnotified = array_values(array_filter(
            $transitions,
            fn (array $t) => DiagnosticBaseline::whereKey($t['baseline_id'])->whereNull('notified_at')->exists(),
        ));

        if ($unnotified === []) {
            return;
        }

        $broke = array_values(array_filter($unnotified, fn ($t) => ! $t['recovered']));
        $fixed = array_values(array_filter($unnotified, fn ($t) => $t['recovered']));

        $summary = trim(implode("\n", array_merge(
            array_map(fn ($t) => '• '.$t['title'].' — '.$t['reason'], $broke),
            array_map(fn ($t) => '• '.$t['title'].' — working again.', $fixed),
        )));

        $actions = trim(implode("\n", array_filter(array_map(fn ($t) => $t['action'], $broke))));

        foreach ($this->administrators() as $admin) {
            try {
                $this->notifier->send($admin, NotificationEvent::DIAGNOSTIC_CHANGED, [
                    'name' => $admin->name,
                    'app_name' => (string) settings('branding.app_name'),
                    'changed_count' => (string) count($unnotified),
                    'summary' => $summary,
                    'actions' => $actions !== '' ? $actions : __('Open Admin → System Health for the detail.'),
                ]);
            } catch (Throwable $e) {
                Log::warning('Diagnostic transition notice could not be sent', ['reason' => $e->getMessage()]);
            }
        }

        DiagnosticBaseline::whereIn('id', array_column($unnotified, 'baseline_id'))
            ->update(['notified_at' => now()]);
    }

    /** @return Collection<int, User> */
    private function administrators()
    {
        return User::role([PermissionRegistry::SUPER_ADMIN, PermissionRegistry::ADMIN])->get();
    }

    private function isHealthy(string $status): bool
    {
        // GREY is healthy: "not applicable here" is an answer, not a fault.
        return in_array($status, [Status::Green->value, Status::Grey->value], true);
    }

    /** @param array<int, CheckResult> $results */
    private function worst(array $results): Status
    {
        foreach ([Status::Red, Status::Yellow, Status::Green] as $status) {
            foreach ($results as $result) {
                if ($result->status === $status) {
                    return $status;
                }
            }
        }

        return Status::Grey;
    }
}
