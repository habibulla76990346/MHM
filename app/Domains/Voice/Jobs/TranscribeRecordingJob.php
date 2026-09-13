<?php

namespace App\Domains\Voice\Jobs;

use App\Domains\AI\Contracts\SupportsTranscription;
use App\Domains\AI\DTO\TranscriptionRequest;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Diagnostics\Support\Redactor;
use App\Domains\Voice\Models\VoiceJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turn a recording into words (§18).
 *
 * QUEUED, LIKE EVERY LONG OPERATION, even though transcription is usually
 * quick: "usually" is doing a lot of work in that sentence, and a request that
 * waited would hold a PHP worker for as long as the provider took.
 *
 * THE CUSTOMER SEES THE TRANSCRIPT BEFORE IT IS SENT. This job produces text
 * and stops; nothing is said to a model on the customer's behalf. Speech
 * recognition gets names, numbers and negations wrong, and a product that
 * silently sends what it thought it heard is one that answers a question
 * nobody asked.
 */
class TranscribeRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [20];

    public int $timeout = 300;

    public function __construct(
        private readonly int $voiceJobId,
        private readonly ?int $holdId = null,
    ) {}

    public function handle(ProviderRegistry $registry, UsageRecorder $usage, CreditService $credits): void
    {
        $job = VoiceJob::with(['model.provider', 'file', 'owner'])->find($this->voiceJobId);

        if (! $job || ! $job->isWorking()) {
            // Already settled, or deleted while queued. Releasing rather than
            // returning silently, so a hold cannot outlive its job.
            $this->releaseHold($credits);

            return;
        }

        $model = $job->model;
        $file = $job->file;

        if (! $model || ! $file) {
            $this->fail($job, __('The recording is no longer available.'), $credits);

            return;
        }

        $adapter = $registry->for($model->provider);

        if (! $adapter instanceof SupportsTranscription) {
            $this->fail($job, __('The chosen provider cannot transcribe audio.'), $credits);

            return;
        }

        $job->update(['status' => VoiceJob::WORKING, 'started_at' => now()]);

        $startedAt = hrtime(true);

        try {
            $bytes = Storage::disk($file->disk)->get($file->path);

            if (! is_string($bytes) || $bytes === '') {
                throw new \RuntimeException('The recording could not be read from storage.');
            }

            $transcript = $adapter->transcribe(new TranscriptionRequest(
                modelIdentifier: (string) $model->model_identifier,
                bytes: $bytes,
                filename: (string) $file->stored_name,
                mimeType: (string) $file->detected_mime,
                language: $job->language,
            ));
        } catch (ProviderFailed $e) {
            $this->fail($job, $this->wording($e), $credits, $usage, $startedAt, $e);

            throw $e;
        } catch (Throwable $e) {
            $this->fail($job, __('The recording could not be transcribed.'), $credits, $usage, $startedAt);

            throw $e;
        }

        // The provider's own duration where it gave one; otherwise the figure
        // the browser measured, which was recorded when the job was created.
        // Never invented: this number is what the customer is charged against.
        $seconds = $transcript->seconds > 0 ? $transcript->seconds : (float) $job->seconds;

        $job->update([
            'status' => VoiceJob::COMPLETED,
            'text' => $transcript->text,
            'language' => $transcript->language ?: $job->language,
            'seconds' => round($seconds, 2),
            'characters' => mb_strlen($transcript->text),
            'completed_at' => now(),
        ]);

        $metrics = new UsageMetrics(seconds: $seconds, requests: 1);

        $usage->record(
            model: $model,
            usage: $metrics,
            user: $job->owner,
            latencyMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
            capability: Capability::TRANSCRIPTION,
        );

        $this->settle($credits, (float) $usage->cost($model, $metrics)['credit_cost'], $job);
    }

    /** The last word when the queue gives up: never a row stuck at "working". */
    public function failed(?Throwable $e): void
    {
        $job = VoiceJob::find($this->voiceJobId);

        if ($job && $job->isWorking()) {
            $job->update([
                'status' => VoiceJob::FAILED,
                'failure_reason' => __('The recording could not be transcribed. No credits were charged.'),
                'completed_at' => now(),
            ]);
        }

        $this->releaseHold(app(CreditService::class));
    }

    private function settle(CreditService $credits, float $cost, VoiceJob $job): void
    {
        $hold = $this->hold();

        if (! $hold) {
            return;
        }

        if ($credits->settle($hold, $cost, __('Voice transcription'))) {
            $job->update(['credit_cost' => round($cost, 6)]);
        }
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
            // A failed call still cost the owner a request at the provider.
            $usage->record(
                model: $job->model,
                usage: new UsageMetrics(requests: 1),
                user: $job->owner,
                latencyMs: $startedAt ? (int) round((hrtime(true) - $startedAt) / 1_000_000) : null,
                httpStatus: $failure?->httpStatus,
                errorClass: $failure?->errorClass,
                capability: Capability::TRANSCRIPTION,
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
            ErrorClass::INVALID_REQUEST => __('That recording could not be read. Try recording it again.'),
            ErrorClass::QUOTA_EXCEEDED => __('The voice service has reached its spending limit. An administrator needs to look at it.'),
            default => __('The recording could not be transcribed. No credits were charged.'),
        };
    }
}
