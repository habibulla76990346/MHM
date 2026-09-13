<?php

namespace App\Domains\Voice\Jobs;

use App\Domains\AI\Contracts\SupportsSpeech;
use App\Domains\AI\DTO\SpeechRequest;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Diagnostics\Support\Redactor;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Voice\Models\VoiceJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Read a reply aloud (§18).
 *
 * THE BYTES ARE TYPE-CHECKED BEFORE THEY ARE STORED, by content, against a
 * fixed allowlist — because a provider returning an HTML error page with a 200
 * is a thing that happens, and writing it under `.mp3` would make it something
 * the browser is asked to play from this application's origin.
 */
class SynthesiseSpeechJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [20];

    public int $timeout = 300;

    public function __construct(
        private readonly int $voiceJobId,
        private readonly ?int $holdId = null,
    ) {}

    public function handle(
        ProviderRegistry $registry,
        FileStorage $storage,
        UsageRecorder $usage,
        CreditService $credits,
    ): void {
        $job = VoiceJob::with(['model.provider', 'owner'])->find($this->voiceJobId);

        if (! $job || ! $job->isWorking()) {
            $this->releaseHold($credits);

            return;
        }

        $model = $job->model;

        if (! $model) {
            $this->fail($job, __('The model that was going to read this is no longer available.'), $credits);

            return;
        }

        $adapter = $registry->for($model->provider);

        if (! $adapter instanceof SupportsSpeech) {
            $this->fail($job, __('The chosen provider cannot read text aloud.'), $credits);

            return;
        }

        $job->update(['status' => VoiceJob::WORKING, 'started_at' => now()]);

        $startedAt = hrtime(true);

        try {
            $speech = $adapter->synthesise(new SpeechRequest(
                modelIdentifier: (string) $model->model_identifier,
                text: (string) $job->text,
                // The owner's chosen voice, passed through UNCHANGED. Every
                // provider names its voices differently and there is no
                // common vocabulary to translate into, so this is the one
                // provider-shaped value in the voice path — a setting an
                // owner fills in from their provider's own list. Empty means
                // the provider's default, which is the right answer for an
                // owner who has not chosen.
                voice: ((string) settings('voice.speech_voice')) ?: null,
                format: 'mp3',
            ));

            $file = $storage->storeGenerated(
                bytes: $speech->bytes,
                owner: $job->owner,
                purpose: 'speech',
                displayName: __('spoken reply'),
            );
        } catch (ProviderFailed $e) {
            $this->fail($job, $this->wording($e), $credits, $usage, $startedAt, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->fail($job, __('The reply could not be read aloud.'), $credits, $usage, $startedAt);

            throw $e;
        }

        $job->update([
            'status' => VoiceJob::COMPLETED,
            'file_id' => $file->getKey(),
            'completed_at' => now(),
        ]);

        // Charged on what the provider actually bills for. Both units go in;
        // whichever the model is priced in produces the number.
        $metrics = new UsageMetrics(
            inputTokens: (int) ceil($job->characters / 4),
            seconds: (float) $job->seconds,
            requests: 1,
        );

        $usage->record(
            model: $model,
            usage: $metrics,
            user: $job->owner,
            latencyMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
            capability: Capability::SPEECH,
        );

        $cost = (float) $usage->cost($model, $metrics)['credit_cost'];
        $hold = $this->hold();

        if ($hold && $credits->settle($hold, $cost, __('Reading a reply aloud'))) {
            $job->update(['credit_cost' => round($cost, 6)]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = VoiceJob::find($this->voiceJobId);

        if ($job && $job->isWorking()) {
            $job->update([
                'status' => VoiceJob::FAILED,
                'failure_reason' => __('The reply could not be read aloud. No credits were charged.'),
                'completed_at' => now(),
            ]);
        }

        $this->releaseHold(app(CreditService::class));
    }

    private function fail(
        VoiceJob $job,
        string $reason,
        CreditService $credits,
        ?UsageRecorder $usage = null,
        ?float $startedAt = null,
        ?ProviderFailed $failure = null,
    ): void {
        $job->update([
            'status' => VoiceJob::FAILED,
            'failure_reason' => mb_substr(Redactor::scrub($reason), 0, 500),
            'completed_at' => now(),
        ]);

        if ($usage && $job->model) {
            $usage->record(
                model: $job->model,
                usage: new UsageMetrics(requests: 1),
                user: $job->owner,
                latencyMs: $startedAt ? (int) round((hrtime(true) - $startedAt) / 1_000_000) : null,
                httpStatus: $failure?->httpStatus,
                errorClass: $failure?->errorClass,
                capability: Capability::SPEECH,
            );
        }

        $this->releaseHold($credits);
    }

    private function releaseHold(CreditService $credits): void
    {
        $hold = $this->hold();

        if ($hold) {
            $credits->release($hold);
        }
    }

    private function hold(): ?CreditHold
    {
        return $this->holdId ? CreditHold::find($this->holdId) : null;
    }

    private function wording(ProviderFailed $e): string
    {
        return match ($e->errorClass) {
            ErrorClass::RATE_LIMIT => __('The voice service is busy. Please try again in a minute.'),
            ErrorClass::QUOTA_EXCEEDED => __('The voice service has reached its spending limit. An administrator needs to look at it.'),
            default => __('The reply could not be read aloud. No credits were charged.'),
        };
    }
}
