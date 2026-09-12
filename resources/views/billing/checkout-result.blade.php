<x-layouts.app :title="__('Payment')">
    {{-- This page decides NOTHING. It shows what the server established by
         asking the gateway; a URL claiming success is not evidence. --}}
    <div class="flex flex-col gap-5" style="max-width: 32rem;">
        <div class="rounded-lg border border-border bg-surface p-5">
            @if ($payment->isPaid())
                <h1 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                    {{ __('Payment received') }}
                </h1>
                <p class="text-text-muted">
                    {{ __('Thank you. Your plan is active and your credits are available now.') }}
                </p>

                @if ($payment->invoice?->number)
                    <p class="mt-3 text-sm text-text-muted">
                        {{ __('Invoice :number', ['number' => $payment->invoice->number]) }}
                    </p>
                @endif
            @elseif ($payment->status === \App\Domains\Payments\Models\Payment::STATUS_FAILED)
                <h1 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                    {{ __('That payment did not go through') }}
                </h1>
                <p class="text-text-muted">
                    {{ __('Nothing has been charged. You can try again, or use a different method.') }}
                </p>
            @else
                <h1 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                    {{ __('Checking with your bank') }}
                </h1>
                <p class="text-text-muted">
                    {{ __('This can take a moment. You can safely leave this page — if the payment completes, your plan is activated automatically and you will not be charged twice.') }}
                </p>

                <div class="mt-4"
                     data-poll="{{ route('checkout.status', $payment) }}"
                     id="payment-status"></div>
            @endif
        </div>

        <div class="flex flex-wrap gap-3">
            <x-ui.button :href="route('billing')">{{ __('Go to billing') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('dashboard')">{{ __('Back to chat') }}</x-ui.button>
        </div>
    </div>
</x-layouts.app>
