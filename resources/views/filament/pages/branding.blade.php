<x-filament-panels::page>

    @unless ($this->webRootIsWritable())
        {{-- Addendum G: name the problem, whose it is, and what to do. --}}
        <x-filament::section compact>
            <div class="flex flex-col gap-1">
                <p class="font-medium text-heading">Branding cannot be changed on this server yet</p>
                <p class="text-sm text-text">
                    Aziv AI cannot write to the <code>public/brand</code> folder, so an uploaded logo could not be saved.
                    The built-in Aziv AI artwork is being used.
                </p>
                <p class="text-sm text-text-muted">
                    Send this to your hosting provider: “Please make the <code>public/brand</code> folder writable by the
                    web server (permission 755).”
                </p>
            </div>
        </x-filament::section>
    @endunless

    {{-- Names and wording ------------------------------------------------- --}}
    <x-filament::section
        heading="Names and wording"
        description="What the product is called, wherever its name appears.">

        <div class="flex flex-col gap-4">
            @foreach ($this->textFields() as $key => $field)
                <label class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-heading">{{ $field['label'] }}</span>
                    <input
                        type="{{ $field['type'] }}"
                        wire:model="text.{{ $this->wireKey($key) }}"
                        @disabled(! $this->canWrite())
                        class="fi-input w-full">
                    <span class="text-xs text-text-muted">{{ $field['help'] }}</span>
                </label>
            @endforeach
        </div>

        <x-slot name="footerActions">
            <x-filament::button wire:click="saveText" :disabled="! $this->canWrite()">
                Save
            </x-filament::button>
        </x-slot>
    </x-filament::section>

    {{-- Artwork ------------------------------------------------------------
         D-09: the official Aziv AI artwork is the starting point, not a
         placeholder, and the master is never overwritten — so "Use the Aziv AI
         artwork" always has something to go back to. --}}
    <x-filament::section
        heading="Artwork"
        description="PNG, JPEG, WebP or ICO. SVG is not accepted for uploads: it can carry scripts and would be served from your own domain.">

        <div class="flex flex-col gap-6">
            @foreach ($this->assets() as $purpose => $asset)
                <div class="flex flex-col gap-3 border-b border-divider pb-6 last:border-0 last:pb-0">
                    <div class="flex flex-col gap-1">
                        <span class="font-medium text-heading">{{ $asset['label'] }}</span>
                        <span class="text-sm text-text-muted">{{ $asset['help'] }}</span>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                        {{-- Current. Checkered behind it so a transparent logo
                             is visible against either theme. --}}
                        <div class="aziv-asset-preview shrink-0">
                            <img src="{{ asset($this->currentPath($purpose)) }}"
                                 alt="{{ $asset['label'] }}"
                                 loading="lazy">
                        </div>

                        <div class="flex min-w-0 flex-1 flex-col gap-2">
                            <x-filament::badge :color="$this->isCustomised($purpose) ? 'success' : 'gray'" class="self-start">
                                {{ $this->isCustomised($purpose) ? 'Your artwork' : 'Aziv AI artwork' }}
                            </x-filament::badge>

                            <label class="flex flex-col gap-1">
                                <span class="sr-only">Replace {{ $asset['label'] }}</span>
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp,image/x-icon"
                                    wire:model="uploads.{{ $purpose }}"
                                    @disabled(! $this->canWrite())
                                    class="fi-input w-full">
                            </label>

                            <div class="flex flex-wrap gap-2">
                                <x-filament::button
                                    size="sm"
                                    wire:click="upload('{{ $purpose }}')"
                                    wire:loading.attr="disabled"
                                    :disabled="! $this->canWrite()">
                                    Replace
                                </x-filament::button>

                                @if ($this->isCustomised($purpose))
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        wire:click="resetAsset('{{ $purpose }}')"
                                        :disabled="! $this->canWrite()">
                                        Use the Aziv AI artwork
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- What happens to an upload ----------------------------------------- --}}
    <x-filament::section heading="Where your uploads go" collapsible collapsed>
        <div class="flex flex-col gap-2 text-sm text-text">
            <p>
                The file you upload is stored privately and checked first — file type, real content,
                size, and a security scan. Nothing reaches the public part of the site until it passes.
            </p>
            <p>
                Aziv AI then writes a copy of the image into <code>public/brand</code> so browsers can
                fetch it directly, without asking the application each time. That copy is named after
                the file's own content, so browsers can cache it safely and a new upload never shows a
                stale image.
            </p>
            <p class="text-text-muted">
                Your original upload is kept privately either way, and the artwork Aziv AI ships with is
                never overwritten.
            </p>
        </div>
    </x-filament::section>

</x-filament-panels::page>
