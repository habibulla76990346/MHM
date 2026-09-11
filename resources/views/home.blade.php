<x-layouts.app :title="config('app.name')">
    <header class="flex flex-col gap-6">
        <div class="flex items-center gap-4">
            <picture>
                <source srcset="{{ asset('brand/logo-dark-bg.png') }}" media="(prefers-color-scheme: dark)">
                <img src="{{ asset('brand/logo-light-bg.png') }}" alt="{{ config('app.name') }}"
                     class="h-12 w-auto" width="48" height="48">
            </picture>
            <div>
                <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-2xl); line-height:1.15;">
                    {{ config('app.name') }}
                </h1>
                <p class="text-text-muted" style="font-size: var(--text-fluid-sm);">
                    Multi-provider AI platform
                </p>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5 shadow-sm">
            <h2 class="mb-3 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">
                Phase&nbsp;0 — foundation
            </h2>
            <p class="mb-4 text-text-muted" style="max-width: var(--content-max);">
                The application boots, connects to the database, compiles assets and runs its
                test suite. Features begin in Phase&nbsp;1.
            </p>
            <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'Laravel'  => app()->version(),
                    'PHP'      => PHP_VERSION,
                    'Database' => config('database.default'),
                    'Mode'     => config('aziv.deployment_mode'),
                ] as $label => $value)
                    <div class="rounded-md border border-border bg-background p-3">
                        <dt class="text-text-muted" style="font-size: 0.75rem; letter-spacing:.06em; text-transform:uppercase;">{{ $label }}</dt>
                        <dd class="mt-1 font-medium text-text break-anywhere">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </header>
</x-layouts.app>
