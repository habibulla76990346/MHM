<x-layouts.app :title="$heading">
    <div class="rounded-lg border border-border bg-surface p-6">
        <h2 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ $heading }}</h2>
        <p class="text-text-muted" style="max-width: var(--content-max);">
            {{ __('This arrives in Phase :phase. The navigation entry exists now so nothing links to a dead page.', ['phase' => $phase]) }}
        </p>
    </div>
</x-layouts.app>
