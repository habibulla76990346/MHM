<?php

namespace App\Filament\Pages;

use App\Domains\Branding\Services\BrandAssetPublisher;
use App\Domains\Branding\Services\BrandAssetRejected;
use App\Domains\Branding\Services\BrandingService;
use App\Domains\Branding\Support\BrandAsset;
use App\Domains\Files\Exceptions\UploadRejected;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * ADMIN → Branding (blueprint §4, owner decisions D-09 and D-13).
 *
 * What the product is called and what it looks like. The artwork that ships
 * with Aziv AI is the starting point, never a placeholder, and every asset can
 * be replaced and put back.
 *
 * An upload here takes the ordinary Phase 1 path — private disk, validated,
 * scanned — and only then is a derived copy written to the web root. The two
 * steps stay separate so nothing reaches public/ before the scanner has seen it.
 */
class Branding extends Page
{
    use WithFileUploads;

    protected static ?string $navigationLabel = 'Branding';

    protected static ?string $title = 'Branding';

    protected static ?string $slug = 'branding';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.branding';

    /** @var array<string, TemporaryUploadedFile|null> purpose => pending upload */
    public array $uploads = [];

    public array $text = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('branding.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach (self::textFields() as $key => $field) {
            // Dot-free keys: Livewire reads a dot in wire:model as a nested
            // array path, so `text.branding.app_name` would bind the wrong
            // thing entirely. Same trap as the theme editor.
            $this->text[self::wireKey($key)] = settings($key);
        }
    }

    /** @return array<string, array{label: string, help: string, type: string}> */
    public static function textFields(): array
    {
        return [
            'branding.app_name' => ['label' => 'Product name', 'help' => 'Appears in the browser tab, headings and emails.', 'type' => 'text'],
            'branding.short_name' => ['label' => 'Short name', 'help' => 'Used where space is tight, and under the icon when installed to a phone.', 'type' => 'text'],
            'branding.tagline' => ['label' => 'Tagline', 'help' => 'One line describing the product.', 'type' => 'text'],
            'branding.support_email' => ['label' => 'Support email', 'help' => 'Where customers are told to write.', 'type' => 'email'],
            'branding.footer_text' => ['label' => 'Footer line', 'help' => 'Shown at the foot of customer pages.', 'type' => 'text'],
        ];
    }

    public static function wireKey(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function assets(): array
    {
        return BrandAsset::all();
    }

    public function currentPath(string $purpose): string
    {
        return app(BrandingService::class)->path($purpose);
    }

    public function isCustomised(string $purpose): bool
    {
        return app(BrandingService::class)->isCustomised($purpose);
    }

    public function webRootIsWritable(): bool
    {
        return app(BrandAssetPublisher::class)->webRootIsWritable();
    }

    public function canWrite(): bool
    {
        return auth()->user()?->can('branding.manage') ?? false;
    }

    // -- writes --------------------------------------------------------------

    public function saveText(): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $before = [];
        $after = [];

        foreach (self::textFields() as $key => $field) {
            $value = $this->text[self::wireKey($key)] ?? null;
            $current = settings($key);

            if ((string) $value === (string) $current) {
                continue;
            }

            try {
                settings()->set($key, $value === '' ? null : $value, auth()->id());
            } catch (ValidationException $e) {
                Notification::make()
                    ->title($field['label'].' was not saved')
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

        app(ActivityLogger::class)->log('branding.text_updated', null, $before, $after);

        Notification::make()->title('Saved')->body(count($after).' setting(s) updated.')->success()->send();
    }

    public function upload(string $purpose): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $file = $this->uploads[$purpose] ?? null;

        if (! $file instanceof TemporaryUploadedFile) {
            Notification::make()->title('Choose a file first')->warning()->send();

            return;
        }

        try {
            // TemporaryUploadedFile IS an UploadedFile, so it goes straight
            // into the ordinary Phase 1 pipeline with no special casing.
            $result = app(BrandingService::class)->replace($purpose, $file, auth()->user());
        } catch (UploadRejected|BrandAssetRejected $e) {
            // Addendum G: say exactly what is wrong and what to do about it.
            Notification::make()->title('That file was not used')->body($e->getMessage())->danger()->persistent()->send();

            return;
        }

        app(ActivityLogger::class)->log(
            'branding.asset_replaced',
            null,
            [$purpose => $result['previous']],
            [$purpose => $result['path']],
        );

        $this->uploads[$purpose] = null;

        Notification::make()->title(BrandAsset::all()[$purpose]['label'].' updated')->success()->send();
    }

    public function resetAsset(string $purpose): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $previous = settings(BrandAsset::settingKey($purpose));

        app(BrandingService::class)->reset($purpose, auth()->id());

        app(ActivityLogger::class)->log('branding.asset_reset', null, [$purpose => $previous], [$purpose => null]);

        Notification::make()
            ->title('Back to the Aziv AI artwork')
            ->body('The original artwork is never overwritten, so it is always there to return to.')
            ->success()
            ->send();
    }
}
