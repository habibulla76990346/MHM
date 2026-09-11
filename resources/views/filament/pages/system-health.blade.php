@php
    use App\Domains\Diagnostics\Support\Severity;
    use App\Domains\Diagnostics\Support\Status;
@endphp

<x-filament-panels::page>
    {{-- Summary. Status and severity are separate axes: a GREY item can still
         be High severity, and on shared hosting an expected limitation shows
         GREY or YELLOW rather than alarming RED. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Working',        $this->counts()['green'],  'success'],
            ['Limited',        $this->counts()['yellow'], 'warning'],
            ['Problems',       $this->counts()['red'],    'danger'],
            ['Not applicable', $this->counts()['grey'],   'gray'],
        ] as [$label, $count, $colour])
            <x-filament::section compact>
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-sm text-text-muted">{{ $label }}</span>
                    <x-filament::badge :color="$colour" size="lg">{{ $count }}</x-filament::badge>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section compact>
        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span class="text-text-muted">
                Environment: <strong class="text-heading">{{ $this->deploymentMode() }}</strong>
            </span>
            <span class="text-text-muted">Last checked {{ $this->ranAt() }}</span>
        </div>
    </x-filament::section>

    @foreach ($this->grouped() as $category => $results)
        <x-filament::section :heading="$category" collapsible>
            <div class="divide-y divide-divider">
                @foreach ($results as $result)
                    @php
                        $tone = match ($result->status) {
                            Status::Green => 'success',
                            Status::Yellow => 'warning',
                            Status::Red => 'danger',
                            Status::Grey => 'gray',
                        };
                    @endphp

                    <div class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0">
                        {{-- Field 1 title · 2 category · 3 severity · 5 responsibility --}}
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$tone">{{ ucfirst($result->status->value) }}</x-filament::badge>

                            <span class="font-medium text-heading">{{ $result->title }}</span>

                            @if ($result->severity !== Severity::Informational)
                                <x-filament::badge
                                    :color="match ($result->severity) {
                                        Severity::Critical => 'danger',
                                        Severity::High => 'warning',
                                        default => 'gray',
                                    }"
                                    size="sm">{{ $this->severityLabel($result->severity) }}</x-filament::badge>
                            @endif

                            <span class="text-xs text-text-muted">
                                {{ $result->responsibility->label() }}
                            </span>
                        </div>

                        {{-- Field 4: exact technical reason, already scrubbed of
                             anything secret-shaped at construction. --}}
                        @if ($result->technicalReason !== '')
                            <p class="text-sm text-text">{{ $result->technicalReason }}</p>
                        @endif

                        {{-- Field 6: plain language, actionable by a non-developer --}}
                        @if ($result->recommendedAction !== '')
                            <p class="text-sm text-heading">{{ $result->recommendedAction }}</p>
                        @endif

                        {{-- Field 7: the specific thing to change --}}
                        @if ($result->adminAction !== '')
                            <p class="text-sm">
                                <span class="font-medium text-heading">Do this:</span>
                                <span class="text-text">{{ $result->adminAction }}</span>
                            </p>
                        @endif

                        {{-- Field 8: when it is the host's problem, the exact
                             wording to send them. Safe to forward — no
                             credential can reach this text. --}}
                        @if ($result->requiresHostingSupport && $result->supportWording !== '')
                            <div class="rounded-lg bg-surface-raised p-3 text-sm">
                                <p class="mb-1 font-medium text-heading">
                                    Send this to your hosting provider:
                                </p>
                                <p class="text-text">“{{ $result->supportWording }}”</p>
                            </div>
                        @endif

                        {{-- Fields 9 & 11 --}}
                        <p class="text-xs text-text-muted">
                            {{ $result->key }}
                            @if ($result->durationMs > 0) · {{ (int) $result->durationMs }}ms @endif
                            @if ($result->logReference) · log {{ $result->logReference }} @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
