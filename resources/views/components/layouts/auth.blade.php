@props(['title', 'heading', 'subheading' => null])
<x-layouts.app :title="$title">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6" style="padding-block: clamp(1rem, 6vh, 4rem);">
        <div class="flex flex-col items-center gap-3 text-center">
            <span class="text-primary" style="width:56px;height:56px;">
                {!! file_get_contents(public_path('brand/mark.svg')) !!}
            </span>
            <div>
                <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-2xl); line-height:1.15;">
                    {{ $heading }}
                </h1>
                @if ($subheading)
                    <p class="mt-1 text-text-muted" style="font-size: var(--text-fluid-sm);">{{ $subheading }}</p>
                @endif
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5 shadow-sm sm:p-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <p class="text-center text-sm text-text-muted">{{ $footer }}</p>
        @endisset
    </div>
</x-layouts.app>
