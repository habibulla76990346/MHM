<x-layouts.app :title="__('Pricing')">
    {{-- Stacked on a phone, side by side when there is room. Addendum A: a
         pricing table that keeps its columns at 320px is unreadable, and a
         pricing page nobody can read is a pricing page nobody buys from. --}}
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-2xl);">{{ __('Plans') }}</h1>
            <p class="text-text-muted">{{ __('Prices are shown in :currency.', ['currency' => $currency->code]) }}</p>
        </div>

        @if ($plans->isEmpty())
            <div class="rounded-lg border border-border bg-surface p-5">
                <p class="text-text-muted">{{ __('No plans are on sale yet.') }}</p>
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($plans as $plan)
                    @php $price = $plan->priceIn($currency->code); @endphp

                    <div class="flex flex-col gap-4 rounded-lg border border-border bg-surface p-5">
                        <div class="flex flex-col gap-1">
                            <h2 class="font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ $plan->name }}</h2>
                            @if ($plan->description)
                                <p class="text-sm text-text-muted">{{ $plan->description }}</p>
                            @endif
                        </div>

                        <div class="flex items-baseline gap-2">
                            <span class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                                {{ $plan->is_free && ! $price ? __('Free') : $currency->format((float) $price->amount) }}
                            </span>

                            @if ($price && $plan->billing_cycle !== 'lifetime' && $plan->billing_cycle !== 'none')
                                <span class="text-sm text-text-muted">
                                    {{ $plan->billing_cycle === 'yearly' ? __('a year') : __('a month') }}
                                </span>
                            @endif
                        </div>

                        @if ($plan->credits_per_period > 0)
                            <p class="text-sm text-text-muted">
                                {{ __(':credits credits included each period', ['credits' => rtrim(rtrim(number_format((float) $plan->credits_per_period, 2), '0'), '.')]) }}
                            </p>
                        @endif

                        @if ($plan->highlights)
                            <ul class="flex flex-col gap-2 text-sm text-text">
                                @foreach (array_filter(array_map('trim', explode("\n", $plan->highlights))) as $line)
                                    <li class="flex gap-2">
                                        <span aria-hidden="true" class="text-text-muted">•</span>
                                        <span>{{ $line }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-auto">
                            @if ($currentPlanId === $plan->id)
                                <span class="inline-flex min-h-11 items-center rounded-lg border border-border px-4 text-sm text-text-muted">
                                    {{ __('Your current plan') }}
                                </span>
                            @elseif (auth()->check())
                                {{-- Checkout arrives with the payment gateways.
                                     Until then this is honest about what it can do. --}}
                                <x-ui.button :href="route('billing')">{{ __('Manage your plan') }}</x-ui.button>
                            @else
                                <x-ui.button :href="route('register')">{{ __('Get started') }}</x-ui.button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
