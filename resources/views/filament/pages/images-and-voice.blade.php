<x-filament-panels::page>
    {{--
        Whether the features can actually work, before the switches that turn
        them on. A switch that is on with no model behind it is the most
        confusing state this product has: the customer sees a button that
        always fails.
    --}}
    <x-filament::section compact heading="Can these work right now?">
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([
                'image' => ['Make images', 'a provider offering image generation'],
                'transcription' => ['Speech to text', 'a provider offering transcription'],
                'speech' => ['Text to speech', 'a provider offering speech'],
            ] as $key => [$label, $needs])
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-heading">{{ $label }}</span>
                    @if ($this->readiness[$key])
                        <span class="text-sm" style="color: var(--color-success);">A model is available.</span>
                    @else
                        <span class="text-sm" style="color: var(--color-warning);">
                            Nothing available — add {{ $needs }}, sync its catalog and enable a model.
                        </span>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section compact heading="Today">
        <p class="text-sm text-text-muted">
            <strong class="text-heading">{{ $this->activity['images_today'] }}</strong> image(s) requested,
            {{ $this->activity['images_failed_today'] }} failed ·
            <strong class="text-heading">{{ $this->activity['voice_today'] }}</strong> voice job(s),
            {{ $this->activity['voice_failed_today'] }} failed.
            What each cost is on Admin → Usage and costs.
        </p>
    </x-filament::section>

    {{-- Expanded, not collapsed: the six-viewport gate only measures what is
         visible, so a form behind a toggle is a form nothing checks. --}}
    <x-filament::section heading="Settings">
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($this->editableSettings() as $key => $definition)
                @php $field = \App\Filament\Pages\ImagesAndVoice::wireKey($key); @endphp

                <div class="flex flex-col gap-1">
                    @if ($definition->type === 'bool')
                        <label class="flex items-center gap-3 text-sm font-medium text-heading"
                               for="{{ $field }}" style="min-height: var(--tap-min);">
                            <input id="{{ $field }}" type="checkbox"
                                   wire:model="values.{{ $field }}"
                                   @disabled(! $this->canWrite())
                                   class="fi-checkbox-input"
                                   style="width: 1.25rem; height: 1.25rem;">
                            {{ $definition->label ?: $key }}
                        </label>
                    @else
                        <label class="text-sm font-medium text-heading" for="{{ $field }}">
                            {{ $definition->label ?: $key }}
                        </label>
                        {{-- fi-input carries the shared 16px sizing and 44px
                             minimum height, so a field here cannot drift away
                             from every other input in the panel. --}}
                        <input id="{{ $field }}"
                               type="{{ $definition->type === 'int' ? 'number' : 'text' }}"
                               wire:model="values.{{ $field }}"
                               @disabled(! $this->canWrite())
                               class="fi-input w-full">
                    @endif

                    @if ($definition->description)
                        <span class="text-xs text-text-muted">{{ $definition->description }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($this->canWrite())
            <div class="mt-4">
                <x-filament::button wire:click="save">Save</x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
