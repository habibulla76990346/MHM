<x-layouts.auth :title="__('Reset password')" :heading="__('Reset your password')"
                :subheading="__('We will email you a link to choose a new one.')">
    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
        @csrf
        <x-ui.input name="email" :label="__('Email address')" type="email" required autofocus
                    autocomplete="email" inputmode="email" enterkeyhint="send" />
        <x-ui.button full>{{ __('Email me a reset link') }}</x-ui.button>
        <a href="{{ route('login') }}" class="inline-flex items-center justify-center text-center text-sm"
           style="color: var(--color-link); min-height: var(--tap-min);">
            {{ __('Back to sign in') }}
        </a>
    </form>
</x-layouts.auth>
