@props(['section'])
<section class="flex flex-col items-start gap-5" style="padding-block: var(--space-section);">
    @if ($section->value('heading'))
        <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-3xl); line-height: 1.1;">
            {{ $section->value('heading') }}
        </h1>
    @endif

    @if ($section->value('subheading'))
        <p class="text-text-muted" style="font-size: var(--text-fluid-lg); max-width: var(--content-max);">
            {{ $section->value('subheading') }}
        </p>
    @endif

    {{-- Wraps rather than scrolls, and each button meets the touch minimum at
         every width — Owner Addendum A applies to every user-facing page. --}}
    <div class="flex flex-wrap gap-3">
        @if ($section->value('primary_label'))
            <x-ui.button :href="$section->url('primary_url')">{{ $section->value('primary_label') }}</x-ui.button>
        @endif

        @if ($section->value('secondary_label'))
            <x-ui.button variant="secondary" :href="$section->url('secondary_url')">
                {{ $section->value('secondary_label') }}
            </x-ui.button>
        @endif
    </div>
</section>
