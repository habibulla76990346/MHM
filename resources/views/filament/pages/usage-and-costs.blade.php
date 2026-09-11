<x-filament-panels::page>
    {{-- Range. Four fixed windows rather than a date picker: an owner checking
         "how are we doing?" wants an answer in one tap, on a phone. --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $value => $label)
            <x-filament::button
                :color="$days === $value ? 'primary' : 'gray'"
                size="sm"
                wire:click="setRange({{ $value }})"
            >{{ $label }}</x-filament::button>
        @endforeach
    </div>

    @if ($this->missingRates())
        {{-- Never a silent zero: a cost figure missing a day's provider spend
             is a figure an owner would act on wrongly. --}}
        <x-filament::section compact>
            <p class="text-sm text-heading">
                No exchange rate on file for
                <strong>{{ implode(', ', $this->missingRates()) }}</strong>.
                Spending in {{ count($this->missingRates()) === 1 ? 'that currency' : 'those currencies' }}
                is shown as zero until a rate is added.
            </p>
        </x-filament::section>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['What you spent', $this->money($this->totals()['cost']), 'Paid to providers'],
            ['What you charged', $this->money($this->totals()['revenue']), 'Credits used by customers'],
            ['What you kept', $this->money($this->totals()['margin']), $this->totals()['margin_percent'] . '% of revenue'],
            ['Requests', number_format($this->totals()['requests']), $this->totals()['failures'] . ' failed'],
        ] as [$label, $value, $note])
            <x-filament::section compact>
                <div class="flex flex-col gap-1">
                    <span class="text-sm text-text-muted">{{ $label }}</span>
                    <span class="text-xl font-semibold text-heading">{{ $value }}</span>
                    <span class="text-xs text-text-muted">{{ $note }}</span>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Today so far" compact>
        <p class="text-sm text-text-muted">
            Not yet summarised, so these figures can still move —
            {{ number_format($this->today()['requests']) }} requests,
            {{ number_format($this->today()['tokens']) }} tokens,
            {{ $this->money($this->today()['cost']) }} spent.
        </p>
    </x-filament::section>

    <x-filament::section heading="By provider">
        @if ($this->byProvider())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-text-muted">
                            <th class="py-2 pr-4 font-medium">Provider</th>
                            <th class="py-2 pr-4 font-medium">Requests</th>
                            <th class="py-2 pr-4 font-medium">Spent</th>
                            <th class="py-2 pr-4 font-medium">Charged</th>
                            <th class="py-2 font-medium">Kept</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        @foreach ($this->byProvider() as $row)
                            <tr>
                                <td class="py-3 pr-4 text-heading">{{ $row['provider'] }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ number_format($row['requests']) }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ $this->money($row['cost']) }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ $this->money($row['revenue']) }}</td>
                                <td class="py-3">
                                    <x-filament::badge :color="$row['margin'] >= 0 ? 'success' : 'danger'">
                                        {{ $this->money($row['margin']) }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-text-muted">
                Nothing has been summarised for this period yet. Daily totals are written overnight.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Day by day" collapsible collapsed>
        @if ($this->rows())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-text-muted">
                            <th class="py-2 pr-4 font-medium">Date</th>
                            <th class="py-2 pr-4 font-medium">Model</th>
                            <th class="py-2 pr-4 font-medium">Requests</th>
                            <th class="py-2 pr-4 font-medium">Tokens</th>
                            <th class="py-2 pr-4 font-medium">Spent</th>
                            <th class="py-2 font-medium">Kept</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        @foreach ($this->rows() as $row)
                            <tr>
                                <td class="py-3 pr-4 text-heading">{{ $row['date'] }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ $row['model'] }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ number_format($row['requests']) }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ number_format($row['tokens']) }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ $this->money($row['cost']) }}</td>
                                <td class="py-3 text-text-muted">{{ $this->money($row['margin']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-text-muted">No days in this range have been summarised yet.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
