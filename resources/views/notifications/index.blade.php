<x-layouts.app :title="__('Notifications')">
    <div class="flex flex-col gap-4" style="max-width: 44rem;">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">
                {{ __('Notifications') }}
            </h1>

            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.read') }}">
                    @csrf
                    <x-ui.button variant="secondary" type="submit">
                        {{ __('Mark all as read (:count)', ['count' => $unread]) }}
                    </x-ui.button>
                </form>
            @endif
        </div>

        @forelse ($notifications as $notification)
            @php($data = $notification->data)
            <div @class([
                    'rounded-lg border p-4',
                    'border-border bg-surface' => $notification->read_at !== null,
                    'border-primary bg-surface-raised' => $notification->read_at === null,
                ])>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium text-heading">{{ $data['subject'] ?? '' }}</span>
                    <span class="text-sm text-text-muted">{{ $notification->created_at->diffForHumans() }}</span>
                </div>

                {{-- Escaped, and printed as the text it is. The body came from
                     a template an administrator can edit, so treating it as
                     markup would let a link say one thing and go elsewhere. --}}
                <p class="mt-2 text-text-muted" style="white-space: pre-line;">{{ $data['body'] ?? '' }}</p>

                @if (! empty($data['url']))
                    <a class="mt-3 inline-flex items-center underline" href="{{ $data['url'] }}">
                        {{ __('Open') }}
                    </a>
                @endif
            </div>
        @empty
            <div class="rounded-lg border border-border bg-surface p-5 text-text-muted">
                {{ __('Nothing yet. Anything the platform needs to tell you will appear here.') }}
            </div>
        @endforelse

        {{ $notifications->links() }}
    </div>
</x-layouts.app>
