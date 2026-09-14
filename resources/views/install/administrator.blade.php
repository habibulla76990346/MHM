<x-install.layout title="Your administrator account">
    <p style="color: var(--color-text-muted); margin-top: 0;">
        This is the account you will manage everything with. It is never created automatically and
        there is no default password — this form is the only place it happens.
    </p>

    <form method="POST" action="{{ route('install.administrator.create') }}" style="margin-top: 1.25rem;">
        @csrf
        <x-install.field name="name" label="Your name" />
        <x-install.field name="email" label="Email address" type="email" autocomplete="username" />
        <x-install.field name="password" label="Password" type="password" autocomplete="new-password"
                         help="At least 12 characters, with letters, numbers and symbols." />
        <x-install.field name="password_confirmation" label="Password again" type="password" autocomplete="new-password" />

        <x-install.submit label="Create my account and finish" />
    </form>
</x-install.layout>
