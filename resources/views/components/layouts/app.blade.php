@props([
    'title' => null,
    'wide' => false,
    'page' => null,
])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @if(($mode = settings('ui.theme_mode')) !== 'system') data-theme="{{ $mode }}" @endif>
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover is required for env(safe-area-inset-*) to resolve
         on notched devices — Owner Addendum A --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Title, description, canonical and the social preview, all falling back
         through the page, its content, and branding. --}}
    <x-seo :page="$page" :title="$title" />
    {{-- Every one of these is administrator-replaceable (D-09), and every one
         falls back to the artwork that ships with Aziv AI. --}}
    <link rel="icon" href="{{ asset(brand('favicon')) }}">
    <link rel="apple-touch-icon" href="{{ asset(brand('app_icon')) }}">
    <link rel="manifest" href="{{ route('manifest') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- AFTER the stylesheet: the compiled block redefines the same custom
         properties tokens.css declares, and later wins. Nothing here is
         template-specific — the Admin Panel includes the identical component
         with scope="admin" (owner decision D-07). --}}
    <x-theme.styles scope="customer" />
</head>
<body class="min-h-dvh bg-background text-text antialiased">

<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded-md focus:bg-surface focus:px-4 focus:py-2 focus:text-text focus:shadow-lg">
    {{ __('Skip to content') }}
</a>

@auth
    {{-- The flex row must start at the SAME breakpoint the sidebar appears
         (md), or on tablet the rail stacks above the content instead of
         sitting beside it. --}}
    <div class="md:flex md:min-h-dvh">
        {{-- DESKTOP (>=1024): persistent labelled sidebar.
             TABLET  (768–1023): collapsed icon rail.
             MOBILE  (<768): hidden entirely; bottom nav + drawer take over. --}}
        <x-nav.sidebar />

        <div class="flex min-h-dvh w-full min-w-0 flex-col">
            <x-nav.topbar :title="$title" />

            <x-content.banner />

            <main id="main" class="flex-1 w-full mx-auto"
                  style="max-width: {{ $wide ? '100%' : '1200px' }};
                         padding-inline: var(--space-gutter);
                         padding-block: 1.5rem;
                         padding-bottom: calc(var(--nav-height-mobile) + var(--safe-bottom) + 1.5rem);">
                @if (session('status'))
                    <x-ui.alert variant="success" class="mb-5">{{ session('status') }}</x-ui.alert>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- MOBILE only: four destinations + More, above the safe-area inset. --}}
    <x-nav.bottom-bar />
    <x-nav.drawer />
@else
    <x-content.banner />

    <main id="main" class="mx-auto w-full"
          style="max-width: 1200px; padding-inline: var(--space-gutter); padding-block: var(--space-section);">
        @if (session('status'))
            <x-ui.alert variant="success" class="mb-5">{{ session('status') }}</x-ui.alert>
        @endif

        {{ $slot }}
    </main>

    {{-- Footer on the public side only: a signed-in customer has the sidebar
         and bottom bar, and a footer would sit under the phone nav. --}}
    <x-content.footer />
@endauth

</body>
</html>
