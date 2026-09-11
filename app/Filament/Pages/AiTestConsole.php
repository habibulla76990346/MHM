<?php

namespace App\Filament\Pages;

use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\DTO\ChatMessage;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\ProviderHealthLog;
use App\Domains\AI\Routing\CircuitBreaker;
use App\Domains\AI\Services\ModelSyncService;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * ADMIN → AI → Test console (blueprint §25).
 *
 * A first-class screen, not a debugging afterthought. For an owner who is not
 * a developer this is the difference between "the AI is broken" and "the
 * Gemini key expired on Tuesday".
 *
 * THREE CONSTRAINTS SHAPE IT:
 *
 *  1. A test must never become expensive. The request is capped at a handful
 *     of output tokens, and the prompt is fixed rather than free text.
 *  2. The full secret is never shown — the console reports which key was used
 *     by its last four characters, which are stored separately and never
 *     decrypted to display.
 *  3. Failures are reported as a CLASS and a remedy, never as the provider's
 *     own words, which on several APIs echo the request back.
 */
class AiTestConsole extends Page
{
    protected static ?string $navigationLabel = 'Test console';

    protected static ?string $title = 'AI test console';

    protected static ?string $slug = 'ai-test-console';

    protected static string|\UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.ai-test-console';

    /** A test is bounded, so it cannot cost more than a fraction of a penny. */
    public const MAX_OUTPUT_TOKENS = 16;

    public const TEST_PROMPT = 'Reply with the single word: ready';

    public ?int $providerId = null;

    public ?int $modelId = null;

    /** @var array<string, mixed>|null the last result, as plain scalars */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('providers.test') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->providerId = AiProvider::usable()->value('id');
        $this->syncModel();
    }

    public function updatedProviderId(): void
    {
        $this->modelId = null;
        $this->result = null;
        $this->syncModel();
    }

    /** @return array<int, AiProvider> */
    public function providers(): Collection
    {
        return AiProvider::orderBy('name')->get();
    }

    /** @return Collection<int, AiModel> */
    public function models(): Collection
    {
        if (! $this->providerId) {
            return collect();
        }

        return AiModel::where('provider_id', $this->providerId)
            ->orderBy('display_name')
            ->get();
    }

    public function provider(): ?AiProvider
    {
        return $this->providerId ? AiProvider::find($this->providerId) : null;
    }

    /**
     * Which key would be used, identified the only way §25 permits.
     */
    public function credentialHint(): ?string
    {
        return $this->provider()?->activeCredential()?->masked();
    }

    // -- actions -------------------------------------------------------------

    public function testCredential(): void
    {
        $provider = $this->provider();

        if (! $provider) {
            return;
        }

        $adapter = app(ProviderRegistry::class)->for($provider);

        if (! $adapter) {
            $this->fail('No adapter is installed for "'.$provider->adapter_type.'".');

            return;
        }

        $outcome = $adapter->testConnection();

        $this->record($provider, $outcome->success, $outcome->latencyMs, $outcome->errorClass, $outcome->httpStatus);

        $this->result = [
            'kind' => 'credential',
            'success' => $outcome->success,
            'latency_ms' => $outcome->latencyMs,
            'http_status' => $outcome->httpStatus,
            'error_class' => $outcome->errorClass,
            'error_label' => $outcome->success ? null : ErrorClass::label((string) $outcome->errorClass),
            'detail' => $outcome->detail,
            'models_visible' => $outcome->context['models_visible'] ?? null,
        ];

        app(ActivityLogger::class)->log('provider.credential_tested', $provider, null, [
            'success' => $outcome->success,
            'latency_ms' => $outcome->latencyMs,
            'error_class' => $outcome->errorClass,
        ]);
    }

    public function sendTestRequest(): void
    {
        $provider = $this->provider();
        $model = $this->modelId ? AiModel::find($this->modelId) : null;

        if (! $provider || ! $model) {
            $this->fail('Choose a provider and a model first.');

            return;
        }

        $adapter = app(ProviderRegistry::class)->for($provider);

        if (! $adapter instanceof SupportsChat) {
            $this->fail('This provider does not support chat, so there is nothing to send.');

            return;
        }

        $request = new ChatRequest(
            modelIdentifier: $model->model_identifier,
            messages: [ChatMessage::user(self::TEST_PROMPT)],
            // Bounded, so a test can never become expensive.
            maxTokens: self::MAX_OUTPUT_TOKENS,
        );

        try {
            $response = $adapter->chat($request);
        } catch (ProviderFailed $e) {
            $this->record($provider, false, $e->latencyMs, $e->errorClass, $e->httpStatus, $model->getKey());

            $this->result = [
                'kind' => 'request',
                'success' => false,
                'latency_ms' => $e->latencyMs,
                'http_status' => $e->httpStatus,
                'error_class' => $e->errorClass,
                'error_label' => ErrorClass::label($e->errorClass),
                'detail' => $e->action(),
                'retryable' => $e->isRetryable(),
            ];

            return;
        }

        $this->record($provider, true, $response->latencyMs, null, 200, $model->getKey());

        $usage = $response->usage;

        $this->result = [
            'kind' => 'request',
            'success' => true,
            'latency_ms' => $response->latencyMs,
            'http_status' => 200,
            'content' => $response->content,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'finish_reason' => $response->finishReason,
            // Costed from the price that applied AT THIS MOMENT, the same way
            // real usage will be (§13).
            'estimated_cost' => $this->estimateCost($model, $usage->inputTokens, $usage->outputTokens),
        ];

        app(ActivityLogger::class)->log('provider.test_request', $provider, null, [
            'model' => $model->model_identifier,
            'latency_ms' => $response->latencyMs,
            'tokens' => $usage->totalTokens(),
        ]);
    }

    public function refreshModels(): void
    {
        $provider = $this->provider();

        if (! $provider || ! auth()->user()?->can('models.sync')) {
            $this->fail('Not permitted.');

            return;
        }

        $sync = app(ModelSyncService::class);

        if (! $sync->canSync($provider)) {
            Notification::make()
                ->title('This provider does not publish a model list')
                ->body('Its models are added by hand in Admin → AI → Models. That is expected for some providers.')
                ->warning()->send();

            return;
        }

        $log = $sync->sync($provider, auth()->id());

        Notification::make()
            ->title($log->status === 'success' ? 'Catalog refreshed' : 'Refresh failed')
            ->body($log->status === 'success'
                ? $log->summary().'. New models arrive switched off.'
                : $log->error_message)
            ->status($log->status === 'success' ? 'success' : 'danger')
            ->send();
    }

    public function resetCircuit(): void
    {
        $provider = $this->provider();

        if (! $provider || ! auth()->user()?->can('providers.manage')) {
            $this->fail('Not permitted.');

            return;
        }

        // Through CircuitBreaker, never straight at the mirror row: the live
        // state lives in the cache, and clearing only the database copy would
        // report success while the provider stayed out of rotation.
        $before = app(CircuitBreaker::class)->state($provider);

        app(CircuitBreaker::class)->reset($provider);

        app(ActivityLogger::class)->log('provider.circuit_reset', $provider, $before, ['state' => 'closed']);

        Notification::make()->title('Circuit breaker reset')->success()->send();
    }

    // -- helpers -------------------------------------------------------------

    private function syncModel(): void
    {
        $this->modelId ??= $this->models()->firstWhere('is_enabled', true)?->getKey()
            ?? $this->models()->first()?->getKey();
    }

    private function estimateCost(AiModel $model, int $inputTokens, int $outputTokens): ?string
    {
        $input = $model->priceAt('per_1k_input');
        $output = $model->priceAt('per_1k_output');

        if (! $input && ! $output) {
            return null;
        }

        $cost = ($inputTokens / 1000) * (float) ($input->provider_cost ?? 0)
            + ($outputTokens / 1000) * (float) ($output->provider_cost ?? 0);

        return number_format($cost, 6).' '.($input->currency ?? $output->currency ?? 'USD');
    }

    private function record(
        AiProvider $provider,
        bool $success,
        int $latencyMs,
        ?string $errorClass,
        ?int $httpStatus,
        ?int $modelId = null,
    ): void {
        ProviderHealthLog::create([
            'provider_id' => $provider->getKey(),
            'model_id' => $modelId,
            'checked_at' => now(),
            'success' => $success,
            'latency_ms' => $latencyMs,
            // A class and a status. Never the provider's message.
            'error_class' => $errorClass,
            'http_status' => $httpStatus,
        ]);

        if ($success) {
            $provider->activeCredential()?->forceFill([
                'last_verified_at' => now(),
                'last_verify_result' => 'ok',
            ])->save();
        }
    }

    private function fail(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }
}
