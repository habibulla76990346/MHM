<x-layouts.auth :title="__('Confirm your email')" :heading="__('Confirm your email')"
                :subheading="__('We sent a link to :email', ['email' => auth()->user()?->email])">
    <div class="flex flex-col gap-4">
        <p class="text-sm text-text-muted">
            {{ __('Open the link in that email to finish setting up your account. If it has not arrived, check your spam folder or send another.') }}
        </p>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-ui.button full>{{ __('Send another link') }}</x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button full type="submit" variant="secondary">{{ __('Sign out') }}</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
