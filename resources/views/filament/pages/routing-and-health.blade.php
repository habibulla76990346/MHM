<x-filament-panels::page>
    <x-filament::section compact>
        <p class="text-sm text-text-muted">
            Health is measured from real customer traffic over the last
            <strong class="text-heading">{{ $this->healthWindowHours() }} hours</strong>.
            {{ $this->fallbackRate() }}% of requests in the last day needed a second provider.
        </p>
    </x-filament::section>

    {{-- Not collapsed by default: the six-viewport gate only measures what is
         visible, so a form hidden behind a toggle is a form nothing checks. --}}
    <x-filament::section heading="Defaults" collapsible>
        <p class="mb-4 text-sm text-text-muted">
            How Aziv AI chooses a model when a conversation has not chosen for itself,
            and how hard it tries before giving up.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($this->editableSettings() as $key => $definition)
                @php $field = \App\Filament\Pages\RoutingAndHealth::wireKey($key); @endphp

                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium text-heading" for="{{ $field }}">
                        {{ $definition->label ?: $key }}
                    </label>

                    @if ($key === 'routing.default_mode')
                        <select
                            id="{{ $field }}"
                            wire:model="defaults.{{ $field }}"
                            @disabled(! $this->canWrite())
                            class="fi-input fi-select-input w-full"
                        >
                            @foreach ($this->modeOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        {{-- fi-input carries the shared 16px sizing and 44px
                             minimum height, so a number field here cannot
                             drift away from every other input in the panel. --}}
                        <input
                            id="{{ $field }}"
                            type="{{ $definition->type === 'int' ? 'number' : 'text' }}"
                            wire:model="defaults.{{ $field }}"
                            @disabled(! $this->canWrite())
                            class="fi-input w-full"
                        >
                    @endif

                    @if ($definition->description)
                        <span class="text-xs text-text-muted">{{ $definition->description }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($this->canWrite())
            <div class="mt-4">
                <x-filament::button wire:click="saveDefaults">Save defaults</x-filament::button>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Providers">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-text-muted">
                        <th class="py-2 pr-4 font-medium">Provider</th>
                        <th class="py-2 pr-4 font-medium">State</th>
                        <th class="py-2 pr-4 font-medium">Answered</th>
                        <th class="py-2 pr-4 font-medium">Typical speed</th>
                        <th class="py-2 font-medium">Circuit</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    @forelse ($this->providers() as $provider)
                        <tr>
                            <td class="py-3 pr-4 text-heading">{{ $provider['name'] }}</td>
                            <td class="py-3 pr-4 text-text-muted">{{ $provider['state'] }}</td>
                            <td class="py-3 pr-4 text-text-muted">
                                @if ($provider['samples'] === 0)
                                    Not used yet
                                @else
                                    {{ $provider['success_rate'] }}% of {{ number_format($provider['samples']) }}
                                @endif
                            </td>
                            <td class="py-3 pr-4 text-text-muted">
                                {{ $provider['latency'] === null ? '—' : number_format($provider['latency']) . ' ms' }}
                            </td>
                            <td class="py-3">
                                <x-filament::badge :color="$provider['open'] ? 'danger' : 'success'">
                                    {{ $provider['circuit'] }}
                                </x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-3 text-text-muted">No providers are configured yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Recent decisions">
        @forelse ($this->decisions() as $decision)
            <div class="flex flex-col gap-2 border-b border-divider py-4 first:pt-0 last:border-0 last:pb-0">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$decision['depth'] > 0 ? 'warning' : 'gray'">
                        {{ $decision['mode'] }}
                    </x-filament::badge>

                    <span class="font-medium text-heading">{{ $decision['chosen'] }}</span>

                    @if ($decision['provider'])
                        <span class="text-sm text-text-muted">via {{ $decision['provider'] }}</span>
                    @endif

                    @if ($decision['depth'] > 0)
                        <x-filament::badge color="warning">
                            Substituted after {{ $decision['depth'] }} failure(s)
                        </x-filament::badge>
                    @endif

                    <span class="text-xs text-text-muted">{{ $decision['at'] }}</span>
                </div>

                <p class="text-sm text-text-muted">{{ $decision['reason'] }}</p>

                <div>
                    <x-filament::button size="xs" color="gray" wire:click="inspect({{ $decision['id'] }})">
                        {{ $inspecting === $decision['id'] ? 'Hide' : 'Why not the others?' }}
                        ({{ $decision['considered'] }} considered)
                    </x-filament::button>
                </div>

                @if ($inspecting === $decision['id'])
                    <ul class="flex flex-col gap-1 text-sm text-text-muted">
                        @forelse ($decision['rejected'] as $candidate)
                            <li>
                                <span class="text-heading">{{ $candidate['model'] ?? 'Unknown model' }}</span>
                                @if (! empty($candidate['provider']))
                                    ({{ $candidate['provider'] }})
                                @endif
                                — {{ $candidate['rejected_because'] ?? 'No reason recorded' }}
                            </li>
                        @empty
                            <li>Every model considered was eligible.</li>
                        @endforelse
                    </ul>
                @endif
            </div>
        @empty
            <p class="text-sm text-text-muted">No routing decisions have been made yet.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
