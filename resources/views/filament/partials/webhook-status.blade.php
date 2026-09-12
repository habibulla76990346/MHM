<div class="flex flex-col gap-3 text-sm">
    <div class="flex flex-col gap-1">
        <span class="text-text-muted">Paste this into the gateway's dashboard:</span>
        <code class="break-all rounded-md border border-divider px-2 py-1 text-heading">{{ $gateway->webhookUrl() }}</code>
    </div>

    @unless ($hasSecret)
        <p class="text-heading">
            No signing secret is set for {{ $gateway->mode }} mode, so every notification will be rejected.
            Payments will still be credited by the reconciliation sweep, but minutes late instead of instantly.
        </p>
    @endunless

    <div class="flex flex-col gap-1">
        <span class="text-text-muted">Last delivery</span>
        <span class="text-heading">
            @if ($last)
                {{ $last->event_type ?: 'event' }} — {{ $last->received_at?->diffForHumans() }}
                ({{ $last->signature_valid ? 'accepted' : 'rejected' }})
            @else
                Nothing has arrived yet.
            @endif
        </span>
    </div>

    @if ($rejected > 0)
        <p class="text-heading">
            {{ $rejected }} delivery(ies) rejected in the last day. That is either the wrong signing secret,
            or something posting to this URL that is not the gateway.
        </p>
    @endif
</div>
