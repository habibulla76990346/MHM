<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @isset($themeMode) data-theme="{{ $themeMode }}" @endisset>
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is required for env(safe-area-inset-*) to resolve
         on notched devices — Owner Addendum A --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ $themeColor ?? '#0b1120' }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" href="{{ asset('brand/favicon-32.png') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('brand/favicon-16.png') }}" sizes="16x16">
    <link rel="apple-touch-icon" href="{{ asset('brand/app-icon-180.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-background text-text antialiased">
    {{-- The theme system injects its compiled token block here in Phase 2. --}}
    <main id="main" class="mx-auto w-full" style="max-width: 1200px; padding-inline: var(--space-gutter); padding-block: var(--space-section);">
        {{ $slot }}
    </main>
</body>
</html>
