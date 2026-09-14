<x-install.layout title="Your site">
    <p style="color: var(--color-text-muted); margin-top: 0;">
        The address here is what every emailed link is built from — password resets, email
        verification and renewal payment links. It has to be the address your customers actually
        use, including <code>https://</code> if you have a certificate.
    </p>

    <form method="POST" action="{{ route('install.application.save') }}" style="margin-top: 1.25rem;">
        @csrf
        <x-install.field name="name" label="What is your platform called?" value="Aziv AI" />
        <x-install.field name="url" label="Site address" type="url" :value="$suggestedUrl"
                         help="Include https:// if you have a certificate. Getting this wrong makes every emailed link fail." />

        <div style="margin-bottom: 1rem;">
            <label for="timezone" style="display: block; font-size: 0.875rem; color: var(--color-text-muted); margin-bottom: 0.375rem;">
                Timezone
            </label>
            <select id="timezone" name="timezone" required
                    style="width: 100%; min-height: var(--tap-min); box-sizing: border-box; padding: 0.5rem 0.75rem;
                           font-size: max(16px, 1rem); border: 1px solid var(--color-input-border);
                           border-radius: var(--radius-md); background: var(--color-input-bg); color: var(--color-input-text);">
                @foreach ($timezones as $zone)
                    <option value="{{ $zone }}" @selected($zone === 'UTC')>{{ $zone }}</option>
                @endforeach
            </select>
            <p style="margin: 0.375rem 0 0; font-size: 0.8125rem; color: var(--color-text-muted);">
                Invoice dates and billing periods are calculated in this timezone.
            </p>
        </div>

        <x-install.field name="locale" label="Language code" value="en" help="Two letters, such as en." />

        <x-install.submit />
    </form>
</x-install.layout>
