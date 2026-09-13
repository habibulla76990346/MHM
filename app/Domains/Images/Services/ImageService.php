<?php

namespace App\Domains\Images\Services;

use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Routing\AiRouter;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Billing\Services\EntitlementService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Images\Jobs\GenerateImageJob;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Images\Support\ImageRefused;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Asking for a picture (§16).
 *
 * THE ORDER MATTERS AND IS THE WHOLE DESIGN. Everything that can refuse the
 * request happens BEFORE anything is queued — the feature switch, the plan's
 * allowance, whether a model exists at all, and the credit hold. A request
 * that reaches the queue is one the customer can afford and is allowed to
 * make, which is what stops somebody queueing a hundred jobs against a balance
 * that covers one.
 *
 * NOTHING HERE NAMES A PROVIDER OR A MODEL. It asks `AiRouter` for something
 * that can do `Capability::IMAGE_GENERATION`, the same way chat asks for
 * something that can talk and knowledge bases ask for something that can
 * embed. An owner who switches provider keeps their gallery.
 *
 * ONE HOLD PER REQUEST, SETTLED TO WHAT ARRIVED. A request for four images
 * that returns three charges for three and releases the rest: a customer pays
 * for pictures, never for attempts. The hold is taken here and settled in the
 * job, and `CreditService::settle()` refuses a second settlement of the same
 * hold — so a retried job cannot charge twice.
 */
class ImageService
{
    /** Most images one request may ask for. */
    public const MAX_PER_REQUEST = 4;

    public function __construct(
        private readonly AiRouter $router,
        private readonly UsageRecorder $usage,
        private readonly CreditService $credits,
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** Is the feature on at all? */
    public function isEnabled(): bool
    {
        return (bool) settings('images.enabled');
    }

    /**
     * Is there anything that could serve a picture?
     *
     * Asked without recording a routing decision: this is the question the
     * screen asks on every render to decide whether to show the form, and a
     * routing log per page view would bury the real ones.
     */
    public function availableModel(): ?AiModel
    {
        return $this->router
            ->route([Capability::IMAGE_GENERATION], RoutingMode::AUTO, record: false)
            ->model;
    }

    /**
     * Queue a generation.
     *
     * @return Collection<int, ImageGeneration> one row per image asked for
     *
     * @throws ImageRefused
     */
    public function request(
        User $user,
        string $prompt,
        int $count = 1,
        string $size = '1024x1024',
        string $quality = 'standard',
        ?string $negativePrompt = null,
        ?ImageGeneration $regeneratedFrom = null,
    ): Collection {
        $prompt = trim($prompt);
        $count = max(1, min($count, self::MAX_PER_REQUEST));

        if (! $this->isEnabled()) {
            throw ImageRefused::disabled();
        }

        if ($prompt === '') {
            throw ImageRefused::empty();
        }

        if (! array_key_exists($size, ImageGeneration::SIZES)) {
            throw ImageRefused::badSize();
        }

        if (! array_key_exists($quality, ImageGeneration::QUALITIES)) {
            $quality = 'standard';
        }

        // Everybody is on a plan; assigned lazily so switching billing on
        // never needed a migration over existing accounts.
        $this->subscriptions->ensureSubscription($user);

        $this->assertWithinAllowance($user, $count);

        $model = $this->availableModel();

        if (! $model) {
            throw ImageRefused::noModel();
        }

        $batch = (string) Str::uuid();
        $hold = $this->authoriseSpend($user, $model, $count, $batch);

        $rows = collect(range(0, $count - 1))->map(fn (int $position) => ImageGeneration::create([
            'user_id' => $user->getKey(),
            'model_id' => $model->getKey(),
            'batch_uuid' => $batch,
            'position' => $position,
            'prompt' => mb_substr($prompt, 0, 4000),
            'negative_prompt' => $negativePrompt ? mb_substr($negativePrompt, 0, 1000) : null,
            'size' => $size,
            'quality' => $quality,
            'status' => ImageGeneration::QUEUED,
            'regenerated_from_id' => $regeneratedFrom?->getKey(),
            'expires_at' => $this->expiryDate(),
        ]));

        GenerateImageJob::dispatch($batch, $hold?->getKey());

        return $rows;
    }

    /**
     * Ask again from the same prompt (§16).
     *
     * A NEW ROW POINTING AT THE OLD ONE, never an edit — both survive, exactly
     * as a regenerated chat reply does, so the customer can compare them and
     * the owner can see that two images were paid for.
     */
    public function regenerate(User $user, ImageGeneration $source, ?string $prompt = null): Collection
    {
        if ($source->user_id !== $user->getKey()) {
            throw ImageRefused::notYours();
        }

        return $this->request(
            user: $user,
            prompt: $prompt ?? (string) $source->prompt,
            count: 1,
            size: (string) $source->size,
            quality: (string) $source->quality,
            negativePrompt: $source->negative_prompt,
            regeneratedFrom: $source,
        );
    }

    /**
     * The prompts this customer has used (§16 "prompt history").
     *
     * A QUERY, NOT A TABLE. The prompts a customer has used ARE their
     * generations; a second table holding the same strings would be a second
     * thing to keep in step and a second thing to forget when they ask to be
     * deleted.
     *
     * @return array<int, string>
     */
    public function promptHistory(User $user, int $limit = 12): array
    {
        return ImageGeneration::ownedBy($user)
            ->select('prompt')
            ->groupBy('prompt')
            ->orderByRaw('MAX(created_at) DESC')
            ->limit($limit)
            ->pluck('prompt')
            ->all();
    }

    // -- refusals, before anything is queued ----------------------------------

    /**
     * @throws ImageRefused
     */
    private function assertWithinAllowance(User $user, int $count): void
    {
        // The platform-wide ceiling. Applies to everybody, including plans
        // with no image limit of their own — it is there to stop one account
        // spending the owner's provider budget in an afternoon.
        $perDay = (int) settings('images.max_per_day');

        if ($perDay > 0) {
            $today = ImageGeneration::ownedBy($user)
                ->where('created_at', '>=', now()->startOfDay())
                ->whereIn('status', [ImageGeneration::QUEUED, ImageGeneration::GENERATING, ImageGeneration::COMPLETED])
                ->count();

            if ($today + $count > $perDay) {
                throw ImageRefused::dailyLimit($perDay);
            }
        }

        // The plan's own allowance, counted over the subscription period so it
        // refreshes when the period does — the same way message allowances and
        // credit grants work.
        $limit = $this->entitlements->limit($user, 'image_credits_per_period');

        if ($limit === null) {
            return;
        }

        $since = $this->entitlements->subscription($user)?->current_period_start ?? now()->startOfMonth();

        $used = ImageGeneration::ownedBy($user)
            ->where('created_at', '>=', $since)
            ->whereIn('status', [ImageGeneration::QUEUED, ImageGeneration::GENERATING, ImageGeneration::COMPLETED])
            ->count();

        if ($used + $count > (int) $limit) {
            throw ImageRefused::planLimit((int) $limit);
        }
    }

    /**
     * Reserve what this will cost.
     *
     * Priced from the model's own `per_image` price at today's rate, times the
     * number asked for. An unpriced model holds zero and is served — metering
     * begins when the owner enters prices, not when this code shipped.
     *
     * @throws ImageRefused
     */
    private function authoriseSpend(User $user, AiModel $model, int $count, string $batch): ?CreditHold
    {
        if (! $this->entitlements->meteringIsActive()) {
            return null;
        }

        $estimate = (float) $this->usage->cost($model, new UsageMetrics(images: $count, requests: 1))['credit_cost'];

        $hold = $this->credits->hold(
            user: $user,
            amount: $estimate,
            referenceType: 'image_generation',
            referenceId: $batch,
        );

        if (! $hold) {
            throw ImageRefused::outOfCredits();
        }

        return $hold;
    }

    private function expiryDate(): ?\DateTimeInterface
    {
        $days = (int) settings('images.retention_days');

        return $days > 0 ? now()->addDays($days) : null;
    }
}
