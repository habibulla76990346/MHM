<?php

namespace App\Domains\AI\Usage;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderBudget;
use App\Domains\AI\Models\AiProviderBudgetAlert;
use Illuminate\Support\Carbon;

/**
 * Spending caps per provider (§10, §21).
 *
 * The owner chooses what a breach does, and both answers are legitimate:
 * "warn" keeps the product working and tells them; "block" stops spending.
 * Guessing either way is wrong — one surprises them with a bill, the other
 * takes their product down without being asked.
 *
 * A period rolls over on its own. A daily cap that had to be reset by hand
 * every morning is a cap that stops working the first weekend.
 */
class BudgetGuard
{
    public function spend(AiProvider $provider, float $amount, string $currency): void
    {
        if ($amount <= 0) {
            return;
        }

        foreach ($provider->budgets as $budget) {
            $this->rollIfPeriodElapsed($budget);

            // Only a budget kept in the same currency is meaningful to add to.
            // Converting here would bake one day's exchange rate into a running
            // total that spans months.
            if (strtoupper($budget->currency) !== strtoupper($currency)) {
                continue;
            }

            $budget->increment('spent_amount', $amount);

            $this->alertIfCrossed($budget->fresh());
        }
    }

    /** Whether this provider must be skipped entirely right now. */
    public function blocks(AiProvider $provider): bool
    {
        foreach ($provider->budgets as $budget) {
            $this->rollIfPeriodElapsed($budget);

            if ($budget->fresh()->blocksUse()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start a new period once the old one has elapsed.
     *
     * The spend resets; the budget itself does not change. An owner who set
     * "10 dollars a day" means every day, not once.
     */
    public function rollIfPeriodElapsed(AiProviderBudget $budget): void
    {
        $startedAt = $budget->period_started_at;

        if ($startedAt === null) {
            $budget->forceFill(['period_started_at' => $this->periodStart($budget)])->save();

            return;
        }

        $currentStart = $this->periodStart($budget);

        if ($startedAt->lt($currentStart)) {
            $budget->forceFill([
                'spent_amount' => 0,
                'period_started_at' => $currentStart,
            ])->save();
        }
    }

    private function periodStart(AiProviderBudget $budget): Carbon
    {
        return $budget->period === 'daily' ? now()->startOfDay() : now()->startOfMonth();
    }

    /**
     * Alert once per threshold per period.
     *
     * Alerting on every request past 80% would bury the one message that
     * matters under hundreds that do not.
     */
    private function alertIfCrossed(AiProviderBudget $budget): void
    {
        $percent = $budget->percentUsed();

        foreach ([$budget->threshold_percent, 100] as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            $already = AiProviderBudgetAlert::where('budget_id', $budget->getKey())
                ->where('threshold_hit', $threshold)
                ->where('created_at', '>=', $budget->period_started_at ?? now()->startOfMonth())
                ->exists();

            if ($already) {
                continue;
            }

            AiProviderBudgetAlert::create([
                'budget_id' => $budget->getKey(),
                'threshold_hit' => $threshold,
                'notified_at' => now(),
                'action_taken' => $threshold >= 100 ? $budget->action_on_breach : 'warned',
            ]);
        }
    }
}
