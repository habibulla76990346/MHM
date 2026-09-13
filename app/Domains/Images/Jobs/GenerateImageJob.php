<?php

namespace App\Domains\Images\Jobs;

use App\Domains\AI\Contracts\SupportsImageGeneration;
use App\Domains\AI\DTO\GeneratedImage;
use App\Domains\AI\DTO\ImageRequest;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Diagnostics\Support\Redactor;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Images\Models\ImageGeneration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Make the pictures a customer asked for.
 *
 * QUEUED, LIKE EVERY LONG OPERATION. Image generation takes tens of seconds; a
 * request that waited for it would hold a PHP worker on shared hosting and
 * time out behind most proxies. Shared hosting differs in SPEED here, never in
 * capability — the cron-driven queue runs the same job.
 *
 * ONE CALL FOR THE WHOLE BATCH. Providers charge and rate-limit per request,
 * and asking once for four is both cheaper and less likely to be throttled
 * than asking four times. It also makes a partial result — three of four came
 * back — a single honest settlement rather than four races over one hold.
 *
 * THE CREDIT HOLD IS SETTLED EXACTLY ONCE. `CreditService::settle()` refuses a
 * second settlement of the same hold, so a retried job cannot charge twice;
 * and every failure path releases rather than settles, because a customer pays
 * for pictures and never for attempts.
 */
class GenerateImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retried, but not many times.
     *
     * A rate limit resolves in a minute; a prompt the provider refuses never
     * will, and each attempt is a real charge to the owner even when it fails.
     */
    public int $tries = 2;

    public array $backoff = [30];

    public int $timeout = 300;

    /**
     * Larger than the provider timeout and smaller than the credit hold's
     * thirty minutes, so a job can never still be running when its own hold
     * expires and is swept away underneath it.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    public function __construct(
        private readonly string $batchUuid,
        private readonly ?int $holdId = null,
    ) {}

    public function handle(
        ProviderRegistry $registry,
        FileStorage $storage,
        UsageRecorder $usage,
        CreditService $credits,
    ): void {
        /** @var \Illuminate\Database\Eloquent\Collection<int, ImageGeneration> $rows */
        $rows = ImageGeneration::where('batch_uuid', $this->batchUuid)
            ->orderBy('position')
            ->get();

        if ($rows->isEmpty()) {
            $this->releaseHold($credits);

            return;
        }

        // A retry after a partial success must not regenerate what already
        // arrived — those images are stored and paid for.
        $pending = $rows->filter(fn (ImageGeneration $row) => $row->isWorking());

        if ($pending->isEmpty()) {
            return;
        }

        $first = $pending->first();
        $model = $first->model;

        if (! $model) {
            $this->failAll($pending, __('The model that was going to make this is no longer available.'), $credits);

            return;
        }

        $pending->each->update([
            'status' => ImageGeneration::GENERATING,
            'started_at' => now(),
        ]);

        $adapter = $registry->for($model->provider);

        if (! $adapter instanceof SupportsImageGeneration) {
            // Unreachable through the router, which filters by capability —
            // so reaching it means the catalog claims something the adapter
            // cannot do, and that is worth saying plainly.
            $this->failAll($pending, __('The chosen provider cannot generate images.'), $credits);

            return;
        }

        $startedAt = hrtime(true);

        try {
            $images = $adapter->generateImage(new ImageRequest(
                modelIdentifier: (string) $model->model_identifier,
                prompt: (string) $first->prompt,
                count: $pending->count(),
                size: (string) $first->size,
                quality: (string) $first->quality,
                style: $first->style,
                negativePrompt: $first->negative_prompt,
            ));
        } catch (ProviderFailed $e) {
            // The provider's own message never crosses this boundary: several
            // APIs echo the failing request, and that request carried the key.
            $this->failAll($pending, $this->wording($e), $credits, $model, $usage, $startedAt, $e);

            throw $e;   // let the queue decide whether to retry
        } catch (Throwable $e) {
            $this->failAll($pending, __('The image could not be generated.'), $credits, $model, $usage, $startedAt);

            throw $e;
        }

        $stored = $this->store($pending, $images, $storage, $credits);

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        // Metered like every other call, so the owner sees on one screen what
        // pictures cost them next to what chat costs them.
        $usage->record(
            model: $model,
            usage: new UsageMetrics(images: $stored, requests: 1),
            user: $first->owner,
            latencyMs: $latencyMs,
            capability: Capability::IMAGE_GENERATION,
        );

        $this->settle($model, $stored, $credits, $usage);
    }

    /**
     * Anything still queued when the job gives up for good.
     *
     * `failed()` runs after the last attempt. Without it a provider outage
     * would leave rows saying "generating" for ever and the hold would only
     * disappear when the sweeper found it — the customer would see neither an
     * image nor an explanation.
     */
    public function failed(?Throwable $e): void
    {
        $rows = ImageGeneration::where('batch_uuid', $this->batchUuid)
            ->whereIn('status', [ImageGeneration::QUEUED, ImageGeneration::GENERATING])
            ->get();

        $rows->each->update([
            'status' => ImageGeneration::FAILED,
            'failure_reason' => __('The image could not be generated. No credits were charged.'),
            'completed_at' => now(),
        ]);

        $this->releaseHold(app(CreditService::class));
    }

    // -- internals -------------------------------------------------------------

    /**
     * Put each returned image on the private disk and attach it to its row.
     *
     * FEWER IMAGES THAN ASKED FOR IS AN ORDINARY OUTCOME, not an error: a
     * provider filters some prompts per-image. The rows that got one complete;
     * the rest fail with a sentence, and only what arrived is charged for.
     *
     * @param  Collection<int, ImageGeneration>  $rows
     * @param  array<int, GeneratedImage>  $images
     * @return int how many were actually stored
     */
    private function store($rows, array $images, FileStorage $storage, CreditService $credits): int
    {
        $stored = 0;

        foreach ($rows->values() as $index => $row) {
            $image = $images[$index] ?? null;

            if (! $image) {
                $row->update([
                    'status' => ImageGeneration::FAILED,
                    'failure_reason' => __('The provider returned fewer images than were asked for. You were not charged for this one.'),
                    'completed_at' => now(),
                ]);

                continue;
            }

            try {
                $bytes = $image->hasBytes() ? $image->bytes : $this->download((string) $image->url);

                $file = $storage->storeGenerated(
                    bytes: (string) $bytes,
                    owner: $row->owner,
                    purpose: 'image_generation',
                    displayName: mb_substr((string) $row->prompt, 0, 60),
                );

                $row->update([
                    'file_id' => $file->getKey(),
                    'revised_prompt' => $image->revisedPrompt,
                    'status' => ImageGeneration::COMPLETED,
                    'completed_at' => now(),
                ]);

                $stored++;
            } catch (Throwable $e) {
                // A provider that returned something that is not an image, or
                // a URL that had already expired. Reported as a sentence, and
                // not charged for.
                Log::warning('Generated image could not be stored', [
                    'generation' => $row->uuid,
                    'reason' => Redactor::scrub($e->getMessage()),
                ]);

                $row->update([
                    'status' => ImageGeneration::FAILED,
                    'failure_reason' => __('The image arrived but could not be saved. You were not charged for it.'),
                    'completed_at' => now(),
                ]);
            }
        }

        return $stored;
    }

    /**
     * Fetch an image a provider left on its own CDN.
     *
     * BOUNDED, because this writes to disk. The cap is generous for a picture
     * and small enough that a provider returning a video, a redirect loop or
     * an error page cannot fill the server. What comes back is still type-
     * checked from its bytes by `storeGenerated()` — the Content-Type header
     * is a claim and is not trusted.
     */
    private function download(string $url): string
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            // Plain HTTP would put the customer's picture on the wire in the
            // clear, and is not something any current provider needs.
            throw new \RuntimeException('Refusing to fetch a generated image over an insecure connection.');
        }

        $response = Http::timeout(60)
            ->withOptions(['stream' => false])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('The provider\'s image link did not work.');
        }

        $bytes = $response->body();

        if (strlen($bytes) > 25 * 1024 * 1024) {
            throw new \RuntimeException('The provider returned a file far larger than an image.');
        }

        return $bytes;
    }

    /**
     * Charge for what arrived.
     *
     * Zero images means the hold is RELEASED, not settled at zero: the two are
     * the same arithmetic and different records, and "we charged you nothing"
     * is the one the customer's ledger should show.
     */
    private function settle(AiModel $model, int $stored, CreditService $credits, UsageRecorder $usage): void
    {
        $hold = $this->hold();

        if (! $hold) {
            return;
        }

        if ($stored < 1) {
            $credits->release($hold);

            return;
        }

        $cost = (float) $usage->cost($model, new UsageMetrics(images: $stored, requests: 1))['credit_cost'];

        $entry = $credits->settle($hold, $cost, __('Image generation'));

        if ($entry) {
            // Stored, not recomputed: editing a price must never rewrite what
            // somebody already paid.
            ImageGeneration::where('batch_uuid', $this->batchUuid)
                ->where('status', ImageGeneration::COMPLETED)
                ->update(['credit_cost' => round($cost / max($stored, 1), 6)]);
        }
    }

    /**
     * @param  Collection<int, ImageGeneration>  $rows
     */
    private function failAll(
        $rows,
        string $reason,
        CreditService $credits,
        ?AiModel $model = null,
        ?UsageRecorder $usage = null,
        ?float $startedAt = null,
        ?ProviderFailed $failure = null,
    ): void {
        $rows->each->update([
            'status' => ImageGeneration::FAILED,
            // Scrubbed even though every string reaching here is ours: this
            // column is read by administrators and exported in reports, and
            // the cost of the belt is one function call.
            'failure_reason' => mb_substr(Redactor::scrub($reason), 0, 500),
            'completed_at' => now(),
        ]);

        if ($model && $usage) {
            // A failed call still cost the owner a request at the provider.
            // Recording it is how "why is my bill higher than my revenue?"
            // has an answer.
            $usage->record(
                model: $model,
                usage: new UsageMetrics(images: 0, requests: 1),
                user: $rows->first()?->owner,
                latencyMs: $startedAt ? (int) round((hrtime(true) - $startedAt) / 1_000_000) : null,
                httpStatus: $failure?->httpStatus,
                errorClass: $failure?->errorClass,
                capability: Capability::IMAGE_GENERATION,
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
            ErrorClass::RATE_LIMIT => __('The image service is busy. Please try again in a minute.'),
            ErrorClass::CONTENT_FILTERED => __('The provider would not generate that. Try describing it differently.'),
            ErrorClass::QUOTA_EXCEEDED => __('The image service has reached its spending limit. An administrator needs to look at it.'),
            ErrorClass::INVALID_REQUEST => __('The provider could not use that description. Try rewording it.'),
            default => __('The image could not be generated. No credits were charged.'),
        };
    }
}
