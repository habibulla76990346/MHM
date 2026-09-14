<x-install.layout title="Aziv AI is installed">
    <p style="margin-top: 0;">
        Your administrator account <strong>{{ $email }}</strong> is ready, and the installer has locked
        itself — every one of its pages now returns "not found".
    </p>

    <h2 style="font-size: var(--text-fluid-lg); font-weight: 600; color: var(--color-heading); margin: 1.5rem 0 0.5rem;">
        Do these four things next
    </h2>
    <ol style="padding-left: 1.25rem; line-height: 1.7;">
        <li>
            <strong>Back up your <code>APP_KEY</code></strong> from the <code>.env</code> file, somewhere that is
            not this server. Without it a database backup cannot be restored.
        </li>
        <li>
            <strong>Add the cron job</strong>, or renewals, reminders and payment reconciliation will never run:<br>
            <code style="font-size: 0.8125rem;">* * * * * cd {{ base_path() === '/' ? '/path/to/aziv' : '[your application folder]' }} && php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</code>
        </li>
        <li>
            <strong>Configure email</strong> in <code>.env</code>, then run
            <code>php artisan aziv:mail:test you@yourdomain.com</code> — or check Admin → System Health,
            which says whether mail is really being delivered.
        </li>
        <li>
            <strong>Open Admin → System Health</strong> and work through anything red.
        </li>
    </ol>

    @if ($details)
        <p style="font-size: 0.8125rem; color: var(--color-text-muted);">
            Installed {{ $details['installed_at'] ?? 'just now' }} · version {{ $details['version'] ?? 'unknown' }}
        </p>
    @endif

    <a href="{{ url('/admin') }}"
       style="display: inline-flex; align-items: center; justify-content: center; min-height: var(--tap-min);
              padding-inline: 1.25rem; border-radius: var(--radius-md); font-weight: 500;
              background: var(--color-primary); color: var(--color-text-inverse); text-decoration: none;">
        Sign in to the Admin Panel
    </a>
</x-install.layout>
