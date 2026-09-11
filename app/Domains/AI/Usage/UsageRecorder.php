<?php

namespace App\Domains\AI\Usage;

use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Models\CredentialUsageCounter;
use App\Domains\AI\Models\RoutingLog;
use App\Models\User;

/**
 * What each call cost and what it earned (blueprint §13, §21).
 *
 * COSTED AT THE PRICE THAT APPLIED WHEN IT HAPPENED, not today's. Editing a
 * price must never rewrite the profitability of history, so the price is
 * looked up by the moment of use and the resulting figures are stored, not
 * recomputed on read.
 *
 * The provider cost is kept in the PROVIDER'S currency with that currency
 * recorded beside it. Conversion happens in reporting, at the rate that
 * applied on the day — see ExchangeRate.
 */
class UsageRecorder
{
    public function record(
        AiModel $model,
        UsageMetrics $usage,
        ?User $user = null,
        ?RoutingLog $routingLog = null,
        ?int $latencyMs = null,
        ?int $httpStatus = null,
        ?string $errorClass = null,
        string $capability = 'chat',
        ?\DateTimeInterface $occurredAt = null,
    ): ?ApiUsageLog {
        $occurredAt ??= now();
        $credential = $model->provider?->activeCredential();

        $costs = $this->cost($model, $usage, $occurredAt);

        try {
            $log = ApiUsageLog::create([
                'user_id' => $user?->getKey(),
                'provider_id' => $model->provider_id,
                'model_id' => $model->getKey(),
                // WHICH key served it, never the key itself.
                'credential_id' => $credential?->getKey(),
                'routing_log_id' => $routingLog?->getKey(),
                'capability' => $capability,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'total_tokens' => $usage->totalTokens(),
                'latency_ms' => $latencyMs,
                'http_status' => $httpStatus,
                'error_class' => $errorClass,
                'provider_cost' => $costs['provider_cost'],
                'provider_currency' => $costs['currency'],
                'credit_cost' => $costs['credit_cost'],
                'occurred_at' => $occurredAt,
            ]);
        } catch (\Throwable) {
            // Analytics must never cost a customer their answer.
            return null;
        }

        if ($credential) {
            // Rule 7: per-key usage so a provider's limits can be RESPECTED.
            CredentialUsageCounter::record($credential->getKey(), $usage->totalTokens());
        }

        if ($model->provider) {
            app(BudgetGuard::class)->spend($model->provider, (float) $costs['provider_cost'], $costs['currency']);
        }

        return $log;
    }

    /**
     * @return array{provider_cost: float, credit_cost: float, currency: string}
     */
    public function cost(AiModel $model, UsageMetrics $usage, ?\DateTimeInterface $at = null): array
    {
        $at ??= now();

        $input = $model->priceAt('per_1k_input', $at);
        $output = $model->priceAt('per_1k_output', $at);
        $perRequest = $model->priceAt('per_request', $at);
        $perImage = $usage->images > 0 ? $model->priceAt('per_image', $at) : null;

        $providerCost =
            ($usage->inputTokens / 1000) * (float) ($input->provider_cost ?? 0)
            + ($usage->outputTokens / 1000) * (float) ($output->provider_cost ?? 0)
            + $usage->requests * (float) ($perRequest->provider_cost ?? 0)
            + $usage->images * (float) ($perImage->provider_cost ?? 0);

        $creditCost =
            ($usage->inputTokens / 1000) * (float) ($input->credit_cost ?? 0)
            + ($usage->outputTokens / 1000) * (float) ($output->credit_cost ?? 0)
            + $usage->requests * (float) ($perRequest->credit_cost ?? 0)
            + $usage->images * (float) ($perImage->credit_cost ?? 0);

        return [
            'provider_cost' => round($providerCost, 10),
            'credit_cost' => round($creditCost, 6),
            // An unpriced model records zero in the provider's default
            // currency rather than guessing — a zero that is visibly zero can
            // be found and fixed; an invented number cannot.
            'currency' => $input->currency ?? $output->currency ?? 'USD',
        ];
    }
}
