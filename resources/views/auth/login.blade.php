<x-layouts.auth :title="__('Sign in')" :heading="__('Welcome back')"
                :subheading="__('Sign in to continue to :app', ['app' => settings('branding.app_name')])">
    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
        @csrf

        <x-ui.input name="email" :label="__('Email address')" type="email" required autofocus
                    autocomplete="username" inputmode="email" enterkeyhint="next" />

        <x-ui.input name="password" :label="__('Password')" type="password" required
                    autocomplete="current-password" enterkeyhint="go" />

        <label class="flex items-center gap-2 text-sm text-text-muted" style="min-height: var(--tap-min);">
            <input type="checkbox" name="remember" value="1" class="rounded border-border"
                   style="width:1.15rem;height:1.15rem;">
            {{ __('Keep me signed in') }}
        </label>

        <x-ui.button full>{{ __('Sign in') }}</x-ui.button>

        <a href="{{ route('password.request') }}"
           class="inline-flex items-center justify-center text-center text-sm"
           style="color: var(--color-link); min-height: var(--tap-min);">
            {{ __('Forgotten your password?') }}
        </a>
    </form>

    @if (settings('auth.registration_enabled'))
        <p class="mt-5 border-t border-divider pt-4 text-center text-sm text-text-muted">
            {{ __('New here?') }}
            <a href="{{ route('register') }}" style="color: var(--color-link);">{{ __('Create an account') }}</a>
        </p>
    @endif
</x-layouts.auth>
