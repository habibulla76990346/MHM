<x-layouts.app :title="__('Billing')">
    <div class="flex flex-col gap-5" style="max-width: 48rem;">
        @if (session('status'))
            <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
        @endif

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Your plan') }}</h2>

            @if ($plan)
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium text-text">{{ $plan->name }}</span>
                    @if ($subscription?->current_period_end)
                        <span class="text-sm text-text-muted">
                            {{ $subscription->status === 'cancelled'
                                ? __('Ends :date', ['date' => $subscription->current_period_end->format('j M Y')])
                                : __('Renews :date', ['date' => $subscription->current_period_end->format('j M Y')]) }}
                        </span>
                    @endif
                </div>

                @if ($subscription?->pending_plan_id)
                    <p class="mt-3 text-sm text-text-muted">
                        {{ __('Changing to :plan on :date. You keep everything you have paid for until then.', [
                            'plan' => $subscription->pendingPlan?->name,
                            'date' => $subscription->pending_plan_starts_at?->format('j M Y'),
                        ]) }}
                    </p>
                @endif
            @else
                <p class="text-text-muted">{{ __('You are not on a plan.') }}</p>
            @endif

            <div class="mt-4">
                <x-ui.button variant="secondary" :href="route('pricing')">{{ __('See the plans') }}</x-ui.button>
            </div>
        </div>

        @if ($metering)
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="mb-4 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Credits') }}</h2>

                <div class="flex flex-wrap gap-6">
                    <div class="flex flex-col gap-1">
                        <span class="text-sm text-text-muted">{{ __('Available') }}</span>
                        <span class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                            {{ number_format($balance->spendable(), 2) }}
                        </span>
                    </div>

                    @if ((float) $balance->held_balance > 0)
                        <div class="flex flex-col gap-1">
                            <span class="text-sm text-text-muted">{{ __('Reserved for replies in progress') }}</span>
                            <span class="font-medium text-text">{{ number_format((float) $balance->held_balance, 2) }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="mb-4 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Where your credits went') }}</h2>

                @if ($history->isEmpty())
                    <p class="text-text-muted">{{ __('Nothing yet.') }}</p>
                @else
                    <ul class="flex flex-col gap-3">
                        @foreach ($history as $entry)
                            <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-divider pb-3 last:border-0 last:pb-0">
                                <span class="text-text">{{ $entry->reason }}</span>
                                <span class="flex items-baseline gap-3">
                                    <span class="text-sm text-text-muted">{{ $entry->created_at?->format('j M Y') }}</span>
                                    <span class="font-medium text-text">{{ number_format((float) $entry->amount, 2) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Invoices') }}</h2>

            @if ($invoices->isEmpty())
                <p class="text-text-muted">{{ __('No invoices yet.') }}</p>
            @else
                <ul class="flex flex-col gap-3">
                    @foreach ($invoices as $invoice)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 border-b border-divider pb-3 last:border-0 last:pb-0">
                            <a href="{{ route('billing.invoice', $invoice) }}"
                               class="inline-flex min-h-11 items-center font-medium text-link underline">
                                {{ $invoice->number }}
                            </a>
                            <span class="flex items-baseline gap-3">
                                <span class="text-sm text-text-muted">{{ $invoice->issued_at?->format('j M Y') }}</span>
                                <span class="font-medium text-text">
                                    {{ $invoice->currency }} {{ number_format((float) $invoice->total, 2) }}
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <form method="POST" action="{{ route('billing.profile') }}" class="rounded-lg border border-border bg-surface p-5">
            @csrf
            <h2 class="mb-1 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Billing details') }}</h2>
            <p class="mb-4 text-sm text-text-muted">
                {{ __('Copied onto invoices when they are issued. Changing them here never alters an invoice you already have.') }}
            </p>

            <div class="flex flex-col gap-4">
                <x-ui.input name="billing_name" :label="__('Name or company')" :value="old('billing_name', $profile->billing_name)" autocomplete="organization" />

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-text">{{ __('Address') }}</span>
                    <textarea name="billing_address" rows="3" autocomplete="street-address"
                              class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text"
                              style="min-height: var(--tap-min); font-size: max(16px, 1rem);">{{ old('billing_address', $profile->billing_address) }}</textarea>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-text">{{ __('Country') }}</span>
                    <select name="country" autocomplete="country"
                            class="w-full rounded-md border border-border bg-surface px-3 text-text"
                            style="min-height: var(--tap-min); font-size: max(16px, 1rem);">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country->code }}" @selected(old('country', $profile->country) === $country->code)>
                                {{ $country->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <x-ui.input name="state" :label="__('State or region')" :value="old('state', $profile->state)" autocomplete="address-level1" />
                <x-ui.input name="postal_code" :label="__('Postal code')" :value="old('postal_code', $profile->postal_code)" autocomplete="postal-code" />
                <x-ui.input name="tax_registration_number" :label="__('Tax registration number (optional)')" :value="old('tax_registration_number', $profile->tax_registration_number)" />

                {{-- The LABEL is the touch target: tapping anywhere in it
                     toggles the box, so it is what has to meet the 44px
                     minimum, not the 20px box itself. --}}
                <label class="flex items-center gap-3" style="min-height: var(--tap-min);">
                    <input type="checkbox" name="is_business" value="1" @checked(old('is_business', $profile->is_business))
                           class="rounded border-border" style="width:1.15rem;height:1.15rem;">
                    <span class="text-text">{{ __('This is a business account') }}</span>
                </label>

                <div>
                    <x-ui.button>{{ __('Save billing details') }}</x-ui.button>
                </div>
            </div>
        </form>
    </div>
</x-layouts.app>
