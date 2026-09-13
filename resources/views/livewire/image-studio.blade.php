{{--
    The image studio and gallery (§16).

    Polls ONLY while something is actually generating. A gallery that polls for
    ever costs a request every few seconds per open tab, for ever, on hosting
    where that matters.

    Every colour, radius and spacing value comes from a design token — the
    gallery inherits the owner's theme like everything else (Rule 1).
--}}
<div class="flex flex-col gap-5" style="max-width: 60rem;"
     @if ($this->working) wire:poll.4s @endif>

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">{{ __('Images') }}</h1>
        <p class="text-sm text-text-muted">{{ __('Describe a picture and Aziv AI will make it.') }}</p>
    </div>

    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    @if (! $this->enabled)
        <x-ui.alert variant="info">{{ __('Image generation is switched off.') }}</x-ui.alert>
    @elseif (! $this->ready)
        <x-ui.alert variant="warning">
            {{ __('No image model is available yet. An administrator needs to add a provider that generates images and enable one of its models.') }}
        </x-ui.alert>
    @else
        {{-- --- the studio ------------------------------------------------- --}}
        <div class="rounded-lg border border-border bg-surface p-5">
            <form wire:submit="generate" class="flex flex-col gap-4">
                <div>
                    <label for="image-prompt" class="mb-2 block text-sm text-text-muted">
                        {{ __('What should the picture show?') }}
                    </label>
                    <textarea id="image-prompt" wire:model="prompt" rows="3" required
                              class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text"
                              placeholder="{{ __('A quiet street at dawn, watercolour') }}"></textarea>
                    @error('prompt') <p class="mt-1 text-sm" style="color: var(--color-danger);">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4" style="grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));">
                    <div>
                        <label for="image-size" class="mb-2 block text-sm text-text-muted">{{ __('Shape') }}</label>
                        <select id="image-size" wire:model="size"
                                class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text">
                            @foreach ($sizes as $value => $label)
                                <option value="{{ $value }}">{{ __($label) }} — {{ $value }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="image-quality" class="mb-2 block text-sm text-text-muted">{{ __('Quality') }}</label>
                        <select id="image-quality" wire:model="quality"
                                class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text">
                            @foreach ($qualities as $value => $label)
                                <option value="{{ $value }}">{{ __($label) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="image-count" class="mb-2 block text-sm text-text-muted">{{ __('How many') }}</label>
                        <select id="image-count" wire:model="count"
                                class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text">
                            @for ($n = 1; $n <= $maxPerRequest; $n++)
                                <option value="{{ $n }}">{{ $n }}</option>
                            @endfor
                        </select>
                    </div>
                </div>

                {{-- Left EXPANDED rather than collapsed: the six-viewport gate
                     only measures what is visible, so a control hidden behind a
                     toggle is a control nothing checks. --}}
                <div>
                    <label for="image-negative" class="mb-2 block text-sm text-text-muted">
                        {{ __('Anything to leave out (optional)') }}
                    </label>
                    <input id="image-negative" type="text" wire:model="negativePrompt"
                           class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text"
                           placeholder="{{ __('text, watermarks') }}">
                    <p class="mt-1 text-sm text-text-muted">
                        {{ __('Not every provider supports this. Where it is not supported it is ignored rather than added to your description.') }}
                    </p>
                </div>

                <div>
                    <x-ui.button type="submit">
                        <span wire:loading.remove wire:target="generate">{{ __('Generate') }}</span>
                        <span wire:loading wire:target="generate">{{ __('Sending…') }}</span>
                    </x-ui.button>
                </div>
            </form>

            @if ($this->recentPrompts)
                <div class="mt-5 border-t border-divider pt-4">
                    <p class="mb-2 text-sm text-text-muted">{{ __('Ask for one of these again') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->recentPrompts as $recent)
                            <button type="button" wire:click="reuse(@js($recent))"
                                    class="rounded-md border border-border bg-surface px-3 text-sm text-text"
                                    style="min-height: var(--tap-min); max-width: 100%;">
                                <span class="block truncate" style="max-width: 18rem;">{{ $recent }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- --- the gallery ---------------------------------------------------- --}}
    <div class="rounded-lg border border-border bg-surface p-5">
        <h2 class="mb-4 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Your images') }}</h2>

        @forelse ($this->generations as $generation)
            <div class="flex flex-wrap items-start gap-4 border-b border-divider py-4 last:border-0 last:pb-0">
                <div class="shrink-0 overflow-hidden rounded-md border border-border"
                     style="width: 8rem; height: 8rem; background: var(--color-surface-alt);">
                    @if ($generation->isViewable())
                        <img src="{{ route('media.show', $generation->file) }}"
                             alt="{{ $generation->prompt }}" loading="lazy"
                             style="width: 100%; height: 100%; object-fit: cover;">
                    @else
                        <div class="flex h-full w-full items-center justify-center p-2 text-center text-sm text-text-muted">
                            @if ($generation->isWorking())
                                {{ __('Making…') }}
                            @else
                                {{ __('No image') }}
                            @endif
                        </div>
                    @endif
                </div>

                <div class="min-w-0 flex-1" style="min-width: 12rem;">
                    <p class="text-text break-anywhere">{{ $generation->prompt }}</p>

                    @if ($generation->revised_prompt && $generation->revised_prompt !== $generation->prompt)
                        {{-- Some providers rewrite the description before
                             generating. Showing what they actually used is the
                             only way to answer "I asked for a red car". --}}
                        <p class="mt-1 text-sm text-text-muted break-anywhere">
                            {{ __('Generated from: :prompt', ['prompt' => $generation->revised_prompt]) }}
                        </p>
                    @endif

                    <p class="mt-1 text-sm text-text-muted">
                        {{ $generation->size }}
                        @if ($generation->source) · {{ __('regenerated') }} @endif
                        · {{ $generation->created_at?->diffForHumans() }}
                    </p>

                    @if ($generation->status === 'failed' && $generation->failure_reason)
                        <p class="mt-1 text-sm" style="color: var(--color-danger);">{{ $generation->failure_reason }}</p>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($generation->isViewable())
                            <x-ui.button variant="secondary" :href="route('files.show', $generation->file)">
                                {{ __('Download') }}
                            </x-ui.button>
                        @endif

                        <x-ui.button type="button" variant="secondary" wire:click="again('{{ $generation->uuid }}')">
                            {{ __('Again') }}
                        </x-ui.button>

                        <x-ui.button type="button" variant="secondary"
                                     wire:click="remove('{{ $generation->uuid }}')"
                                     wire:confirm="{{ __('Delete this image? This cannot be undone.') }}">
                            {{ __('Delete') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-text-muted">{{ __('Nothing yet. Describe a picture above and it will appear here.') }}</p>
        @endforelse
    </div>
</div>
