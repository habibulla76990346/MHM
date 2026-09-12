{{--
    The Library (§17).

    Polls ONLY while something is being read or indexed. A screen that polls
    for ever costs a request a second on shared hosting, and a status that
    never resolves is what makes somebody upload the same file four times.
--}}
<div class="flex flex-col gap-5" style="max-width: 46rem;"
     @if ($this->working) wire:poll.3s @endif>

    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="font-semibold text-heading" style="font-size: var(--text-fluid-xl);">{{ __('Library') }}</h1>
        <p class="text-sm text-text-muted">
            {{ __('Upload documents and ask about them in chat.') }}
        </p>
    </div>

    @if ($error)
        <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
    @endif

    {{-- --- collections ------------------------------------------------- --}}
    <div class="rounded-lg border border-border bg-surface p-5">
        <h2 class="mb-3 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Collections') }}</h2>

        @if ($this->bases->isEmpty())
            <p class="mb-4 text-text-muted">
                {{ __('A collection holds documents that belong together — a handbook, a set of contracts, your notes.') }}
            </p>
        @else
            <label class="mb-2 block text-sm text-text-muted" for="base-select">{{ __('Showing') }}</label>
            <select id="base-select" wire:model.live="baseUuid" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text">
                @foreach ($this->bases as $base)
                    <option value="{{ $base->uuid }}">
                        {{ $base->name }}{{ $base->scope === 'shared' ? ' — '.__('shared with you') : '' }}
                    </option>
                @endforeach
            </select>
        @endif

        @if ($creating)
            <form class="mt-4 flex flex-col gap-3" wire:submit="createBase">
                <x-ui.input name="newBaseName" wire:model="newBaseName" :label="__('Name this collection')" required />
                @error('newBaseName') <p class="text-sm" style="color: var(--color-danger);">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit">{{ __('Create') }}</x-ui.button>
                    <x-ui.button type="button" variant="secondary" wire:click="$set('creating', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        @else
            <div class="mt-4">
                <x-ui.button type="button" variant="secondary" wire:click="$set('creating', true)">
                    {{ __('New collection') }}
                </x-ui.button>
            </div>
        @endif
    </div>

    {{-- --- upload -------------------------------------------------------- --}}
    @if ($this->base)
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-2 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Add a document') }}</h2>
            <p class="mb-4 text-sm text-text-muted">
                {{ __('Aziv AI can read: :types.', ['types' => $this->acceptedTypes]) }}
            </p>

            <form wire:submit="store" class="flex flex-col gap-3">
                {{-- `capture` lets a phone offer the camera as well as the
                     file picker, which is how most documents reach a phone. --}}
                <input id="document-upload" type="file" wire:model="upload"
                       class="w-full rounded-md border border-border bg-surface px-3 py-2 text-text"
                       aria-label="{{ __('Choose a document') }}">

                <div wire:loading wire:target="upload" class="text-sm text-text-muted">{{ __('Uploading…') }}</div>
                @error('upload') <p class="text-sm" style="color: var(--color-danger);">{{ $message }}</p> @enderror

                <x-ui.button type="submit">{{ __('Add to this collection') }}</x-ui.button>
            </form>
        </div>

        {{-- --- documents -------------------------------------------------- --}}
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-3 font-semibold text-heading" style="font-size: var(--text-fluid-lg);">{{ __('Documents') }}</h2>

            @forelse ($this->documents as $document)
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-divider py-3 last:border-0 last:pb-0">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-text break-anywhere">{{ $document->title }}</p>
                        <p class="text-sm text-text-muted">
                            {{ $document->statusLabel() }}
                            @if ($document->isReady())
                                · {{ trans_choice('{1}:count passage|[2,*]:count passages', $document->chunk_count, ['count' => $document->chunk_count]) }}
                            @endif
                        </p>
                        @if ($document->hasFailed() && $document->failure_reason)
                            {{-- The reason, in words. A document stuck with no
                                 explanation is a support ticket. --}}
                            <p class="mt-1 text-sm" style="color: var(--color-danger);">{{ $document->failure_reason }}</p>
                        @endif
                    </div>

                    <x-ui.button type="button" variant="secondary"
                                 wire:click="remove('{{ $document->uuid }}')"
                                 wire:confirm="{{ __('Remove :name from this collection?', ['name' => $document->title]) }}">
                        {{ __('Remove') }}
                    </x-ui.button>
                </div>
            @empty
                <p class="text-text-muted">{{ __('Nothing here yet. Add a document above and you can ask about it in chat.') }}</p>
            @endforelse
        </div>
    @endif
</div>
