@php($nav = app(\App\Domains\Content\Services\NavigationService::class))
{{--
  MOBILE ONLY (<768px). Decision D-11: Chat · Library · Images · Account,
  everything else behind More.

  Behaviour rules from docs/11-responsive-design-system.md §3:
   - sits above env(safe-area-inset-bottom) so the home indicator never
     overlaps a tab target
   - every tab is at least 44x44
   - hidden while the chat composer is focused, so the bar never sits between
     the keyboard and the input (wired in Phase 4 via .composer-focused)
   - the page reserves its height, so the last row of content is never
     permanently hidden behind it
--}}
<nav class="md:hidden fixed inset-x-0 bottom-0 z-40 border-t border-border bg-surface"
     style="padding-bottom: var(--safe-bottom);"
     data-bottom-bar
     aria-label="{{ __('Primary') }}">
    <ul class="grid" style="grid-template-columns: repeat({{ count($nav->bottomBar()) + 1 }}, minmax(0, 1fr));">
        @foreach ($nav->bottomBar() as $item)
            @php($active = $nav->isActive($item))
            <li>
                <a href="{{ $nav->href($item) }}"
                   @if($active) aria-current="page" @endif
                   class="flex flex-col items-center justify-center gap-1 px-1 py-1
                          {{ $active ? 'text-primary' : 'text-text-muted' }}"
                   style="min-height: var(--nav-height-mobile);">
                    <x-ui.icon :name="$item['icon']" class="size-6" />
                    <span style="font-size: 0.75rem; line-height: 1;">{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
        <li>
            <button type="button"
                    class="flex w-full flex-col items-center justify-center gap-1 px-1 py-1 text-text-muted"
                    style="min-height: var(--nav-height-mobile);"
                    aria-controls="aziv-drawer"
                    aria-expanded="false"
                    data-drawer-open>
                <x-ui.icon name="more" class="size-6" />
                <span style="font-size: 0.75rem; line-height: 1;">{{ __('More') }}</span>
            </button>
        </li>
    </ul>
</nav>
