<?php

namespace App\Filament\Pages;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\RoutingLog;
use App\Domains\AI\Routing\CircuitBreaker;
use App\Domains\AI\Routing\ProviderHealth;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Settings\Support\SettingDefinition;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * ADMIN → Routing and Health (blueprint §14, §24).
 *
 * ANSWERS ONE QUESTION: why did that request go where it went?
 *
 * The routing log records every model considered and the reason each was
 * ruled out, so this screen never has to reconstruct a decision — it shows
 * what was actually recorded at the time. An owner looking at a surprising
 * bill can see "the cheap provider's circuit was open, so everything went to
 * the expensive one", which is a different problem from the one they came
 * looking for.
 *
 * Health figures come from REAL customer traffic in the configured window, not
 * from synthetic pings. A ping tells you a status page is up; what matters is
 * the latency of the request a customer just made, from this server.
 */
class RoutingAndHealth extends Page
{
    protected static ?string $navigationLabel = 'Routing and Health';

    protected static ?string $title = 'Routing and Health';

    protected static ?string $slug = 'routing-and-health';

    protected static ?int $navigationSort = 41;

    protected string $view = 'filament.pages.routing-and-health';

    /** The decision being examined, by id. */
    public ?int $inspecting = null;

    /**
     * The editable routing defaults (§24), keyed WITHOUT dots.
     *
     * Livewire reads a dot in wire:model as a nested array path, so binding
     * `defaults.routing.max_retries` would write $defaults['routing']
     * ['max_retries'] and never touch the real key — silently, with no error.
     * That trap has bitten this project in two other places.
     *
     * @var array<string, mixed>
     */
    public array $defaults = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('providers.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach (array_keys($this->editableSettings()) as $key) {
            $this->defaults[self::wireKey($key)] = settings($key);
        }
    }

    public static function wireKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * The settings this screen owns.
     *
     * Read from the registry rather than restated here, so a definition's
     * label, help text and validation rules cannot drift away from what is
     * actually enforced when it is saved.
     *
     * @return array<string, SettingDefinition>
     */
    public function editableSettings(): array
    {
        return settings()->registry()->group('routing')
            + settings()->registry()->group('billing');
    }

    /** @return array<string, string> for the default-mode picker */
    public function modeOptions(): array
    {
        return array_map(fn (array $mode) => $mode['label'], RoutingMode::all());
    }

    public function canWrite(): bool
    {
        return auth()->user()?->can('providers.manage') ?? false;
    }

    /**
     * Authorise, validate, audit — all three, or it does not ship.
     *
     * Validation is the registry's own, applied inside SettingsService under a
     * flat field name; this method never restates a rule.
     */
    public function saveDefaults(): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $before = [];
        $after = [];

        foreach ($this->editableSettings() as $key => $definition) {
            $value = $this->defaults[self::wireKey($key)] ?? null;
            $current = settings($key);

            if ((string) $value === (string) $current) {
                continue;
            }

            try {
                settings()->set($key, $value, auth()->id());
            } catch (ValidationException $e) {
                Notification::make()
                    ->title(($definition->label ?: $key).' was not saved')
                    ->body(collect($e->errors())->flatten()->first())
                    ->danger()
                    ->send();

                continue;
            }

            $before[$key] = $current;
            $after[$key] = $value;
        }

        if ($after === []) {
            Notification::make()->title('Nothing to save')->send();

            return;
        }

        app(ActivityLogger::class)->log('routing.defaults_updated', null, $before, $after);

        Notification::make()->title('Saved')->body(count($after).' setting(s) updated.')->success()->send();
    }

    public function inspect(int $id): void
    {
        $this->inspecting = $this->inspecting === $id ? null : $id;
    }

    /**
     * Each provider's live state, health and circuit.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function providers(): array
    {
        $health = app(ProviderHealth::class);
        $breaker = app(CircuitBreaker::class);

        return AiProvider::orderBy('priority')->get()->map(function (AiProvider $provider) use ($health, $breaker) {
            $measured = $health->for($provider->getKey());

            return [
                'id' => $provider->getKey(),
                'name' => $provider->name,
                'priority' => $provider->priority,
                'state' => $provider->stateLabel(),
                // The LIVE breaker, not the mirror row, which is written
                // best-effort and can lag.
                'circuit' => $breaker->describe($provider),
                'open' => $breaker->isOpen($provider),
                'samples' => $measured['samples'],
                'success_rate' => round($measured['success_rate'] * 100, 1),
                'latency' => $measured['p50_latency_ms'],
            ];
        })->all();
    }

    /**
     * Recent decisions.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function decisions(): array
    {
        return RoutingLog::with(['selectedModel', 'selectedProvider'])
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (RoutingLog $log) => [
                'id' => $log->getKey(),
                'at' => $log->decided_at?->diffForHumans(),
                'mode' => RoutingMode::label((string) $log->routing_mode),
                'needed' => implode(', ', (array) $log->capability_required),
                'chosen' => $log->selectedModel?->display_name ?? 'Nothing available',
                'provider' => $log->selectedProvider?->name,
                'depth' => (int) $log->fallback_depth,
                'reason' => $log->decision_reason,
                'considered' => count((array) $log->candidates),
                'rejected' => $log->rejectedCandidates(),
            ])
            ->all();
    }

    /** How often an answer needed a second provider, over the last day. */
    #[Computed]
    public function fallbackRate(): float
    {
        $recent = RoutingLog::where('decided_at', '>=', now()->subDay());
        $total = (clone $recent)->count();

        if ($total === 0) {
            return 0.0;
        }

        return round((clone $recent)->where('fallback_depth', '>', 0)->count() / $total * 100, 1);
    }

    public function healthWindowHours(): int
    {
        return max(1, (int) settings('routing.health_window_hours'));
    }
}
