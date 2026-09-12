@php $balance = $this->balance(); @endphp

<x-filament-panels::page>
    <x-filament::section heading="Find a customer">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Email or name"
            class="fi-input w-full"
        >

        @if ($this->matches()->isNotEmpty())
            <ul class="mt-3 divide-y divide-divider">
                @foreach ($this->matches() as $match)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <span class="text-sm text-heading">{{ $match->email }}</span>
                        <x-filament::button size="xs" color="gray" wire:click="select({{ $match->id }})">Open</x-filament::button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    @if ($this->selected())
        <x-filament::section :heading="$this->selected()->email">
            @unless ($balance['reconciles'])
                <p class="mb-3 text-sm font-medium text-heading">
                    This balance does not match the ledger. Something has written it outside the credit service — treat every figure here as suspect.
                </p>
            @endunless

            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([
                    ['Balance', $balance['confirmed']],
                    ['Reserved for calls in flight', $balance['held']],
                    ['Spendable now', $balance['spendable']],
                ] as [$label, $value])
                    <div class="flex flex-col gap-1">
                        <span class="text-sm text-text-muted">{{ $label }}</span>
                        <span class="text-xl font-semibold text-heading">{{ number_format($value, 2) }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if ($this->canAdjust())
            <x-filament::section heading="Adjust" description="Recorded on the ledger for ever, with your name and your reason.">
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-sm font-medium text-heading">Amount</span>
                        <input type="number" step="0.000001" wire:model="amount" class="fi-input w-full">
                        <span class="text-xs text-text-muted">Negative to take credits away.</span>
                    </label>

                    <label class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-sm font-medium text-heading">Reason</span>
                        <input type="text" wire:model="reason" class="fi-input w-full">
                    </label>
                </div>

                <div class="mt-4">
                    <x-filament::button wire:click="adjust">Apply adjustment</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="History">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-text-muted">
                            <th class="py-2 pr-4 font-medium">When</th>
                            <th class="py-2 pr-4 font-medium">What</th>
                            <th class="py-2 pr-4 font-medium">Amount</th>
                            <th class="py-2 pr-4 font-medium">Balance after</th>
                            <th class="py-2 font-medium">Who</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-divider">
                        @forelse ($this->ledger() as $entry)
                            <tr>
                                <td class="py-3 pr-4 text-text-muted">{{ $entry->created_at?->format('j M Y H:i') }}</td>
                                <td class="py-3 pr-4 text-heading">{{ $entry->reason }}</td>
                                <td class="py-3 pr-4">
                                    <x-filament::badge :color="$entry->isCredit() ? 'success' : 'gray'">
                                        {{ number_format((float) $entry->amount, 2) }}
                                    </x-filament::badge>
                                </td>
                                <td class="py-3 pr-4 text-text-muted">{{ number_format((float) $entry->balance_after, 2) }}</td>
                                <td class="py-3 text-text-muted">{{ $entry->actor?->email ?? 'System' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-3 text-text-muted">No credit movements yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
