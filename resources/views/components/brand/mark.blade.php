@props(['size' => 26])
@php
    $branding = brand();
    $customised = $branding->isCustomised('mark');
@endphp

@if ($customised)
    {{-- A replaced mark is a raster image: it cannot inherit currentColor, so
         it is rendered as an image and keeps its own colours. --}}
    <img src="{{ asset($branding->path('mark')) }}"
         alt="{{ settings('branding.app_name') }}"
         width="{{ $size }}" height="{{ $size }}"
         {{ $attributes->merge(['class' => 'shrink-0 object-contain']) }}
         style="width: {{ $size }}px; height: {{ $size }}px;">
@else
    {{-- The mark that ships with Aziv AI is a hand-drawn vector using
         currentColor, so it re-colours with the theme instead of being a fixed
         image. Inlined rather than linked so it cannot flash in unstyled. --}}
    <span {{ $attributes->merge(['class' => 'shrink-0']) }}
          style="width: {{ $size }}px; height: {{ $size }}px; display: inline-flex;"
          aria-hidden="true">
        {!! file_get_contents(public_path('brand/mark.svg')) !!}
    </span>
@endif
