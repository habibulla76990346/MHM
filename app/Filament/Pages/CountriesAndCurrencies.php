<?php

namespace App\Filament\Pages;

use App\Domains\AI\Models\ExchangeRate;
use App\Domains\Billing\Models\Country;
use App\Domains\Billing\Models\Currency;
use App\Domains\Security\Services\ActivityLogger;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * ADMIN → Billing → Countries and currencies (Addendum F §4).
 *
 * Which markets the business sells into, what it prices in, and the dated
 * exchange rates margin reporting is computed from.
 *
 * `decimal_places` is editable because it is not universal: JPY has none and
 * KWD has three, and a formatter that assumed two would invent money in one
 * market and lose it in the other.
 */
class CountriesAndCurrencies extends Page
{
    protected static ?string $navigationLabel = 'Countries and currencies';

    protected static ?string $title = 'Countries and currencies';

    protected static ?string $slug = 'countries-and-currencies';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.countries-and-currencies';

    public string $rateFrom = 'USD';

    public string $rateTo = 'INR';

    public ?string $rateValue = null;

    public ?string $rateDate = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('billing.manage') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->rateDate = now()->toDateString();
        $this->rateTo = strtoupper((string) settings('billing.base_currency'));
    }

    public function currencies()
    {
        return Currency::orderBy('sort_order')->orderBy('code')->get();
    }

    public function countries()
    {
        return Country::orderBy('name')->get();
    }

    public function rates()
    {
        return ExchangeRate::orderByDesc('effective_on')->limit(25)->get();
    }

    public function canWrite(): bool
    {
        return auth()->user()?->can('billing.manage') ?? false;
    }

    public function toggleCurrency(int $id): void
    {
        if (! $this->canWrite()) {
            return;
        }

        $currency = Currency::findOrFail($id);

        // The base currency is what every report is expressed in. Switching it
        // off would leave the margin figures with nothing to convert into.
        if ($currency->is_base && $currency->is_active) {
            Notification::make()
                ->title('That is your reporting currency')
                ->body('Choose a different base currency first.')
                ->warning()
                ->send();

            return;
        }

        $currency->forceFill(['is_active' => ! $currency->is_active])->save();

        app(ActivityLogger::class)->log('billing.currency_toggled', $currency, null, [
            'code' => $currency->code,
            'is_active' => $currency->is_active,
        ]);
    }

    public function toggleCountry(int $id): void
    {
        if (! $this->canWrite()) {
            return;
        }

        $country = Country::findOrFail($id);
        $country->forceFill(['is_billing_enabled' => ! $country->is_billing_enabled])->save();

        app(ActivityLogger::class)->log('billing.country_toggled', $country, null, [
            'code' => $country->code,
            'is_billing_enabled' => $country->is_billing_enabled,
        ]);
    }

    /**
     * Record a rate by hand.
     *
     * DATED AND NEVER OVERWRITTEN for a past day: the rate that applied on a
     * day is a historical fact, and rewriting it would move a margin figure
     * that has already been reported.
     */
    public function addRate(): void
    {
        if (! $this->canWrite()) {
            Notification::make()->title('Not permitted')->danger()->send();

            return;
        }

        $value = (float) $this->rateValue;

        if ($value <= 0) {
            Notification::make()->title('Enter a rate above zero')->warning()->send();

            return;
        }

        $rate = ExchangeRate::updateOrCreate(
            [
                'base_currency' => strtoupper($this->rateFrom),
                'quote_currency' => strtoupper($this->rateTo),
                'effective_on' => $this->rateDate ?: now()->toDateString(),
            ],
            ['rate' => $value, 'source' => 'manual'],
        );

        app(ActivityLogger::class)->log('billing.exchange_rate_set', $rate, null, [
            'pair' => $rate->base_currency.'→'.$rate->quote_currency,
            'rate' => $value,
            'effective_on' => (string) $this->rateDate,
        ]);

        $this->rateValue = null;

        Notification::make()->title('Rate recorded')->success()->send();
    }
}
