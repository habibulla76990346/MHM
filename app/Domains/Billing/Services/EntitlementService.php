<?php

namespace App\Domains\Billing\Services;

use App\Domains\AI\Models\AiModel;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanFeature;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Billing\Support\EntitlementDecision;
use App\Domains\Chat\Models\Message;
use App\Models\User;

/**
 * What a customer's plan lets them do (§19).
 *
 * ONE PLACE ASKS THE QUESTION. Scattering `if ($user->plan->name === 'PRO')`
 * through the application is how a limit ends up enforced in three places and
 * forgotten in a fourth — and how renaming a plan breaks the product.
 *
 * NO PLAN MEANS NO LIMIT. An owner who has not published a plan yet has a
 * working platform, not one where every customer is blocked by a subscription
 * system they have not configured. Restriction is always something an
 * administrator turned on.
 */
class EntitlementService
{
    /**
     * Is the platform metering at all?
     *
     * Billing switches itself on when the owner publishes their first plan.
     * Before that there is nothing to enforce and nothing to charge against,
     * so the product runs unmetered rather than refusing every customer over a
     * subscription system nobody has configured yet — which is what would
     * happen if metering keyed off model prices alone.
     */
    public function meteringIsActive(): bool
    {
        return Plan::where('status', Plan::STATUS_ACTIVE)->exists();
    }

    public function subscription(User $user): ?Subscription
    {
        return Subscription::with('plan.features')
            ->where('user_id', $user->getKey())
            ->live()
            ->latest('id')
            ->first();
    }

    public function plan(User $user): ?Plan
    {
        $subscription = $this->subscription($user);

        return $subscription?->isLive() ? $subscription->plan : null;
    }

    /**
     * A named limit, or null when there is none.
     *
     * Null and zero mean different things and are kept apart: null is "no
     * ceiling", zero is "none allowed". Collapsing them turns a plan meant to
     * forbid something into one that permits everything.
     */
    public function limit(User $user, string $key): ?float
    {
        return $this->plan($user)?->feature($key)?->numericLimit();
    }

    /** A yes/no feature, such as whether the customer may pin a model. */
    public function allows(User $user, string $key, bool $default = true): bool
    {
        $feature = $this->plan($user)?->feature($key);

        if (! $feature) {
            return $default;
        }

        return $feature->isUnlimited() || $feature->isEnabled();
    }

    /**
     * May this customer send another message?
     *
     * Counts real messages in the window rather than a stored tally: a counter
     * can drift, and this question is asked once per turn, not per token.
     */
    public function checkMessageAllowance(User $user): EntitlementDecision
    {
        foreach ([
            ['messages_per_day', now()->startOfDay(), __('today')],
            ['messages_per_month', now()->startOfMonth(), __('this month')],
        ] as [$key, $since, $window]) {
            $feature = $this->plan($user)?->feature($key);

            if (! $feature || $feature->isUnlimited() || $feature->numericLimit() === null) {
                continue;
            }

            $limit = (float) $feature->numericLimit();
            $used = (float) $this->messagesSince($user, $since);

            if ($used < $limit) {
                continue;
            }

            $reason = __('You have used all :limit messages included :window on your plan.', [
                'limit' => (int) $limit,
                'window' => $window,
            ]);

            if ($feature->limit_type === PlanFeature::SOFT) {
                return EntitlementDecision::warn($reason, $limit, $used);
            }

            return EntitlementDecision::deny($reason, $limit, $used);
        }

        return EntitlementDecision::allow();
    }

    public function maxAttachments(User $user): ?int
    {
        $limit = $this->limit($user, 'max_attachments');

        return $limit === null ? null : (int) $limit;
    }

    // -- what the router may choose from -------------------------------------

    /**
     * Models this customer's plan forbids.
     *
     * Handed to the router as an exclusion list rather than checked afterwards,
     * so a forbidden model is never chosen and then rejected — which would
     * show the customer an error for a decision they did not make.
     *
     * @return array<int, int>
     */
    public function deniedModelIds(User $user): array
    {
        $plan = $this->plan($user);

        if (! $plan) {
            return [];
        }

        $plan->loadMissing(['modelAccess', 'providerAccess']);

        return $plan->deniedModelIds();
    }

    public function canUseModel(User $user, AiModel $model): bool
    {
        $plan = $this->plan($user);

        if (! $plan) {
            return true;
        }

        $plan->loadMissing(['modelAccess', 'providerAccess']);

        return $plan->allowsModel($model);
    }

    /** Whether the customer may pin a specific model rather than use Auto. */
    public function canChooseModel(User $user): bool
    {
        return $this->allows($user, 'can_pin_model');
    }

    /** How much conversation history the plan keeps. Null means forever. */
    public function historyDays(User $user): ?int
    {
        $days = $this->limit($user, 'conversation_history_days');

        return $days === null ? null : (int) $days;
    }

    private function messagesSince(User $user, \DateTimeInterface $since): int
    {
        return Message::where('role', Message::ROLE_USER)
            ->where('created_at', '>=', $since)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->getKey()))
            ->count();
    }
}
