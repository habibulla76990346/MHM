<x-layouts.app :title="$invoice->number">
    {{-- Rendered entirely from what was frozen onto the invoice at issue.
         Nothing here is looked up again, so this document reads today exactly
         as it did the day it was sent. --}}
    <div class="flex flex-col gap-5" style="max-width: 48rem;">
        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <span class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">{{ $invoice->number }}</span>
                    <span class="text-sm text-text-muted">{{ __('Issued :date', ['date' => $invoice->issued_at?->format('j M Y')]) }}</span>
                </div>

                <span class="rounded-md border border-border px-3 py-1 text-sm text-text-muted">
                    {{ ucfirst($invoice->status) }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="mb-2 text-sm text-text-muted">{{ __('From') }}</h2>
                <p class="font-medium text-text">{{ $invoice->supplier_legal_name }}</p>
                <p class="whitespace-pre-line text-sm text-text-muted">{{ $invoice->supplier_address }}</p>
                @if ($invoice->supplier_tax_number)
                    <p class="text-sm text-text-muted">{{ $invoice->supplier_tax_number }}</p>
                @endif
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="mb-2 text-sm text-text-muted">{{ __('To') }}</h2>
                <p class="font-medium text-text">{{ $invoice->customer_name }}</p>
                <p class="whitespace-pre-line text-sm text-text-muted">{{ $invoice->customer_address }}</p>
                @if ($invoice->customer_tax_number)
                    <p class="text-sm text-text-muted">{{ $invoice->customer_tax_number }}</p>
                @endif
                @if ($invoice->place_of_supply)
                    <p class="text-sm text-text-muted">{{ __('Place of supply: :place', ['place' => $invoice->place_of_supply]) }}</p>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <ul class="flex flex-col gap-3">
                @foreach ($invoice->lines as $line)
                    <li class="flex flex-wrap justify-between gap-2 border-b border-divider pb-3 last:border-0 last:pb-0">
                        <span class="text-text">{{ $line->description }}</span>
                        <span class="font-medium text-text">{{ $invoice->currency }} {{ number_format((float) $line->line_total, 2) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-4 flex flex-col gap-2 border-t border-divider pt-4 text-sm">
                <div class="flex justify-between">
                    <span class="text-text-muted">{{ __('Subtotal') }}</span>
                    <span class="text-text">{{ $invoice->currency }} {{ number_format((float) $invoice->subtotal, 2) }}</span>
                </div>

                @forelse ($invoice->taxLines as $line)
                    <div class="flex justify-between">
                        <span class="text-text-muted">
                            {{ $line->component_name }}
                            ({{ rtrim(rtrim(number_format((float) $line->rate_percent, 3), '0'), '.') }}%)
                        </span>
                        <span class="text-text">{{ $invoice->currency }} {{ number_format((float) $line->tax_amount, 2) }}</span>
                    </div>
                @empty
                    @if ($invoice->tax_note)
                        <p class="text-text-muted">{{ $invoice->tax_note }}</p>
                    @endif
                @endforelse

                <div class="flex justify-between border-t border-divider pt-2 font-semibold">
                    <span class="text-heading">{{ __('Total') }}</span>
                    <span class="text-heading">{{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}</span>
                </div>
            </div>
        </div>

        @if ($invoice->creditNotes->isNotEmpty())
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="mb-3 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Credit notes') }}</h2>
                <ul class="flex flex-col gap-2 text-sm">
                    @foreach ($invoice->creditNotes as $note)
                        <li class="flex flex-wrap justify-between gap-2">
                            <span class="text-text">{{ $note->number }} — {{ $note->reason }}</span>
                            <span class="font-medium text-text">{{ $note->currency }} {{ number_format((float) $note->amount, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <x-ui.button variant="secondary" :href="route('billing')">{{ __('Back to billing') }}</x-ui.button>
        </div>
    </div>
</x-layouts.app>
