<?php

namespace App\Filament\Pages;

use App\Domains\Billing\Models\Country;
use App\Domains\Billing\Models\InvoiceNumberSequence;
use App\Domains\Billing\Services\InvoiceNumberAllocator;
use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Tax\Models\CustomerTaxProfile;
use App\Domains\Tax\Models\TaxSettings;
use App\Domains\Tax\Services\TaxEngine;
use App\Domains\Tax\Support\TaxComputation;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * ADMIN → Billing → Tax and compliance (Addendum F).
 *
 * The business's own tax identity, the invoice numbering rules, and the
 * PREVIEW TOOL — which earns its place because an issued invoice cannot be
 * corrected, only credited. Checking the configuration against a hypothetical
 * customer before a real invoice exists is the difference between fixing a
 * setting and issuing a credit note.
 *
 * Nothing on this screen ships filled in. Tax is off until an administrator
 * turns it on, and the screen says exactly what is still missing.
 */
class TaxAndCompliance extends Page
{
    protected static ?string $navigationLabel = 'Tax and compliance';

    protected static ?string $title = 'Tax and compliance';

    protected static ?string $slug = 'tax-and-compliance';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 51;

    protected string $view = 'filament.pages.tax-and-compliance';

    /** Dot-free keys: Livewire reads a dot in wire:model as a nested path. */
    public array $settings = [];

    public array $numbering = [];

    // -- the preview tool -----------------------------------------------------

    public float $previewAmount = 1000;

    public ?string $previewCountry = null;

    public ?string $previewState = null;

    public bool $previewRegistered = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('billing.tax.manage') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $settings = TaxSettings::current();

        foreach ($this->settingFields() as $field => $label) {
            $this->settings[$field] = $settings->{$field};
        }

        $sequence = app(InvoiceNumberAllocator::class)->sequence();

        foreach (['prefix', 'suffix', 'padding', 'reset_policy', 'fy_start_month', 'format_template'] as $field) {
            $this->numbering[$field] = $sequence->{$field};
        }

        $this->previewCountry = $settings->country;
        $this->previewState = $settings->state;
    }

    /** @return array<string, string> */
    public function settingFields(): array
    {
        return [
            'legal_name' => 'Registered business name',
            'address_lines' => 'Address',
            'city' => 'City',
            'state' => 'State or region',
            'postal_code' => 'Postal code',
            'country' => 'Country',
            'tax_registration_number' => 'Tax registration number',
            'registration_type' => 'Registration type',
            'default_place_of_supply' => 'Default place of supply',
            'service_code' => 'Service code',
        ];
    }

    public function current(): TaxSettings
    {
        return TaxSettings::current();
    }

    public function countryOptions(): array
    {
        return Country::orderBy('name')->pluck('name', 'code')->all();
    }

    public function resetPolicies(): array
    {
        return InvoiceNumberSequence::resetPolicies();
    }

    public function nextNumber(): string
    {
        return app(InvoiceNumberAllocator::class)->preview(now());
    }

    public function canWrite(): bool
    {
        return auth()->user()?->can('billing.tax.manage') ?? false;
    }

    // -- writes ---------------------------------------------------------------

    public function saveSettings(): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $settings = TaxSettings::current();
        $before = $settings->getOriginal();

        $settings->fill(array_merge(
            array_intersect_key($this->settings, $this->settingFields()),
            ['updated_by' => auth()->id()],
        ))->save();

        app(ActivityLogger::class)->log('tax.settings_updated', $settings, $before, $settings->getChanges());

        Notification::make()->title('Saved')->success()->send();
    }

    public function saveNumbering(): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $sequence = app(InvoiceNumberAllocator::class)->sequence();
        $before = $sequence->getOriginal();

        $sequence->fill([
            'prefix' => (string) ($this->numbering['prefix'] ?? ''),
            'suffix' => (string) ($this->numbering['suffix'] ?? ''),
            'padding' => max(1, min(12, (int) ($this->numbering['padding'] ?? 5))),
            'reset_policy' => $this->numbering['reset_policy'] ?? InvoiceNumberSequence::RESET_FINANCIAL_YEAR,
            'fy_start_month' => max(1, min(12, (int) ($this->numbering['fy_start_month'] ?? 4))),
            'format_template' => (string) ($this->numbering['format_template'] ?? '{prefix}{fy}{number}{suffix}'),
        ])->save();

        app(ActivityLogger::class)->log('tax.numbering_updated', $sequence, $before, $sequence->getChanges());

        Notification::make()->title('Saved')->success()->send();
    }

    /**
     * Turning tax on is its own action, not a checkbox lost in a form.
     *
     * It changes what every future invoice says, so it is deliberate,
     * permission-gated and audited — and refused outright while the details it
     * would print are still missing.
     */
    public function toggleTax(): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $settings = TaxSettings::current();
        $turningOn = ! $settings->tax_enabled;

        if ($turningOn && $settings->missingFields() !== []) {
            Notification::make()
                ->title('Not enough detail yet')
                ->body('Still needed: '.implode(', ', $settings->missingFields()).'.')
                ->warning()
                ->send();

            return;
        }

        $settings->forceFill(['tax_enabled' => $turningOn, 'updated_by' => auth()->id()])->save();

        app(ActivityLogger::class)->log(
            'tax.enabled_changed',
            $settings,
            ['tax_enabled' => ! $turningOn],
            ['tax_enabled' => $turningOn],
        );

        Notification::make()
            ->title($turningOn ? 'Tax is now being charged' : 'Tax is switched off')
            ->success()
            ->send();
    }

    public function setPricingMode(string $mode): void
    {
        if (! $this->canWrite()) {
            return;
        }

        $settings = TaxSettings::current();
        $before = ['pricing_mode' => $settings->pricing_mode];

        $settings->forceFill([
            'pricing_mode' => $mode === TaxSettings::INCLUSIVE ? TaxSettings::INCLUSIVE : TaxSettings::EXCLUSIVE,
        ])->save();

        app(ActivityLogger::class)->log('tax.pricing_mode_changed', $settings, $before, ['pricing_mode' => $mode]);

        Notification::make()->title('Saved')->success()->send();
    }

    public function setRoundingMode(string $mode): void
    {
        if (! $this->canWrite() || ! array_key_exists($mode, TaxSettings::ROUNDING)) {
            return;
        }

        TaxSettings::current()->forceFill(['rounding_mode' => $mode])->save();

        Notification::make()->title('Saved')->success()->send();
    }

    /**
     * What tax a given customer would pay, without issuing anything.
     *
     * Uses the SAME engine a real invoice does — a preview computed a second
     * way would eventually disagree with the thing it is meant to check.
     */
    public function preview(): TaxComputation
    {
        $profile = new CustomerTaxProfile([
            'country' => $this->previewCountry,
            'state' => $this->previewState,
            'tax_registration_number' => $this->previewRegistered ? 'PREVIEW' : null,
        ]);

        return app(TaxEngine::class)->compute(max(0, (float) $this->previewAmount), $profile);
    }
}
