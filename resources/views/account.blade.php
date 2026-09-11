<x-layouts.app :title="__('Account')">
    <div class="flex flex-col gap-5" style="max-width: 42rem;">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Your details') }}</h2>
            <dl class="flex flex-col gap-3">
                @foreach ([
                    __('Name') => auth()->user()->name,
                    __('Email') => auth()->user()->email,
                    __('Signed in from') => auth()->user()->last_login_ip ?? '—',
                    __('Active sessions') => auth()->user()->sessions()->whereNull('revoked_at')->count(),
                ] as $label => $value)
                    <div class="flex flex-wrap justify-between gap-2 border-b border-divider pb-3 last:border-0 last:pb-0">
                        <dt class="text-text-muted">{{ $label }}</dt>
                        <dd class="font-medium text-text break-anywhere">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>
</x-layouts.app>
