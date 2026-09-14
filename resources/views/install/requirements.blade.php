<x-install.layout title="What this server can do">
    <p style="color: var(--color-text-muted); margin-top: 0;">
        These are the same checks the System Health screen runs after installation.
        Anything red has to be fixed before Aziv AI will work; everything else can wait.
    </p>

    @if ($insecure)
        <div role="alert" style="border: 1px solid var(--color-warning); background: var(--color-warning-bg);
                    color: var(--color-warning); border-radius: var(--radius-md); padding: 0.75rem 1rem; margin: 1rem 0;">
            You are running this over plain HTTP. Everything you type here — including your database
            password and the administrator password — will travel unencrypted. Use HTTPS if you can.
        </div>
    @endif

    <ul style="list-style: none; padding: 0; margin: 1.25rem 0;">
        @foreach ($results as $result)
            <li style="border-top: 1px solid var(--color-divider); padding: 0.875rem 0;">
                <p style="margin: 0; font-weight: 500; color: var(--color-heading);">
                    <span style="color: {{ $result->status->value === 'red' ? 'var(--color-danger)' : ($result->status->value === 'yellow' ? 'var(--color-warning)' : 'var(--color-success)') }};">●</span>
                    {{ $result->title }}
                </p>
                <p style="margin: 0.25rem 0 0; font-size: 0.875rem; color: var(--color-text-muted);">
                    {{ $result->technicalReason }}
                </p>
                @if ($result->adminAction && $result->status->value !== 'green')
                    <p style="margin: 0.25rem 0 0; font-size: 0.875rem;">{{ $result->adminAction }}</p>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($blocked)
        <p style="color: var(--color-danger); font-weight: 500;">
            Fix the red items above, then reload this page.
        </p>
    @else
        <a href="{{ route('install.database') }}"
           style="display: inline-flex; align-items: center; justify-content: center; min-height: var(--tap-min);
                  padding-inline: 1.25rem; border-radius: var(--radius-md); font-weight: 500;
                  background: var(--color-primary); color: var(--color-text-inverse); text-decoration: none;">
            Continue
        </a>
    @endif
</x-install.layout>
