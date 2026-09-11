<x-layouts.auth :title="__('Create account')" :heading="__('Create your account')"
                :subheading="settings('branding.tagline')">
    <form method="POST" action="{{ route('register') }}" class="flex flex-col gap-4">
        @csrf

        <x-ui.input name="name" :label="__('Your name')" required autofocus
                    autocomplete="name" enterkeyhint="next" />

        <x-ui.input name="email" :label="__('Email address')" type="email" required
                    autocomplete="email" inputmode="email" enterkeyhint="next" />

        <x-ui.input name="password" :label="__('Password')" type="password" required
                    autocomplete="new-password" enterkeyhint="next" :hint="$passwordHint" />

        <x-ui.input name="password_confirmation" :label="__('Confirm password')" type="password" required
                    autocomplete="new-password" enterkeyhint="go" />

        <x-ui.button full>{{ __('Create account') }}</x-ui.button>
    </form>

    <p class="mt-5 border-t border-divider pt-4 text-center text-sm text-text-muted">
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" style="color: var(--color-link);">{{ __('Sign in') }}</a>
    </p>
</x-layouts.auth>
