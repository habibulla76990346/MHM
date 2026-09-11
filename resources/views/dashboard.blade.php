<x-layouts.app :title="__('Chat')">
    <div class="flex flex-col gap-5">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">
                {{ __('Welcome, :name', ['name' => auth()->user()->name]) }}
            </h2>
            <p class="text-text-muted" style="max-width: var(--content-max);">
                {{ __('Your account is ready. AI chat arrives in Phase 4 — the foundation it runs on is what Phase 1 builds.') }}
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Account', auth()->user()->email],
                ['Role', auth()->user()->getRoleNames()->first() ?? __('Customer')],
                ['Status', ucfirst(auth()->user()->status)],
            ] as [$label, $value])
                <div class="rounded-md border border-border bg-surface p-4">
                    <p class="text-text-muted" style="font-size:.75rem;letter-spacing:.06em;text-transform:uppercase;">{{ $label }}</p>
                    <p class="mt-1 font-medium text-text break-anywhere">{{ $value }}</p>
                </div>
            @endforeach
        </div>
    </div>
</x-layouts.app>
