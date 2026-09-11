@php use App\Domains\Chat\Models\Message; @endphp

{{-- The chat screen.

     MOBILE LAYOUT (Owner Addendum A):
     - the shell is dvh, not vh, so an iOS URL bar appearing does not push the
       composer off-screen
     - the composer tracks visualViewport, so it sits above the keyboard rather
       than under it
     - the conversation list is a bottom SHEET on a phone and a column on a
       desktop — not the same panel squeezed
     - the stop button sits in the composer row, within thumb reach
     - scroll anchoring is conditional: streaming only pins to the bottom while
       the reader is already there                                          --}}

<div class="aziv-chat"
     x-data="azivChat({
         streaming: @js($this->streamingEnabled),
         finishedEvent: 'stream-finished',
     })"
     x-on:start-stream.window="startStream($event.detail.uuid)"
     x-on:conversation-opened.window="closeSheet(); scrollToBottom(true)"
     wire:ignore.self>

    {{-- CONVERSATION LIST ------------------------------------------------
         Desktop: a permanent column. Phone: a bottom sheet. --}}
    <aside class="aziv-chat-list" :class="sheetOpen && 'is-open'" x-cloak>
        <div class="aziv-chat-list-inner">
            <div class="aziv-sheet-grip md:hidden" x-on:click="closeSheet()" aria-hidden="true"></div>

            <div class="flex items-center gap-2 p-3">
                <button type="button" wire:click="newChat" class="aziv-chat-new">
                    <x-ui.icon name="chat" class="size-5" />
                    <span>{{ __('New chat') }}</span>
                </button>

                <button type="button" class="aziv-icon-button md:hidden" x-on:click="closeSheet()"
                        aria-label="{{ __('Close conversations') }}">
                    <x-ui.icon name="close" class="size-5" />
                </button>
            </div>

            <div class="px-3 pb-2">
                <label class="sr-only" for="chat-search">{{ __('Search conversations') }}</label>
                <input id="chat-search" type="search" wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('Search your chats') }}" class="aziv-field">
            </div>

            <div class="flex-1 overflow-y-auto px-2 pb-3">
                @forelse ($this->conversations as $item)
                    <div @class(['aziv-chat-item', 'is-active' => $item->uuid === $conversationUuid])>
                        <button type="button" wire:click="openConversation('{{ $item->uuid }}')"
                                class="aziv-chat-item-open">
                            <span class="truncate">{{ $item->title ?: __('New chat') }}</span>
                            <span class="aziv-chat-item-time">{{ $item->last_message_at?->diffForHumans(short: true) }}</span>
                        </button>

                        <details class="aziv-chat-item-menu">
                            <summary aria-label="{{ __('Chat options') }}"><x-ui.icon name="more" class="size-4" /></summary>
                            <div class="aziv-chat-item-actions">
                                <button type="button"
                                        wire:click="renameConversation('{{ $item->uuid }}', prompt('{{ __('New name') }}', @js($item->title ?: '')) ?? @js($item->title ?: ''))">
                                    {{ __('Rename') }}
                                </button>
                                <button type="button" wire:click="toggleArchive('{{ $item->uuid }}')">
                                    {{ $item->is_archived ? __('Unarchive') : __('Archive') }}
                                </button>
                                <button type="button" wire:click="deleteConversation('{{ $item->uuid }}')"
                                        wire:confirm="{{ __('Delete this conversation?') }}">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </details>
                    </div>
                @empty
                    <p class="p-3 text-sm text-text-muted">
                        {{ $search !== '' ? __('Nothing matches that.') : __('No conversations yet.') }}
                    </p>
                @endforelse
            </div>

            <label class="aziv-chat-archive-toggle">
                <input type="checkbox" wire:model.live="showArchived">
                <span>{{ __('Show archived') }}</span>
            </label>
        </div>
    </aside>

    {{-- The scrim only exists while the sheet is open. --}}
    <div class="aziv-sheet-scrim md:hidden" x-show="sheetOpen" x-on:click="closeSheet()" x-cloak
         x-transition.opacity aria-hidden="true"></div>

    {{-- THREAD ----------------------------------------------------------- --}}
    <section class="aziv-chat-main">
        <header class="aziv-chat-header">
            <button type="button" class="aziv-icon-button md:hidden" x-on:click="openSheet()"
                    aria-label="{{ __('Your conversations') }}">
                <x-ui.icon name="menu" class="size-5" />
            </button>

            <h1 class="min-w-0 flex-1 truncate font-medium text-heading">
                {{ $this->conversation()?->title ?: __('New chat') }}
            </h1>

            <label class="sr-only" for="chat-model">{{ __('Model') }}</label>
            <select id="chat-model" wire:model.live="selectedModelId" class="aziv-field aziv-chat-model">
                <option value="">{{ __('Auto') }}</option>
                @foreach ($this->models as $model)
                    <option value="{{ $model->id }}">{{ $model->display_name }}</option>
                @endforeach
            </select>
        </header>

        <div class="aziv-chat-thread"
             x-ref="thread"
             x-on:scroll="onScroll()">

            @forelse ($this->messages as $message)
                <article @class([
                    'aziv-msg',
                    'aziv-msg-user' => $message->isFromUser(),
                    'aziv-msg-ai' => ! $message->isFromUser(),
                ]) data-uuid="{{ $message->uuid }}">

                    <div class="aziv-msg-body break-anywhere"
                         @if ($message->uuid === $streamingUuid) x-ref="streamTarget" @endif>{{ $message->content }}</div>

                    @if ($message->status === Message::STATUS_FAILED)
                        <p class="aziv-msg-error">
                            {{ \App\Domains\AI\Support\ErrorClass::label($message->error_class ?? 'unknown') }} —
                            {{ \App\Domains\AI\Support\ErrorClass::action($message->error_class ?? 'unknown') }}
                        </p>
                    @elseif ($message->wasStopped())
                        {{-- Stopped answers keep what arrived: it was produced
                             and paid for. --}}
                        <p class="aziv-msg-note">{{ __('You stopped this reply.') }}</p>
                    @endif

                    @if (! $message->isFromUser() && ! $message->isInProgress())
                        <div class="aziv-msg-tools">
                            <button type="button" class="aziv-msg-tool"
                                    x-on:click="copyMessage($el)" data-copy>
                                {{ __('Copy') }}
                            </button>

                            <button type="button" class="aziv-msg-tool" wire:click="regenerate('{{ $message->uuid }}')">
                                {{ __('Regenerate') }}
                            </button>

                            <button type="button" @class(['aziv-msg-tool', 'is-on' => $message->feedback?->rating === 1])
                                    wire:click="rate('{{ $message->uuid }}', 1)"
                                    aria-label="{{ __('Good answer') }}">👍</button>

                            <button type="button" @class(['aziv-msg-tool', 'is-on' => $message->feedback?->rating === -1])
                                    wire:click="rate('{{ $message->uuid }}', -1)"
                                    aria-label="{{ __('Poor answer') }}">👎</button>

                            @if ($message->model)
                                <span class="aziv-msg-model">{{ $message->model->display_name }}</span>
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                <div class="aziv-chat-empty">
                    <x-brand.mark :size="48" class="text-primary" />
                    <p class="text-text-muted">{{ settings('branding.tagline') }}</p>
                </div>
            @endforelse

            {{-- Anchored to the bottom so "scrolled to the end" is a real
                 measurement rather than an arithmetic guess. --}}
            <div x-ref="anchor" class="aziv-chat-anchor" aria-hidden="true"></div>
        </div>

        {{-- Appears only when the reader has scrolled away during a stream. --}}
        <button type="button" class="aziv-jump-latest" x-show="!pinned" x-cloak x-transition
                x-on:click="scrollToBottom(true)">
            {{ __('Jump to latest') }}
        </button>

        @if ($error !== '')
            <p class="aziv-chat-error" role="alert">{{ $error }}</p>
        @endif

        {{-- COMPOSER ------------------------------------------------------
             Tracks visualViewport so it stays above a mobile keyboard, and
             respects the safe-area inset on a notched device. --}}
        <form class="aziv-composer" wire:submit="send" x-ref="composer">
            <label class="sr-only" for="chat-draft">{{ __('Your message') }}</label>

            <textarea id="chat-draft"
                      wire:model="draft"
                      x-ref="draft"
                      x-on:input="autoGrow()"
                      x-on:keydown.enter.exact.prevent="submitIfReady()"
                      rows="1"
                      placeholder="{{ __('Send a message…') }}"
                      class="aziv-composer-input"></textarea>

            <div class="aziv-composer-actions">
                <button type="button" class="aziv-stop" x-show="streamingNow" x-cloak
                        x-on:click="stopStream()">
                    {{ __('Stop') }}
                </button>

                <button type="submit" class="aziv-send" x-show="!streamingNow"
                        wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="send">{{ __('Send') }}</span>
                    <span wire:loading wire:target="send">{{ __('Sending…') }}</span>
                </button>
            </div>
        </form>
    </section>
</div>
