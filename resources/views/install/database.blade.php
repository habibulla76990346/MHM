<x-install.layout title="Your database">
    <p style="color: var(--color-text-muted); margin-top: 0;">
        Create an empty database and a user for it in your hosting control panel first, then enter the
        details here. Nothing is saved until the connection has been tested.
    </p>

    <form method="POST" action="{{ route('install.database.test') }}" style="margin-top: 1.25rem;">
        @csrf
        <x-install.field name="host" label="Database host" :value="$values['host'] ?? '127.0.0.1'"
                         help="On most shared hosting this is localhost." />
        <x-install.field name="port" label="Port" type="number" :value="$values['port'] ?? '3306'" />
        <x-install.field name="database" label="Database name" :value="$values['database'] ?? ''"
                         help="On cPanel this usually starts with your account name and an underscore." />
        <x-install.field name="username" label="Database user" :value="$values['username'] ?? ''" />
        {{-- Never repopulated from old input: a password echoed back into a
             page is a password in the browser's autofill, in the back button
             and in any proxy cache along the way. --}}
        <x-install.field name="password" label="Database password" type="password" :required="false"
                         autocomplete="new-password" help="Leave empty if the user has no password." />

        <x-install.submit label="Test and continue" />
    </form>
</x-install.layout>
