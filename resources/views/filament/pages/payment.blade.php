<x-filament-panels::page>
    <x-filament::section>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Customer', $record->user?->email ?? '—'],
                ['Amount', $record->presentment_currency . ' ' . number_format((float) $record->presentment_amount, 2)],
                ['Status', ucfirst(str_replace('_', ' ', (string) $record->status))],
                ['Gateway', $record->gateway?->name ?? '—'],
            ] as [$label, $value])
                <div class="flex flex-col gap-1">
                    <span class="text-sm text-text-muted">{{ $label }}</span>
                    <span class="font-medium text-heading">{{ $value }}</span>
                </div>
            @endforeach
        </div>

        <div class="mt-4 grid gap-4 border-t border-divider pt-4 text-sm sm:grid-cols-2">
            <div class="flex flex-col gap-1">
                <span class="text-text-muted">Our reference</span>
                <code class="break-all text-heading">{{ $record->uuid }}</code>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-text-muted">Gateway reference</span>
                <code class="break-all text-heading">{{ $record->gateway_payment_id ?: $record->gateway_order_id ?: '—' }}</code>
            </div>
        </div>

        @if ($record->settlement_amount !== null)
            <p class="mt-4 text-sm text-text-muted">
                Settled as {{ $record->settlement_currency }} {{ number_format((float) $record->settlement_amount, 2) }} —
                what actually reached the account, which for a cross-border payment is neither the amount the
                customer saw nor its currency.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="What happened, in order">
        <ol class="flex flex-col gap-3 text-sm">
            @forelse ($record->transactions as $entry)
                <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-divider pb-3 last:border-0 last:pb-0">
                    <span class="font-medium text-heading">{{ ucfirst($entry->type) }}</span>
                    <span class="text-text-muted">{{ $entry->status }}</span>
                    <span class="text-text-muted">{{ $entry->created_at?->format('j M H:i:s') }}</span>
                </li>
            @empty
                <li class="text-text-muted">Nothing recorded yet.</li>
            @endforelse
        </ol>
    </x-filament::section>

    @if ($record->refunds->isNotEmpty())
        <x-filament::section heading="Refunds">
            <ul class="flex flex-col gap-2 text-sm">
                @foreach ($record->refunds as $refund)
                    <li class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="text-heading">{{ $refund->reason }}</span>
                        <span class="text-text-muted">
                            {{ number_format((float) $refund->credits_revoked, 2) }} credits taken back
                        </span>
                        <span class="font-medium text-heading">
                            {{ $refund->currency }} {{ number_format((float) $refund->amount, 2) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    @if ($record->invoice)
        <x-filament::section heading="Invoice">
            <p class="text-sm text-heading">{{ $record->invoice->number }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
