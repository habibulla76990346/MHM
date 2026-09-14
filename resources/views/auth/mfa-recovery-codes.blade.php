<x-layouts.app :title="__('Your recovery codes')">
    <div class="flex flex-col gap-5" style="max-width: 34rem;">
        <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
            {{ $regenerated ? __('Your new recovery codes') : __('Two-factor authentication is on') }}
        </h1>

        <x-ui.alert variant="warning">
            {{ __('This is the only time these are shown. Aziv AI stores a one-way hash of each, so nobody — including you — can read them back.') }}
        </x-ui.alert>

        <div class="rounded-lg border border-border bg-surface p-5">
            <p class="mb-3 text-sm text-text-muted">
                {{ __('Each code works once. Keep them somewhere that is not this server and not your phone.') }}
            </p>
            <ul class="grid gap-2" style="grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); list-style: none; padding: 0;">
                @foreach ($codes as $code)
                    <li><code class="block rounded-md border border-border p-2 text-center text-sm"
                              style="background: var(--color-surface-alt);">{{ $code }}</code></li>
                @endforeach
            </ul>
        </div>

        <div>
            <x-ui.button :href="route('account')">{{ __('I have saved them') }}</x-ui.button>
        </div>
    </div>
</x-layouts.app>
