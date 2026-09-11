@php
    $content = app(\App\Domains\Content\Services\ContentService::class);
    $nav = app(\App\Domains\Content\Services\NavigationService::class);
    $pages = $content->footerPages();
    $links = $nav->footer();
    $support = settings('branding.support_email');
@endphp

<footer class="border-t border-border" style="margin-top: var(--space-section);">
    <div class="mx-auto flex w-full flex-col gap-4"
         style="max-width: 1200px; padding-inline: var(--space-gutter); padding-block: var(--space-section);">

        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-center gap-3">
                <x-brand.mark :size="28" class="text-primary" />
                <span class="font-semibold text-heading">{{ settings('branding.app_name') }}</span>
            </div>

            {{-- Wraps on a phone, single row on a desktop. Each link meets the
                 touch minimum, so a footer is as usable on a phone as a
                 button is. --}}
            <nav class="flex flex-wrap gap-x-5 gap-y-1" aria-label="{{ __('Footer') }}">
                @foreach ($pages as $page)
                    <a href="{{ url('/p/'.$page->slug) }}" class="aziv-footer-link">{{ $page->title }}</a>
                @endforeach

                @foreach ($links as $link)
                    <a href="{{ $nav->href($link) }}"
                       @if ($link['external'] ?? false) target="_blank" rel="noopener noreferrer" @endif
                       class="aziv-footer-link">{{ $link['label'] }}</a>
                @endforeach

                @if ($support)
                    <a href="mailto:{{ $support }}" class="aziv-footer-link">{{ __('Support') }}</a>
                @endif
            </nav>
        </div>

        <p class="text-sm text-text-muted">
            {{ settings('branding.footer_text') ?: '© '.date('Y').' '.settings('branding.app_name').'. '.__('All rights reserved.') }}
        </p>
    </div>
</footer>
