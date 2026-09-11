<?php

namespace App\Domains\AI\Routing;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelPrice;

/**
 * STAGE 3 (blueprint §14): order the survivors by what this mode cares about.
 *
 * Every score is 0..1 where higher is better, so modes can be expressed as
 * weightings rather than as bespoke sorting code. That matters because the
 * factors are recorded alongside the decision: an owner reading a routing log
 * sees "health 1.00, speed 0.72, cost 0.40" rather than an opaque number.
 *
 * Ties break on the administrator's own provider priority, so the owner's
 * preference is what decides when nothing else does.
 */
class CandidateScorer
{
    /** Above this, a model is "slow" for scoring purposes. */
    private const SLOW_MS = 8000;

    public function __construct(private readonly ProviderHealth $health) {}

    /**
     * @param  array<int, Candidate>  $candidates
     * @return array<int, Candidate> eligible only, best first
     */
    public function rank(array $candidates, string $mode): array
    {
        $eligible = array_values(array_filter($candidates, fn (Candidate $c) => $c->eligible));

        if ($eligible === []) {
            return [];
        }

        $costs = $this->costRange($eligible);

        foreach ($eligible as $candidate) {
            $factors = $this->factors($candidate, $costs);
            $candidate->withScore($this->score($mode, $factors), $factors);
        }

        usort($eligible, function (Candidate $a, Candidate $b) {
            if (abs($a->score - $b->score) > 0.0001) {
                return $b->score <=> $a->score;
            }

            // The owner's own priority order is the tie-break, not chance.
            return ($a->model->provider->priority ?? 100) <=> ($b->model->provider->priority ?? 100);
        });

        return $eligible;
    }

    /**
     * @param  array{min: float, max: float}  $costs
     * @return array<string, float>
     */
    private function factors(Candidate $candidate, array $costs): array
    {
        $model = $candidate->model;
        $providerId = (int) $model->provider_id;

        $latency = $this->health->p50Latency($providerId);

        return [
            // How often this provider has actually answered, from real traffic.
            'health' => $this->health->successRate($providerId),
            // Faster is better, flattening out past SLOW_MS. An unmeasured
            // provider scores neutrally rather than badly — no evidence is not
            // bad evidence.
            'speed' => $latency === null ? 0.5 : max(0.0, 1 - min(1.0, $latency / self::SLOW_MS)),
            'cost' => $this->costScore($model, $costs),
            'quality' => min(1.0, max(0.0, ($model->quality_rank ?? 50) / 100)),
            // Priority is "lower is better", so it is inverted here.
            'preference' => 1 - min(1.0, ($model->provider->priority ?? 100) / 1000),
        ];
    }

    /** @param array<string, float> $f */
    private function score(string $mode, array $f): float
    {
        return match ($mode) {
            // Health dominates: a cheap model from a provider that is failing
            // is not a bargain.
            RoutingMode::AUTO => 0.40 * $f['health'] + 0.25 * $f['speed'] + 0.20 * $f['cost'] + 0.15 * $f['preference'],

            RoutingMode::BEST_QUALITY => 0.70 * $f['quality'] + 0.30 * $f['health'],

            RoutingMode::FASTEST => 0.75 * $f['speed'] + 0.25 * $f['health'],

            RoutingMode::LOWEST_COST => 0.80 * $f['cost'] + 0.20 * $f['health'],

            // Every survivor is already free-tier by this point, so the
            // question is which free provider is working best.
            RoutingMode::FREE_ONLY => 0.60 * $f['health'] + 0.40 * $f['speed'],

            // Strictly the owner's order, ignoring cost and speed entirely.
            RoutingMode::ADMIN_PREFERRED => $f['preference'],

            // Pinned modes have already been filtered to one provider or one
            // model, so ordering among what is left is the ordinary balance.
            default => 0.50 * $f['health'] + 0.30 * $f['preference'] + 0.20 * $f['speed'],
        };
    }

    /**
     * Cost, normalised across the candidates actually available.
     *
     * Relative rather than absolute: "cheapest of what can do this job" is the
     * meaningful comparison, and an absolute scale would need a ceiling that
     * every new model release invalidated.
     *
     * @param  array{min: float, max: float}  $range
     */
    private function costScore(AiModel $model, array $range): float
    {
        $cost = $this->costOf($model);

        // An unpriced model scores neutrally. Treating "no price recorded" as
        // free would make it win Lowest Cost every time, which is how an owner
        // ends up unknowingly serving their most expensive model for nothing.
        if ($cost === null) {
            return 0.5;
        }

        if ($range['max'] <= $range['min']) {
            return 1.0;
        }

        return 1 - (($cost - $range['min']) / ($range['max'] - $range['min']));
    }

    /** @param array<int, Candidate> $candidates @return array{min: float, max: float} */
    private function costRange(array $candidates): array
    {
        $costs = [];

        foreach ($candidates as $candidate) {
            $cost = $this->costOf($candidate->model);

            if ($cost !== null) {
                $costs[] = $cost;
            }
        }

        return $costs === []
            ? ['min' => 0.0, 'max' => 0.0]
            : ['min' => min($costs), 'max' => max($costs)];
    }

    /**
     * A comparable price for one model.
     *
     * Input and output are summed with output weighted more heavily, because a
     * reply is usually shorter than its context but priced several times
     * higher — comparing input price alone ranks models wrongly.
     *
     * WHAT THE CUSTOMER PAYS is the basis where it is set, because "lowest
     * cost" is a promise made to whoever chose the mode. Where no credit price
     * has been entered yet the provider's own cost stands in, so an owner
     * mid-way through pricing their catalog still gets sensible ordering
     * instead of every unpriced model scoring identically.
     */
    private function costOf(AiModel $model): ?float
    {
        $input = $model->priceAt('per_1k_input');
        $output = $model->priceAt('per_1k_output');

        if (! $input && ! $output) {
            return null;
        }

        $of = fn (?AiModelPrice $price) => $price === null
            ? 0.0
            : ((float) $price->credit_cost ?: (float) $price->provider_cost);

        return $of($input) + 3 * $of($output);
    }
}
