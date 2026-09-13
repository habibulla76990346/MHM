<?php

namespace App\Filament\Pages;

use App\Domains\AI\Routing\AiRouter;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\AI\Support\Capability;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Settings\Support\SettingDefinition;
use App\Domains\Voice\Models\VoiceJob;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * ADMIN → Media → Images and voice (§16, §18).
 *
 * ONE SCREEN FOR BOTH because they are the same decision made twice: is the
 * feature on, what does one customer get in a day, and how long is what they
 * made kept. Splitting them would mean an owner switching on voice and never
 * finding the retention setting.
 *
 * IT SAYS WHETHER THE FEATURE CAN ACTUALLY WORK. A switch that is on with no
 * model behind it is the most confusing state this product can be in — the
 * customer sees a button that always fails — so the top of the screen answers
 * "is there anything that can serve this?" before offering the switch.
 *
 * The settings themselves are read from the registry rather than restated
 * here, so a label, its help text and the validation actually enforced on save
 * cannot drift apart.
 */
class ImagesAndVoice extends Page
{
    protected static ?string $navigationLabel = 'Images and voice';

    protected static ?string $title = 'Images and voice';

    protected static ?string $slug = 'images-and-voice';

    protected static string|\UnitEnum|null $navigationGroup = 'Media';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.images-and-voice';

    /**
     * Keyed WITHOUT dots.
     *
     * Livewire reads a dot in `wire:model` as a nested array path, so binding
     * `values.images.enabled` would write `$values['images']['enabled']` and
     * never touch the real key — silently, with no error. That trap has bitten
     * this project three times.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('media.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach (array_keys($this->editableSettings()) as $key) {
            $this->values[self::wireKey($key)] = settings($key);
        }
    }

    public static function wireKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return array<string, SettingDefinition> */
    public function editableSettings(): array
    {
        return settings()->registry()->group('images')
            + settings()->registry()->group('voice');
    }

    public function canWrite(): bool
    {
        return auth()->user()?->can('media.manage') ?? false;
    }

    /**
     * Whether anything in the catalog could serve each capability.
     *
     * Asked WITHOUT recording a routing decision: this runs on every render of
     * an admin screen, and a routing log per page view would bury the real
     * ones.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function readiness(): array
    {
        $router = app(AiRouter::class);

        $can = static fn (string $capability): bool => $router
            ->route([$capability], RoutingMode::AUTO, record: false)
            ->model !== null;

        return [
            'image' => $can(Capability::IMAGE_GENERATION),
            'transcription' => $can(Capability::TRANSCRIPTION),
            'speech' => $can(Capability::SPEECH),
        ];
    }

    /** @return array<string, int> */
    #[Computed]
    public function activity(): array
    {
        return [
            'images_today' => ImageGeneration::where('created_at', '>=', now()->startOfDay())->count(),
            'images_failed_today' => ImageGeneration::where('created_at', '>=', now()->startOfDay())
                ->where('status', ImageGeneration::FAILED)->count(),
            'voice_today' => VoiceJob::where('created_at', '>=', now()->startOfDay())->count(),
            'voice_failed_today' => VoiceJob::where('created_at', '>=', now()->startOfDay())
                ->where('status', VoiceJob::FAILED)->count(),
        ];
    }

    /** Authorise, validate, audit — all three, or it does not ship. */
    public function save(): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $before = [];
        $after = [];

        foreach ($this->editableSettings() as $key => $definition) {
            $value = $this->values[self::wireKey($key)] ?? null;

            if ($definition->type === 'bool') {
                $value = (bool) $value;
            }

            $current = settings($key);

            if ((string) $value === (string) $current) {
                continue;
            }

            try {
                // The registry's own rules, applied under a flat field name.
                // This method never restates a rule.
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

        app(ActivityLogger::class)->log('media.settings_updated', null, $before, $after);

        Notification::make()->title('Saved')->body(count($after).' setting(s) updated.')->success()->send();
    }
}
