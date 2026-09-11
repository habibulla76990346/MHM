@props(['type' => 'submit', 'variant' => 'primary', 'full' => false, 'href' => null])
@php
    $style = match ($variant) {
        'secondary' => 'background: var(--color-button-secondary-bg); color: var(--color-button-secondary-text); border-color: var(--color-border);',
        default     => 'background: var(--color-primary); color: var(--color-text-inverse); border-color: var(--color-primary);',
    };

    $classes = 'inline-flex items-center justify-center gap-2 rounded-md border px-4 text-sm font-medium transition-opacity hover:opacity-90 '
        .($full ? 'w-full' : '');

    // An external link opens in a new tab, and carries the rel that stops the
    // opened page from reaching back into this one via window.opener.
    $external = is_string($href) && preg_match('#^https?://#i', $href)
        && ! str_starts_with($href, url('/'));
@endphp

@if ($href)
    {{-- A link, not a button, when it navigates. One definition either way, so
         a call-to-action in page content looks exactly like one in a form. --}}
    <a href="{{ $href }}"
       @if ($external) target="_blank" rel="noopener noreferrer" @endif
       {{ $attributes->merge(['class' => $classes]) }}
       style="min-height: var(--tap-min); {{ $style }}">
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}"
            {{ $attributes->merge(['class' => $classes]) }}
            style="min-height: var(--tap-min); {{ $style }}">
        {{ $slot }}
    </button>
@endif
