@php($nav = app(\App\Domains\Content\Services\NavigationService::class))
{{--
  Hidden below 768px (mobile uses the bottom bar + drawer).
  768–1023: collapsed icon rail — labels hidden, 44px targets preserved.
  >=1024: full labelled sidebar.
--}}
<aside class="hidden md:flex md:w-16 lg:w-60 md:flex-col md:shrink-0 md:sticky md:top-0 md:h-dvh border-r border-border bg-surface"
       aria-label="{{ __('Main navigation') }}">
    <div class="flex items-center gap-3 border-b border-border px-3 lg:px-4"
         style="height: var(--topbar-height);">
        <x-brand.mark :size="28" class="text-primary" />
        <span class="hidden lg:block truncate font-semibold text-heading">{{ settings('branding.app_name') }}</span>
    </div>

    <nav class="flex flex-1 flex-col gap-1 p-2 lg:p-3">
        @foreach ($nav->primary() as $item)
            @php($active = $nav->isActive($item))
            <a href="{{ $nav->href($item) }}"
               @if($active) aria-current="page" @endif
               class="flex items-center gap-3 rounded-md px-3 text-sm transition-colors
                      {{ $active ? 'bg-active font-medium text-text' : 'text-text-muted hover:bg-hover hover:text-text' }}"
               style="min-height: var(--tap-min);"
               title="{{ $item['label'] }}">
                <x-ui.icon :name="$item['icon']" class="size-5 shrink-0" />
                <span class="hidden lg:inline">{{ $item['label'] }}</span>
            </a>
        @endforeach

        @if ($secondary = $nav->secondary())
            <hr class="my-2 border-divider">
            @foreach ($secondary as $item)
                <a href="{{ $nav->href($item) }}"
                   class="flex items-center gap-3 rounded-md px-3 text-sm text-text-muted transition-colors hover:bg-hover hover:text-text"
                   style="min-height: var(--tap-min);"
                   title="{{ $item['label'] }}">
                    <x-ui.icon :name="$item['icon']" class="size-5 shrink-0" />
                    <span class="hidden lg:inline">{{ $item['label'] }}</span>
                </a>
            @endforeach
        @endif
    </nav>
</aside>
