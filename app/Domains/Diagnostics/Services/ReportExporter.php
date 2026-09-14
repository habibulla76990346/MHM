<?php

namespace App\Domains\Diagnostics\Services;

use App\Domains\Diagnostics\Models\DiagnosticRun;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\DeploymentMode;
use App\Domains\Diagnostics\Support\Redactor;

/**
 * The report an owner forwards to their hosting provider (Owner Addendum G).
 *
 * THE WHOLE POINT IS THAT IT CAN BE SENT TO A STRANGER. An owner on shared
 * hosting cannot diagnose anything themselves; what they can do is paste a
 * page into a support ticket. That is only useful if it says exactly what is
 * wrong in words a support agent acts on — and only SAFE if it contains
 * nothing that matters if the ticket is read by six people and archived for
 * ever.
 *
 * SO IT CONTAINS NO CREDENTIAL, and not because it filters at the end.
 * `CheckResult` scrubs at construction, so nothing unredacted exists by the
 * time it reaches here; this class scrubs a second time on the way out, on the
 * principle that the last thing before the door is the cheapest place to put a
 * second lock. It also carries no database name, no absolute path and no
 * hostname beyond the site's own public address, because none of those help a
 * support agent and all of them help somebody else.
 *
 * IT IS TEXT, not JSON, by default. The reader is a support agent, not a
 * parser, and a wall of braces gets skimmed.
 */
class ReportExporter
{
    /** @param array<int, CheckResult> $results */
    public function toText(array $results, ?DiagnosticRun $run = null): string
    {
        $lines = [];
        $lines[] = 'Aziv AI — system health report';
        $lines[] = 'Generated: '.now()->toDayDateTimeString().' ('.config('app.timezone').')';
        $lines[] = 'Version:   '.config('aziv.version');
        $lines[] = 'PHP:       '.PHP_VERSION.' via '.PHP_SAPI;
        $lines[] = 'Mode:      '.DeploymentMode::resolve()->label();
        $lines[] = '';
        $lines[] = 'This report contains no passwords, API keys or other credentials.';
        $lines[] = 'It is safe to send to your hosting provider.';
        $lines[] = str_repeat('=', 72);

        foreach ($this->order($results) as $result) {
            $lines[] = '';
            $lines[] = strtoupper($result->status->value).' — '.$result->title;
            $lines[] = '  Area:  '.$result->category->label().' · '.$result->responsibility->label();

            if ($result->technicalReason !== '') {
                $lines[] = '  What:  '.$result->technicalReason;
            }

            if ($result->recommendedAction !== '') {
                $lines[] = '  Why:   '.$result->recommendedAction;
            }

            if ($result->adminAction !== '') {
                $lines[] = '  Fix:   '.$result->adminAction;
            }

            if ($result->requiresHostingSupport && $result->supportWording !== '') {
                // The sentence to paste into the ticket, written for the agent
                // rather than for the owner.
                $lines[] = '  ASK YOUR HOST: "'.$result->supportWording.'"';
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 72);
        $lines[] = $this->summary($results);

        if ($run) {
            $lines[] = 'Reference: '.$run->uuid;
        }

        // The second lock. Nothing unredacted should reach this point; the
        // cost of proving it is one function call.
        return Redactor::scrub(implode("\n", $lines))."\n";
    }

    /** A filename that says what it is and when, and names nothing else. */
    public function filename(): string
    {
        return 'aziv-health-'.now()->format('Y-m-d-Hi').'.txt';
    }

    /**
     * @param  array<int, CheckResult>  $results
     * @return array<int, CheckResult>
     */
    private function order(array $results): array
    {
        // Problems first. A support agent reads the top of a ticket.
        usort($results, fn (CheckResult $a, CheckResult $b) => [$a->severity->rank(), $a->title]
            <=> [$b->severity->rank(), $b->title]);

        return $results;
    }

    /** @param array<int, CheckResult> $results */
    private function summary(array $results): string
    {
        $counts = [];

        foreach ($results as $result) {
            $counts[$result->status->value] = ($counts[$result->status->value] ?? 0) + 1;
        }

        return sprintf(
            'Summary: %d working · %d limited · %d problems · %d not applicable',
            $counts['green'] ?? 0,
            $counts['yellow'] ?? 0,
            $counts['red'] ?? 0,
            $counts['grey'] ?? 0,
        );
    }
}
