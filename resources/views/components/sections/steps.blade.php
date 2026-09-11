@props(['section'])
<section class="flex flex-col gap-6" style="padding-block: var(--space-section);">
    @if ($section->value('heading'))
        <h2 class="font-semibold text-heading" style="font-size: var(--text-fluid-2xl);">
            {{ $section->value('heading') }}
        </h2>
    @endif

    <ol class="flex flex-col gap-4">
        @foreach ($section->items() as $index => $item)
            <li class="flex items-start gap-4">
                <span class="aziv-step-number shrink-0">{{ $index + 1 }}</span>
                <div class="flex min-w-0 flex-col gap-1">
                    <h3 class="font-medium text-heading">{{ $item['title'] }}</h3>
                    <p class="text-sm text-text-muted">{{ $item['body'] }}</p>
                </div>
            </li>
        @endforeach
    </ol>
</section>
