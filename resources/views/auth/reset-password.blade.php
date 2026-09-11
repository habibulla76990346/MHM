<x-layouts.auth :title="__('Choose a new password')" :heading="__('Choose a new password')">
    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-ui.input name="email" :label="__('Email address')" type="email" required
                    :value="$email" autocomplete="username" inputmode="email" enterkeyhint="next" />

        <x-ui.input name="password" :label="__('New password')" type="password" required autofocus
                    autocomplete="new-password" enterkeyhint="next" :hint="$passwordHint" />

        <x-ui.input name="password_confirmation" :label="__('Confirm new password')" type="password" required
                    autocomplete="new-password" enterkeyhint="go" />

        <x-ui.button full>{{ __('Change password') }}</x-ui.button>
    </form>
</x-layouts.auth>
