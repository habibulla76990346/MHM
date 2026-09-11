@props(['scope' => 'customer'])
@php
    $themes = app(\App\Domains\Theming\Services\ThemeService::class);
    $css = $themes->compiledCss($scope);
@endphp
{{-- Two theme-color tags rather than one: an installed PWA paints the system
     chrome with this, and a single value leaves a light bar above a dark app. --}}
<meta name="theme-color" content="{{ $themes->themeColor('light', $scope) }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="{{ $themes->themeColor('dark', $scope) }}" media="(prefers-color-scheme: dark)">
@if ($css !== '')
    {{-- Unescaped by necessity — this IS a stylesheet. Every value reaching it
         has been through ThemeService::safeValue() or CssSanitiser, both of
         which strip the angle brackets and braces that would be needed to
         close the element and start something else. --}}
    <style id="aziv-theme">{!! $css !!}</style>
@endif
