@php($nav = app(\App\Domains\Content\Services\NavigationService::class))
{{-- Secondary navigation. Mobile: slides in from the left. A bottom sheet is
     used for contextual actions in later phases; a full-height drawer suits a
     navigation list better. --}}
<div id="aziv-drawer" hidden data-drawer>
    <div class="fixed inset-0 z-40" style="background: var(--color-overlay);" data-drawer-close></div>

    <div class="fixed inset-y-0 left-0 z-50 flex w-[min(20rem,85vw)] flex-col border-r border-border bg-surface"
         style="padding-top: var(--safe-top); padding-bottom: var(--safe-bottom);"
         role="dialog" aria-modal="true" aria-label="{{ __('Menu') }}">

        <div class="flex items-center justify-between gap-3 border-b border-border px-4"
             style="height: var(--topbar-height);">
            <div class="flex min-w-0 items-center gap-3">
                <span class="text-primary shrink-0" style="width:26px;height:26px;">
                    {!! file_get_contents(public_path('brand/mark.svg')) !!}
                </span>
                <span class="truncate font-semibold text-heading">{{ settings('branding.app_name') }}</span>
            </div>
            <button type="button" data-drawer-close
                    class="inline-flex items-center justify-center rounded-md text-text-muted hover:bg-hover hover:text-text"
                    style="min-width: var(--tap-min); min-height: var(--tap-min);"
                    aria-label="{{ __('Close menu') }}">
                <x-ui.icon name="close" class="size-6" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto p-3" style="overscroll-behavior: contain;">
            @foreach (array_merge($nav->primary(), $nav->secondary()) as $item)
                @php($active = $nav->isActive($item))
                <a href="{{ $nav->href($item) }}"
                   @if($active) aria-current="page" @endif
                   class="flex items-center gap-3 rounded-md px-3 text-sm
                          {{ $active ? 'bg-active font-medium text-text' : 'text-text-muted hover:bg-hover hover:text-text' }}"
                   style="min-height: var(--tap-min);">
                    <x-ui.icon :name="$item['icon']" class="size-5 shrink-0" />
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>
</div>
