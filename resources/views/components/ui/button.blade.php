@props(['type' => 'submit', 'variant' => 'primary', 'full' => false])
@php
    $style = match ($variant) {
        'secondary' => 'background: var(--color-surface); color: var(--color-text); border-color: var(--color-border);',
        default     => 'background: var(--color-primary); color: var(--color-text-inverse); border-color: var(--color-primary);',
    };
@endphp
<button type="{{ $type }}"
        {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-2 rounded-md border px-4 text-sm font-medium transition-opacity hover:opacity-90 '.($full ? 'w-full' : '')]) }}
        style="min-height: var(--tap-min); {{ $style }}">
    {{ $slot }}
</button>
