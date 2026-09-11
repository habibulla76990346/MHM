@props(['variant' => 'info'])
@php
    $tone = match ($variant) {
        'success' => ['bg' => 'var(--color-success-bg)', 'fg' => 'var(--color-success)'],
        'warning' => ['bg' => 'var(--color-warning-bg)', 'fg' => 'var(--color-warning)'],
        'danger'  => ['bg' => 'var(--color-danger-bg)',  'fg' => 'var(--color-danger)'],
        default   => ['bg' => 'var(--color-info-bg)',    'fg' => 'var(--color-info)'],
    };
@endphp
<div role="status" {{ $attributes->merge(['class' => 'rounded-md border px-4 py-3']) }}
     style="background: {{ $tone['bg'] }}; color: {{ $tone['fg'] }}; border-color: currentColor;">
    {{ $slot }}
</div>
