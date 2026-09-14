<x-layouts.auth :title="__('Two-factor authentication')" :heading="__('One more step')"
                :subheading="__('Enter the six-digit code from your authenticator app.')">
    <form method="POST" action="{{ route('mfa.verify') }}" class="flex flex-col gap-4">
        @csrf

        {{-- inputmode numeric and one-time-code autocomplete: on a phone this
             fills itself from the notification, which is the difference
             between a second factor people accept and one they switch off. --}}
        <x-ui.input name="code" :label="__('Authentication code')" required autofocus
                    autocomplete="one-time-code" inputmode="numeric" enterkeyhint="go"
                    pattern="[0-9A-Za-z\-]*" />

        <x-ui.button full>{{ __('Continue') }}</x-ui.button>
    </form>

    <p class="mt-5 border-t border-divider pt-4 text-sm text-text-muted">
        {{ __('Lost your phone? Enter one of your recovery codes above instead — each works once.') }}
    </p>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <button type="submit" class="inline-flex items-center text-sm"
                style="color: var(--color-link); min-height: var(--tap-min);">
            {{ __('Sign in as somebody else') }}
        </button>
    </form>
</x-layouts.auth>
