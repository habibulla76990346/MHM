@props(['section'])
<section class="flex flex-col gap-6" style="padding-block: var(--space-section);">
    @if ($section->value('heading'))
        <div class="flex flex-col gap-2">
            <h2 class="font-semibold text-heading" style="font-size: var(--text-fluid-2xl);">
                {{ $section->value('heading') }}
            </h2>
            @if ($section->value('subheading'))
                <p class="text-text-muted" style="max-width: var(--content-max);">{{ $section->value('subheading') }}</p>
            @endif
        </div>
    @endif

    {{-- One column on a phone, two on a tablet, three on a desktop. A grid
         that only ever shrank would be the "squeezed desktop" Addendum A
         rules out. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($section->items() as $item)
            <div class="flex flex-col gap-2 rounded-lg border border-border bg-card p-4">
                @if ($item['icon'])
                    <span class="text-primary"><x-ui.icon :name="$item['icon']" class="size-6" /></span>
                @endif
                <h3 class="font-medium text-heading">{{ $item['title'] }}</h3>
                <p class="text-sm text-text-muted">{{ $item['body'] }}</p>
            </div>
        @endforeach
    </div>
</section>
