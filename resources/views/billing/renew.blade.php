<x-layouts.app :title="__('Renew your subscription')">
    {{--
        Reached from a link in an email, by somebody who may not be signed in.

        SO IT SAYS AS LITTLE AS IT CAN. The plan, the amount, the invoice
        number and the date — the same four facts the email already carried.
        No name, no address, no account details: a forwarded email must not
        become a window into somebody's account.
    --}}
    <div class="flex flex-col gap-5" style="max-width: 32rem;">
        @if ($paid)
            <div class="rounded-lg border border-border bg-surface p-5">
                <h1 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                    {{ __('This invoice is paid') }}
                </h1>
                <p class="text-text-muted">
                    {{ __('Thank you — invoice :number is settled and your subscription has been renewed.', ['number' => $invoice?->number]) }}
                </p>
            </div>
        @else
            <div class="rounded-lg border border-border bg-surface p-5">
                <h1 class="mb-1 font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                    {{ __('Renew :plan', ['plan' => $plan?->name]) }}
                </h1>

                @if ($invoice)
                    <p class="text-text-muted">
                        {{ __('Invoice :number, issued :date.', [
                            'number' => $invoice->number,
                            'date' => optional($invoice->issued_at)->toFormattedDateString(),
                        ]) }}
                    </p>
                @endif

                <div class="mt-4 flex items-baseline justify-between border-t border-divider pt-4">
                    <span class="text-text-muted">{{ __('Total') }}</span>
                    <span class="font-semibold text-heading" style="font-size: var(--text-fluid-lg);">
                        {{ $payment->presentment_currency }} {{ number_format((float) $payment->presentment_amount, 2) }}
                    </span>
                </div>
            </div>

            @isset($error)
                <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
            @endisset

            <div class="rounded-lg border border-border bg-surface p-5">
                {{-- The action is this same signed URL: the signature is what
                     authorises the request, so it has to travel with it. --}}
                <form method="POST" action="{{ url()->full() }}">
                    @csrf
                    <x-ui.button full type="submit">{{ __('Pay now') }}</x-ui.button>
                </form>

                <p class="mt-3 text-sm text-text-muted">
                    {{ __('This link is yours alone and stops working once the invoice is paid.') }}
                </p>
            </div>
        @endif

        <p class="text-sm text-text-muted">
            {{ __('You can also sign in and pay from your billing page.') }}
            <a class="underline" href="{{ route('billing') }}">{{ __('Go to billing') }}</a>
        </p>
    </div>
</x-layouts.app>
