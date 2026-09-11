@props(['section'])
<section class="flex flex-col gap-4" style="padding-block: var(--space-section);">
    @if ($section->value('heading'))
        <h2 class="font-semibold text-heading" style="font-size: var(--text-fluid-2xl);">
            {{ $section->value('heading') }}
        </h2>
    @endif

    {{-- Split on blank lines and escape each paragraph. Page copy is never
         rendered as HTML: it is the one field an owner types freely, and the
         theme system owns how text looks. --}}
    @foreach (preg_split('/\R{2,}/', $section->value('body')) as $paragraph)
        @if (trim($paragraph) !== '')
            <p class="text-text break-anywhere" style="max-width: var(--content-max);">{{ trim($paragraph) }}</p>
        @endif
    @endforeach
</section>
