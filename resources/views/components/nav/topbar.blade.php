@props(['title' => null])
<header class="sticky top-0 z-30 flex items-center gap-3 border-b border-border bg-surface px-3 sm:px-4"
        style="height: calc(var(--topbar-height) + var(--safe-top)); padding-top: var(--safe-top);">

    {{-- Drawer trigger: mobile only. Tablet and desktop have the rail/sidebar. --}}
    <button type="button"
            class="md:hidden inline-flex items-center justify-center rounded-md text-text-muted hover:bg-hover hover:text-text"
            style="min-width: var(--tap-min); min-height: var(--tap-min);"
            aria-label="{{ __('Open menu') }}"
            aria-controls="aziv-drawer"
            aria-expanded="false"
            data-drawer-open>
        <x-ui.icon name="menu" class="size-6" />
    </button>

    <x-brand.mark :size="26" class="md:hidden text-primary" />

    <h1 class="min-w-0 flex-1 truncate font-semibold text-heading" style="font-size: var(--text-fluid-lg);">
        {{ $title ?? settings('branding.app_name') }}
    </h1>

    <form method="POST" action="{{ route('logout') }}" class="shrink-0">
        @csrf
        <button type="submit"
                class="inline-flex items-center justify-center gap-2 rounded-md px-3 text-sm text-text-muted hover:bg-hover hover:text-text"
                style="min-height: var(--tap-min); min-width: var(--tap-min);">
            <x-ui.icon name="logout" class="size-5" />
            <span class="hidden sm:inline">{{ __('Sign out') }}</span>
        </button>
    </form>
</header>
