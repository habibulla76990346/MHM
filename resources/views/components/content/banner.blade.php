@php
    $banner = app(\App\Domains\Content\Services\BannerService::class)->current();
@endphp

@if ($banner)
    {{-- One banner at a time. Two stacked announcements push the page content
         below the fold on a phone, and Addendum A rules out treating mobile as
         a squeezed desktop. Priority decides which one wins. --}}
    <div class="aziv-banner aziv-banner-{{ $banner->variant }}" role="status">
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="font-medium">{{ $banner->title }}</span>
            @if ($banner->body)
                <span class="text-sm opacity-90">{{ $banner->body }}</span>
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            @if ($banner->cta_label && $banner->cta_url)
                <a href="{{ $banner->cta_url }}" class="aziv-banner-cta">{{ $banner->cta_label }}</a>
            @endif

            @if ($banner->is_dismissible)
                <form method="POST" action="{{ route('banners.dismiss', $banner->uuid) }}">
                    @csrf
                    <button type="submit" class="aziv-banner-dismiss" aria-label="{{ __('Dismiss this message') }}">
                        <x-ui.icon name="close" class="size-5" />
                    </button>
                </form>
            @endif
        </div>
    </div>
@endif
