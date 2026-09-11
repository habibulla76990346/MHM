@props(['name' => 'dot', 'class' => 'size-5'])
@php
    $paths = [
        'chat'    => '<path d="M8 10h8M8 14h5M21 12a8 8 0 0 1-8 8H7l-4 3v-5.2A8 8 0 0 1 13 4a8 8 0 0 1 8 8Z"/>',
        'library' => '<path d="M4 5v14M9 5v14M14 6l5 13M4 5h5M14 6l-2 .7"/>',
        'image'   => '<path d="M3 5h18v14H3zM3 16l5-5 4 4 3-3 6 6"/><circle cx="8.5" cy="9" r="1.5"/>',
        'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'shield'  => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'more'    => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
        'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'dot'     => '<circle cx="12" cy="12" r="3"/>',
    ];
@endphp
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false">
    {!! $paths[$name] ?? $paths['dot'] !!}
</svg>
