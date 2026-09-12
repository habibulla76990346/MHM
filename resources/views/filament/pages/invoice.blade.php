<x-filament-panels::page>
    {{-- Everything here comes from the invoice row itself. Nothing is looked
         up again, which is what makes a two-year-old invoice still read as it
         did the day it was sent. --}}
    <x-filament::section>
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="flex flex-col gap-1 text-sm">
                <span class="text-text-muted">From</span>
                <span class="font-medium text-heading">{{ $record->supplier_legal_name ?: '—' }}</span>
                <span class="whitespace-pre-line text-text-muted">{{ $record->supplier_address }}</span>
                @if ($record->supplier_tax_number)
                    <span class="text-text-muted">Registration {{ $record->supplier_tax_number }}</span>
                @endif
            </div>

            <div class="flex flex-col gap-1 text-sm">
                <span class="text-text-muted">To</span>
                <span class="font-medium text-heading">{{ $record->customer_name ?: $record->user?->email }}</span>
                <span class="whitespace-pre-line text-text-muted">{{ $record->customer_address }}</span>
                @if ($record->customer_tax_number)
                    <span class="text-text-muted">Registration {{ $record->customer_tax_number }}</span>
                @endif
                @if ($record->place_of_supply)
                    <span class="text-text-muted">Place of supply: {{ $record->place_of_supply }}</span>
                @endif
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="What was billed">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-text-muted">
                        <th class="py-2 pr-4 font-medium">Description</th>
                        <th class="py-2 pr-4 font-medium">Quantity</th>
                        <th class="py-2 font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @foreach ($record->lines as $line)
                        <tr>
                            <td class="py-3 pr-4 text-heading">{{ $line->description }}</td>
                            <td class="py-3 pr-4 text-text-muted">{{ rtrim(rtrim(number_format((float) $line->quantity, 2), '0'), '.') }}</td>
                            <td class="py-3 text-text-muted">{{ $record->currency }} {{ number_format((float) $line->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Tax">
        @if ($record->taxLines->isEmpty())
            <p class="text-sm text-text-muted">
                {{ $record->tax_note ?: 'No tax was charged on this invoice.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-text-muted">
                            <th class="py-2 pr-4 font-medium">Component</th>
                            <th class="py-2 pr-4 font-medium">Rate</th>
                            <th class="py-2 pr-4 font-medium">Taxable</th>
                            <th class="py-2 font-medium">Tax</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        @foreach ($record->taxLines as $line)
                            <tr>
                                <td class="py-3 pr-4 text-heading">{{ $line->component_name }}</td>
                                <td class="py-3 pr-4 text-text-muted">{{ rtrim(rtrim(number_format((float) $line->rate_percent, 3), '0'), '.') }}%</td>
                                <td class="py-3 pr-4 text-text-muted">{{ $record->currency }} {{ number_format((float) $line->taxable_amount, 2) }}</td>
                                <td class="py-3 text-text-muted">{{ $record->currency }} {{ number_format((float) $line->tax_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <div class="flex flex-col gap-2 text-sm">
            <div class="flex justify-between">
                <span class="text-text-muted">Subtotal</span>
                <span class="text-heading">{{ $record->currency }} {{ number_format((float) $record->subtotal, 2) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-text-muted">Tax</span>
                <span class="text-heading">{{ $record->currency }} {{ number_format((float) $record->tax_total, 2) }}</span>
            </div>
            <div class="flex justify-between border-t border-divider pt-2 text-base font-semibold">
                <span class="text-heading">Total</span>
                <span class="text-heading">{{ $record->currency }} {{ number_format((float) $record->total, 2) }}</span>
            </div>
        </div>
    </x-filament::section>

    @if ($record->creditNotes->isNotEmpty())
        <x-filament::section heading="Credit notes">
            <ul class="flex flex-col gap-2 text-sm">
                @foreach ($record->creditNotes as $note)
                    <li class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-heading">{{ $note->number }}</span>
                        <span class="text-text-muted">{{ $note->reason }}</span>
                        <span class="text-heading">{{ $note->currency }} {{ number_format((float) $note->amount, 2) }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm text-text-muted">
                Still outstanding: {{ $record->currency }} {{ number_format($record->outstanding(), 2) }}
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
