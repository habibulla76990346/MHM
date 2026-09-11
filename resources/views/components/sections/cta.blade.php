@props(['section'])
<section style="padding-block: var(--space-section);">
    <div class="flex flex-col items-start gap-4 rounded-lg border border-border bg-surface-raised p-6 sm:p-8">
        @if ($section->value('heading'))
            <h2 class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                {{ $section->value('heading') }}
            </h2>
        @endif

        @if ($section->value('body'))
            <p class="text-text-muted" style="max-width: var(--content-max);">{{ $section->value('body') }}</p>
        @endif

        @if ($section->value('primary_label'))
            <x-ui.button :href="$section->url('primary_url')">{{ $section->value('primary_label') }}</x-ui.button>
        @endif
    </div>
</section>
