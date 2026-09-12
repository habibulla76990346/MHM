<x-filament-panels::page>
    <x-filament::section heading="Currencies" description="Decimal places are per currency because they genuinely differ — two is not universal.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-text-muted">
                        <th class="py-2 pr-4 font-medium">Currency</th>
                        <th class="py-2 pr-4 font-medium">Symbol</th>
                        <th class="py-2 pr-4 font-medium">Decimals</th>
                        <th class="py-2 font-medium">Selling in it</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($this->currencies() as $currency)
                        <tr>
                            <td class="py-3 pr-4 text-heading">
                                {{ $currency->code }} — {{ $currency->name }}
                                @if ($currency->is_base)
                                    <x-filament::badge color="primary" class="ml-2">Reporting currency</x-filament::badge>
                                @endif
                            </td>
                            <td class="py-3 pr-4 text-text-muted">{{ $currency->symbol }}</td>
                            <td class="py-3 pr-4 text-text-muted">{{ $currency->decimal_places }}</td>
                            <td class="py-3">
                                <x-filament::button
                                    size="xs"
                                    :color="$currency->is_active ? 'success' : 'gray'"
                                    wire:click="toggleCurrency({{ $currency->id }})"
                                    :disabled="! $this->canWrite()"
                                >{{ $currency->is_active ? 'Yes' : 'No' }}</x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Countries" collapsible>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-text-muted">
                        <th class="py-2 pr-4 font-medium">Country</th>
                        <th class="py-2 pr-4 font-medium">Currency</th>
                        <th class="py-2 pr-4 font-medium">Needs a state</th>
                        <th class="py-2 font-medium">Billing enabled</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($this->countries() as $country)
                        <tr>
                            <td class="py-3 pr-4 text-heading">{{ $country->name }}</td>
                            <td class="py-3 pr-4 text-text-muted">{{ $country->default_currency }}</td>
                            <td class="py-3 pr-4 text-text-muted">{{ $country->requires_state ? 'Yes' : 'No' }}</td>
                            <td class="py-3">
                                <x-filament::button
                                    size="xs"
                                    :color="$country->is_billing_enabled ? 'success' : 'gray'"
                                    wire:click="toggleCountry({{ $country->id }})"
                                    :disabled="! $this->canWrite()"
                                >{{ $country->is_billing_enabled ? 'Yes' : 'No' }}</x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Exchange rates" description="Dated. A rate already used to report a day's margin is never overwritten by a later one.">
        @if ($this->canWrite())
            <div class="grid gap-3 sm:grid-cols-5">
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-heading">From</span>
                    <input type="text" maxlength="3" wire:model="rateFrom" class="fi-input w-full">
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-heading">To</span>
                    <input type="text" maxlength="3" wire:model="rateTo" class="fi-input w-full">
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-heading">Rate</span>
                    <input type="number" step="0.00000001" wire:model="rateValue" class="fi-input w-full">
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-heading">Applies from</span>
                    <input type="date" wire:model="rateDate" class="fi-input w-full">
                </label>

                <div class="flex items-end">
                    <x-filament::button wire:click="addRate">Record</x-filament::button>
                </div>
            </div>
        @endif

        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-text-muted">
                        <th class="py-2 pr-4 font-medium">Pair</th>
                        <th class="py-2 pr-4 font-medium">Rate</th>
                        <th class="py-2 pr-4 font-medium">Applies from</th>
                        <th class="py-2 font-medium">Source</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @forelse ($this->rates() as $rate)
                        <tr>
                            <td class="py-3 pr-4 text-heading">{{ $rate->base_currency }} → {{ $rate->quote_currency }}</td>
                            <td class="py-3 pr-4 text-text-muted">{{ rtrim(rtrim((string) $rate->rate, '0'), '.') }}</td>
                            <td class="py-3 pr-4 text-text-muted">{{ $rate->effective_on?->toDateString() }}</td>
                            <td class="py-3 text-text-muted">{{ $rate->source }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-3 text-text-muted">No rates recorded yet. Costs in other currencies will show as zero until one is.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
