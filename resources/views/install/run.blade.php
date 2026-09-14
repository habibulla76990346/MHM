<x-install.layout title="Set up the database">
    <p style="color: var(--color-text-muted); margin-top: 0;">
        This creates the tables Aziv AI needs and generates the encryption key that protects every
        credential you will store later.
    </p>

    <div style="border: 1px solid var(--color-warning); background: var(--color-warning-bg); color: var(--color-warning);
                border-radius: var(--radius-md); padding: 0.75rem 1rem; margin: 1rem 0;">
        <strong>Back up your application key once this finishes.</strong>
        It lives in the <code>.env</code> file as <code>APP_KEY</code>. Every provider key and payment
        credential you enter is encrypted with it — restore a database beside a different key and none
        of them can ever be read again.
    </div>

    <form method="POST" action="{{ route('install.run.execute') }}" style="margin-top: 1.25rem;">
        @csrf
        <x-install.submit label="Create the tables" />
        <p style="margin: 0.75rem 0 0; font-size: 0.8125rem; color: var(--color-text-muted);">
            This can take up to a minute on shared hosting. Do not reload the page.
        </p>
    </form>
</x-install.layout>
