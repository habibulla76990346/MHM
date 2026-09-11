@php
    use App\Domains\Theming\Support\TokenCatalogue;
@endphp

<x-filament-panels::page>
    @if (! $this->theme())
        <x-filament::section>
            <p class="text-text-muted">No themes are installed. Run <code>php artisan db:seed --class=ThemesSeeder</code>.</p>
        </x-filament::section>
    @else
        {{-- Which theme, and what state is it in --------------------------- --}}
        <x-filament::section compact>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <label class="flex flex-col gap-1 min-w-0 sm:max-w-sm sm:grow">
                    <span class="text-sm font-medium text-heading">Theme</span>
                    <select wire:model.live="themeId" class="fi-input fi-select-input w-full">
                        @foreach (\App\Domains\Theming\Models\Theme::orderBy('name')->get() as $option)
                            <option value="{{ $option->id }}">
                                {{ $option->name }}@if ($option->is_active) — live @endif
                            </option>
                        @endforeach
                    </select>
                </label>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($this->theme()->is_active)
                        <x-filament::badge color="success">Live</x-filament::badge>
                    @endif
                    @if ($this->theme()->is_builtin)
                        <x-filament::badge color="gray">Built-in — duplicate to edit</x-filament::badge>
                    @endif
                    @if ($this->isPreviewing())
                        <x-filament::badge color="warning">Previewing (only you)</x-filament::badge>
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- STEP 1. The seven decisions most owners will ever make.
             Everything else derives from these, for BOTH panels and BOTH
             modes — which is what keeps the Admin Panel from drifting away
             from the customer application (owner decision D-07). --}}
        <x-filament::section
            heading="Brand colours"
            description="Change these seven and the whole theme is rebuilt around them — the customer app and the Admin Panel, light mode and dark. Most people never need to go further than this.">

            <div class="flex flex-col gap-6 lg:flex-row">
                @foreach ([TokenCatalogue::MODE_LIGHT, TokenCatalogue::MODE_DARK] as $paletteMode)
                    <div class="min-w-0 flex-1">
                        <p class="mb-3 text-sm font-medium text-heading">
                            {{ $paletteMode === TokenCatalogue::MODE_LIGHT ? 'Light mode' : 'Dark mode' }}
                        </p>

                        <div class="flex flex-col gap-2">
                            @foreach ([
                                'primary' => 'Primary',
                                'accent' => 'Accent',
                                'background' => 'Page background',
                                'surface' => 'Cards & panels',
                                'text' => 'Body text',
                                'muted' => 'Muted text',
                                'border' => 'Borders',
                            ] as $paletteKey => $paletteLabel)
                                <div class="flex items-center gap-3">
                                    <input
                                        type="color"
                                        wire:model="palette.{{ $paletteMode }}.{{ $paletteKey }}"
                                        aria-label="{{ $paletteLabel }} ({{ $paletteMode }})"
                                        @disabled(! $this->canWrite() || $this->theme()->is_builtin)
                                        class="aziv-swatch shrink-0">
                                    <span class="min-w-0 flex-1 truncate text-sm text-text">{{ $paletteLabel }}</span>
                                    <input
                                        type="text"
                                        wire:model="palette.{{ $paletteMode }}.{{ $paletteKey }}"
                                        aria-label="{{ $paletteLabel }} value ({{ $paletteMode }})"
                                        @disabled(! $this->canWrite() || $this->theme()->is_builtin)
                                        class="fi-input w-28 shrink-0 font-mono text-sm">
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <x-slot name="footerActions">
                <x-filament::button
                    wire:click="applyPalette"
                    wire:loading.attr="disabled"
                    :disabled="! $this->canWrite() || $this->theme()->is_builtin">
                    Rebuild theme from these colours
                </x-filament::button>
            </x-slot>
        </x-filament::section>

        {{-- STEP 2. Everything else. Collapsed, searchable, and every token
             says what it actually affects. --}}
        <x-filament::section
            heading="Everything else"
            description="Only if you need it. Each value says what it changes."
            collapsible
            collapsed>

            {{-- Scope and mode. Which token set is being edited. --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap gap-2" role="group" aria-label="Which part of Aziv AI">
                    @foreach ([TokenCatalogue::SCOPE_CUSTOMER => 'Customer app', TokenCatalogue::SCOPE_ADMIN => 'Admin panel'] as $scopeKey => $scopeLabel)
                        <button
                            type="button"
                            wire:click="selectScope('{{ $scopeKey }}')"
                            aria-pressed="{{ $scope === $scopeKey ? 'true' : 'false' }}"
                            @class(['fi-btn aziv-tab', 'aziv-tab-on' => $scope === $scopeKey])>
                            {{ $scopeLabel }}
                        </button>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-2" role="group" aria-label="Colour mode">
                    @foreach ([TokenCatalogue::MODE_LIGHT => 'Light', TokenCatalogue::MODE_DARK => 'Dark'] as $modeKey => $modeLabel)
                        <button
                            type="button"
                            wire:click="selectMode('{{ $modeKey }}')"
                            aria-pressed="{{ $mode === $modeKey ? 'true' : 'false' }}"
                            @class(['fi-btn aziv-tab', 'aziv-tab-on' => $mode === $modeKey])>
                            {{ $modeLabel }}
                        </button>
                    @endforeach
                </div>
            </div>

            <label class="mt-4 flex flex-col gap-1">
                <span class="sr-only">Search values</span>
                <input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    placeholder="Search — try “modal”, “sidebar” or “button”"
                    class="fi-input w-full">
            </label>

            @forelse ($this->groups as $groupKey => $group)
                <div class="mt-4 rounded-lg border border-divider">
                    <button
                        type="button"
                        wire:click="toggleGroup('{{ $groupKey }}')"
                        aria-expanded="{{ ($open[$groupKey] ?? false) ? 'true' : 'false' }}"
                        class="flex w-full items-center justify-between gap-3 p-3 text-start">
                        <span class="min-w-0">
                            <span class="block font-medium text-heading">{{ $group['label'] }}</span>
                            <span class="block text-sm text-text-muted">{{ $group['description'] }}</span>
                        </span>
                        <span class="shrink-0 text-sm text-text-muted">{{ count($group['tokens']) }}</span>
                    </button>

                    @if (($open[$groupKey] ?? false) || $search !== '')
                        <div class="border-t border-divider p-3">
                            <div class="flex flex-col gap-3">
                                @foreach ($group['tokens'] as $tokenKey => $token)
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                                        <div class="min-w-0 sm:flex-1">
                                            <span class="block text-sm font-medium text-heading">{{ $token['label'] }}</span>
                                            <span class="block text-xs text-text-muted">{{ $token['affects'] }}</span>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            @if ($token['type'] === 'color')
                                                <input
                                                    type="color"
                                                    wire:model="values.{{ $this->wireKey($tokenKey) }}"
                                                    aria-label="{{ $token['label'] }}"
                                                    @disabled(! $this->canWrite() || $this->theme()->is_builtin)
                                                    class="aziv-swatch shrink-0">
                                            @endif

                                            @if (($token['choices'] ?? null))
                                                <select
                                                    wire:model="values.{{ $this->wireKey($tokenKey) }}"
                                                    aria-label="{{ $token['label'] }}"
                                                    @disabled(! $this->canWrite() || $this->theme()->is_builtin)
                                                    class="fi-input fi-select-input w-full sm:w-56">
                                                    @foreach ($token['choices'] as $choiceValue => $choiceLabel)
                                                        <option value="{{ $choiceValue }}">{{ $choiceLabel }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input
                                                    type="text"
                                                    wire:model="values.{{ $this->wireKey($tokenKey) }}"
                                                    aria-label="{{ $token['label'] }} value"
                                                    @disabled(! $this->canWrite() || $this->theme()->is_builtin)
                                                    class="fi-input w-full font-mono text-sm sm:w-56">
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @if ($this->canWrite() && ! $this->theme()->is_builtin)
                                <button
                                    type="button"
                                    wire:click="resetGroup('{{ $groupKey }}')"
                                    class="fi-link mt-3 text-sm">
                                    Reset this group to the derived values
                                </button>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="mt-4 text-sm text-text-muted">Nothing matches “{{ $search }}”.</p>
            @endforelse

            <x-slot name="footerActions">
                <x-filament::button
                    wire:click="save"
                    wire:loading.attr="disabled"
                    :disabled="! $this->canWrite() || $this->theme()->is_builtin">
                    Save changes
                </x-filament::button>
            </x-slot>
        </x-filament::section>

        {{-- Live preview. Shows the values CURRENTLY in the editor, saved or
             not, so a change can be judged before it is committed to anything.
             Self-contained: it sets the tokens on its own subtree, so it never
             restyles the Admin Panel around it. --}}
        <x-filament::section
            heading="Preview"
            description="How these values look right now, before you save.">

            <div class="aziv-preview" style="{{ $this->previewStyle() }}">
                <div class="aziv-preview-bar">
                    <span class="aziv-preview-brand">{{ settings('branding.app_name') }}</span>
                    <span class="aziv-preview-muted">Account</span>
                </div>

                <div class="aziv-preview-body">
                    <h3 class="aziv-preview-heading">A heading</h3>
                    <p class="aziv-preview-text">
                        Ordinary reading text, at the size and weight this theme sets.
                        <a href="#" class="aziv-preview-link" onclick="return false">This is a link.</a>
                    </p>

                    <div class="aziv-preview-card">
                        <p class="aziv-preview-muted">Muted text on a card</p>
                        <div class="aziv-preview-row">
                            <span class="aziv-preview-btn">Primary action</span>
                            <span class="aziv-preview-btn-secondary">Secondary</span>
                            <span class="aziv-preview-badge">Badge</span>
                        </div>
                    </div>

                    <div class="aziv-preview-row">
                        <span class="aziv-preview-bubble-user">A message you sent</span>
                        <span class="aziv-preview-bubble-ai">A reply from Aziv AI</span>
                    </div>

                    <code class="aziv-preview-code">const answer = 42;</code>
                </div>
            </div>
        </x-filament::section>

        {{-- Readability. An editor that lets an owner build an unreadable site
             would be a defect, so this is shown, not buried in a report. --}}
        <x-filament::section
            heading="Readability"
            description="Every text and background pair, measured against the WCAG AA threshold of 4.5 to 1.">

            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($this->contrast as $pair)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-divider p-3">
                        <span class="min-w-0 flex-1 text-sm text-text">{{ $pair['pair'] }}</span>
                        <x-filament::badge :color="$pair['passes'] ? 'success' : 'danger'">
                            {{ number_format($pair['ratio'], 2) }}:1
                        </x-filament::badge>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Custom CSS. Super Admin only, sanitised on the way in. --}}
        @can('themes.custom_css')
            <x-filament::section
                heading="Custom CSS"
                description="For anything the values above cannot express. Saved sanitised: remote stylesheets, remote images, data URLs and script-capable constructs are stripped, and you are told what was removed."
                collapsible
                collapsed>

                <label class="flex flex-col gap-1">
                    <span class="sr-only">Custom CSS</span>
                    <textarea
                        wire:model="customCss"
                        rows="10"
                        spellcheck="false"
                        class="fi-input fi-textarea w-full font-mono text-sm"
                        @disabled(! $this->canWrite() || $this->theme()->is_builtin)></textarea>
                </label>

                <x-slot name="footerActions">
                    <x-filament::button
                        wire:click="saveCustomCss"
                        wire:loading.attr="disabled"
                        :disabled="! $this->canWrite() || $this->theme()->is_builtin">
                        Save custom CSS
                    </x-filament::button>
                </x-slot>
            </x-filament::section>
        @endcan
    @endif
</x-filament-panels::page>
