<x-layouts.app :title="__('Checkout')">
    {{-- One Aziv-branded page whichever shape the gateway uses: its modal
         opens over this, or the browser leaves from here and comes back to
         it. The customer's experience does not change when the owner switches
         gateway, which is the point of the whole adapter layer. --}}
    <div class="flex flex-col gap-5" style="max-width: 32rem;">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h1 class="mb-1 font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                {{ $plan->name }}
            </h1>
            <p class="text-text-muted">{{ $plan->description }}</p>

            <div class="mt-4 flex items-baseline justify-between border-t border-divider pt-4">
                <span class="text-text-muted">{{ __('Total') }}</span>
                <span class="font-semibold text-heading" style="font-size: var(--text-fluid-lg);">
                    {{ $currency }} {{ number_format((float) $payment->presentment_amount, 2) }}
                </span>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            @if ($session->leavesSite())
                <p class="mb-4 text-text-muted">
                    {{ __('You will be taken to our payment partner to pay securely, then brought straight back here.') }}
                </p>

                <form method="GET" action="{{ $session->redirectUrl }}">
                    <x-ui.button full>{{ __('Continue to payment') }}</x-ui.button>
                </form>
            @else
                <p class="mb-4 text-text-muted">
                    {{ __('Payment opens over this page. Your card details go straight to our payment partner — Aziv AI never sees them.') }}
                </p>

                {{-- Only the publishable identifier reaches the page. It tells
                     the gateway's script which merchant this is and authorises
                     nothing on its own; the secret is server-side and cannot
                     be rendered, because the model that holds it is $hidden. --}}
                <div id="checkout"
                     data-config="{{ json_encode($session->publicConfig) }}"
                     data-return="{{ route('checkout.return', $payment) }}"
                     data-status="{{ route('checkout.status', $payment) }}">
                    <x-ui.button full id="pay-now">{{ __('Pay now') }}</x-ui.button>
                </div>

                <noscript>
                    <p class="mt-3 text-sm text-text-muted">
                        {{ __('This payment method needs JavaScript. Please enable it, or contact support for a payment link.') }}
                    </p>
                </noscript>
            @endif
        </div>

        <p class="text-sm text-text-muted">
            {{ __('Nothing is charged until you confirm with your bank. If anything goes wrong, your payment is checked again automatically and you will not be charged twice.') }}
        </p>
    </div>
</x-layouts.app>
