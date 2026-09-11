{{-- The homepage is CONTENT, not a template.
     It is the `home` page seeded into content_pages, so an owner edits it in
     Admin → Content like any other page. The hard-coded version this replaced
     could only be changed by a developer, which is exactly what blueprint §7
     exists to avoid.

     If that page has been deleted or unpublished, a minimal welcome is shown
     rather than a 404 — the front door of the product must always open. --}}
@php
    $page = app(\App\Domains\Content\Services\ContentService::class)->page('home');
@endphp

@if ($page)
    @include('content.page', ['page' => $page])
@else
    <x-layouts.app :title="settings('branding.app_name')">
        <div class="flex flex-col items-start gap-5" style="padding-block: var(--space-section);">
            <x-brand.mark :size="48" class="text-primary" />

            <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-3xl); line-height: 1.1;">
                {{ settings('branding.app_name') }}
            </h1>

            <p class="text-text-muted" style="font-size: var(--text-fluid-lg); max-width: var(--content-max);">
                {{ settings('branding.tagline') }}
            </p>

            <div class="flex flex-wrap gap-3">
                <x-ui.button :href="route('register')">{{ __('Get started') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('login')">{{ __('Sign in') }}</x-ui.button>
            </div>
        </div>
    </x-layouts.app>
@endif
