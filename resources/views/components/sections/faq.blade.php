@props(['section'])
@php
    $questions = app(\App\Domains\Content\Services\ContentService::class)
        ->faqs($section->value('category') ?: null);
@endphp

@if ($questions->isNotEmpty())
    <section class="flex flex-col gap-4" style="padding-block: var(--space-section);">
        @if ($section->value('heading'))
            <h2 class="font-semibold text-heading" style="font-size: var(--text-fluid-2xl);">
                {{ $section->value('heading') }}
            </h2>
        @endif

        {{-- <details> rather than JavaScript: it works before Alpine loads, it
             is keyboard accessible for free, and the whole summary row is the
             touch target. --}}
        <div class="flex flex-col gap-2">
            @foreach ($questions as $faq)
                <details class="aziv-faq rounded-lg border border-border bg-card">
                    <summary class="aziv-faq-summary">{{ $faq->question }}</summary>
                    <div class="border-t border-divider px-4 py-3">
                        @foreach (preg_split('/\R{2,}/', $faq->answer) as $paragraph)
                            @if (trim($paragraph) !== '')
                                <p class="text-sm text-text">{{ trim($paragraph) }}</p>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>
    </section>
@endif
