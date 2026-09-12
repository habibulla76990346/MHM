@php
    use App\Domains\Tax\Models\TaxSettings;
    $settings = $this->current();
    $preview = $this->preview();
@endphp

<x-filament-panels::page>
    <x-filament::section compact>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">
                    {{ $settings->tax_enabled ? 'Tax is being charged' : 'Tax is switched off' }}
                </span>
                <span class="text-xs text-text-muted">
                    @if ($settings->missingFields())
                        Still needed before tax can be charged: {{ implode(', ', $settings->missingFields()) }}.
                    @else
                        Every invoice records the rates that applied on its own date, permanently.
                    @endif
                </span>
            </div>

            @if ($this->canWrite())
                <x-filament::button
                    :color="$settings->tax_enabled ? 'danger' : 'primary'"
                    wire:click="toggleTax"
                    wire:confirm="This changes what every future invoice says. Continue?"
                >
                    {{ $settings->tax_enabled ? 'Stop charging tax' : 'Start charging tax' }}
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section heading="Your business" description="Copied onto every invoice at the moment it is issued, and never changed afterwards.">
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($this->settingFields() as $field => $label)
                <label class="flex flex-col gap-1 {{ $field === 'address_lines' ? 'sm:col-span-2' : '' }}">
                    <span class="text-sm font-medium text-heading">{{ $label }}</span>

                    @if ($field === 'country')
                        <select wire:model="settings.country" @disabled(! $this->canWrite()) class="fi-input fi-select-input w-full">
                            <option value="">Choose…</option>
                            @foreach ($this->countryOptions() as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" wire:model="settings.{{ $field }}" @disabled(! $this->canWrite()) class="fi-input w-full">
                    @endif
                </label>
            @endforeach
        </div>

        @if ($this->canWrite())
            <div class="mt-4">
                <x-filament::button wire:click="saveSettings">Save business details</x-filament::button>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="How prices are quoted">
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap gap-2">
                @foreach ([TaxSettings::EXCLUSIVE => 'Tax added to the price', TaxSettings::INCLUSIVE => 'Price already includes tax'] as $mode => $label)
                    <x-filament::button
                        size="sm"
                        :color="$settings->pricing_mode === $mode ? 'primary' : 'gray'"
                        wire:click="setPricingMode('{{ $mode }}')"
                        :disabled="! $this->canWrite()"
                    >{{ $label }}</x-filament::button>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach (TaxSettings::ROUNDING as $mode => $label)
                    <x-filament::button
                        size="sm"
                        :color="$settings->rounding_mode === $mode ? 'primary' : 'gray'"
                        wire:click="setRoundingMode('{{ $mode }}')"
                        :disabled="! $this->canWrite()"
                    >{{ $label }}</x-filament::button>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Invoice numbering" description="Numbers are allocated only when an invoice is issued, so a failed payment never leaves a gap.">
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Prefix</span>
                <input type="text" wire:model="numbering.prefix" @disabled(! $this->canWrite()) class="fi-input w-full">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Suffix</span>
                <input type="text" wire:model="numbering.suffix" @disabled(! $this->canWrite()) class="fi-input w-full">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Digits</span>
                <input type="number" wire:model="numbering.padding" @disabled(! $this->canWrite()) class="fi-input w-full">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Restart the count</span>
                <select wire:model="numbering.reset_policy" @disabled(! $this->canWrite()) class="fi-input fi-select-input w-full">
                    @foreach ($this->resetPolicies() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Financial year starts in month</span>
                <input type="number" min="1" max="12" wire:model="numbering.fy_start_month" @disabled(! $this->canWrite()) class="fi-input w-full">
            </label>

            <label class="flex flex-col gap-1 sm:col-span-2">
                <span class="text-sm font-medium text-heading">Format</span>
                <input type="text" wire:model="numbering.format_template" @disabled(! $this->canWrite()) class="fi-input w-full">
                <span class="text-xs text-text-muted">Placeholders: {prefix} {number} {fy} {year} {month} {suffix}</span>
            </label>
        </div>

        <p class="mt-4 text-sm text-text-muted">Next invoice will be numbered <strong class="text-heading">{{ $this->nextNumber() }}</strong>.</p>

        @if ($this->canWrite())
            <div class="mt-4">
                <x-filament::button wire:click="saveNumbering">Save numbering</x-filament::button>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="What would this customer pay?" description="Checked against a hypothetical customer, using the same engine a real invoice uses. An issued invoice cannot be corrected, only credited — so check here first.">
        <div class="grid gap-4 sm:grid-cols-4">
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Amount</span>
                <input type="number" wire:model.live="previewAmount" class="fi-input w-full">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">Country</span>
                <select wire:model.live="previewCountry" class="fi-input fi-select-input w-full">
                    <option value="">Choose…</option>
                    @foreach ($this->countryOptions() as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-heading">State</span>
                <input type="text" wire:model.live="previewState" class="fi-input w-full">
            </label>

            {{-- Tapping the label toggles the box, so the label is the target
                 the 44px rule measures. --}}
            <label class="flex items-center gap-2 sm:pt-6" style="min-height: var(--tap-min);">
                <input type="checkbox" wire:model.live="previewRegistered" style="width:1.15rem;height:1.15rem;">
                <span class="text-sm text-heading">Registered business</span>
            </label>
        </div>

        <div class="mt-4 flex flex-col gap-2 text-sm">
            @forelse ($preview->components as $component)
                <div class="flex justify-between">
                    <span class="text-text-muted">
                        {{ $component['name'] }} ({{ rtrim(rtrim(number_format($component['rate_percent'], 3), '0'), '.') }}%)
                    </span>
                    <span class="text-heading">{{ number_format($component['tax_amount'], 2) }}</span>
                </div>
            @empty
                <p class="text-text-muted">{{ $preview->note ?: 'No tax would be charged.' }}</p>
            @endforelse

            <div class="flex justify-between border-t border-divider pt-2 font-semibold">
                <span class="text-heading">Customer pays</span>
                <span class="text-heading">{{ number_format($preview->gross, 2) }}</span>
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
