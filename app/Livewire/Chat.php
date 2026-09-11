<?php

namespace App\Livewire;

use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Models\MessageFeedback;
use App\Domains\Chat\Models\Persona;
use App\Domains\Chat\Services\ChatService;
use App\Domains\Chat\Services\ConversationService;
use App\Domains\Chat\Services\ModelSelector;
use App\Domains\Chat\Support\ChatRefused;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The chat screen (§15, Owner Addendum A).
 *
 * Livewire owns STATE — which conversation, what history, which model. The
 * streaming itself is plain JavaScript against an SSE endpoint, because a
 * Livewire round trip per token would be thousands of requests for one reply.
 *
 * Every public property is a scalar. Models live behind computed properties: a
 * Livewire snapshot is serialised into the page, and putting an Eloquent model
 * there is the mistake that took down the theme service and System Health
 * earlier in this build.
 */
class Chat extends Component
{
    #[Url(as: 'c', except: '')]
    public string $conversationUuid = '';

    public string $draft = '';

    public string $search = '';

    public bool $showArchived = false;

    /** The reply currently being produced, so the view can attach a stream. */
    public ?string $streamingUuid = null;

    public ?int $selectedModelId = null;

    public ?int $selectedPersonaId = null;

    public string $error = '';

    public function mount(ConversationService $conversations): void
    {
        if ($this->conversationUuid === '') {
            $this->conversationUuid = $conversations->list(auth()->user())->first()?->uuid ?? '';
        }

        $conversation = $this->conversation();

        $this->selectedModelId ??= $conversation?->pinned_model_id;
        $this->selectedPersonaId ??= $conversation?->persona_id ?? Persona::default()?->getKey();
    }

    // -- state ---------------------------------------------------------------

    public function conversation(): ?Conversation
    {
        if ($this->conversationUuid === '') {
            return null;
        }

        return Conversation::forUser(auth()->user())
            ->where('uuid', $this->conversationUuid)
            ->first();
    }

    /** @return Collection<int, Conversation> */
    #[Computed]
    public function conversations(): Collection
    {
        return app(ConversationService::class)
            ->list(auth()->user(), $this->showArchived, $this->search);
    }

    /** @return Collection<int, Message> */
    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation()?->visibleMessages()
            ->with('model', 'attachments.file')
            ->get() ?? collect();
    }

    #[Computed]
    public function models(): Collection
    {
        return app(ModelSelector::class)->available();
    }

    #[Computed]
    public function personas(): Collection
    {
        return Persona::active()->get();
    }

    #[Computed]
    public function streamingEnabled(): bool
    {
        return (bool) settings('chat.streaming_enabled');
    }

    // -- conversation management --------------------------------------------

    public function newChat(ConversationService $conversations): void
    {
        $conversation = $conversations->start(
            auth()->user(),
            $this->selectedPersonaId,
            $this->selectedModelId,
        );

        $this->conversationUuid = $conversation->uuid;
        $this->error = '';
        $this->streamingUuid = null;

        unset($this->conversations, $this->messages);
        $this->dispatch('conversation-opened');
    }

    public function openConversation(string $uuid): void
    {
        $conversation = Conversation::forUser(auth()->user())->where('uuid', $uuid)->first();

        if (! $conversation) {
            return;
        }

        $this->conversationUuid = $uuid;
        $this->selectedModelId = $conversation->pinned_model_id;
        $this->selectedPersonaId = $conversation->persona_id;
        $this->error = '';
        $this->streamingUuid = null;

        unset($this->messages);
        $this->dispatch('conversation-opened');
    }

    public function renameConversation(string $uuid, string $title): void
    {
        $conversation = Conversation::forUser(auth()->user())->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $conversation);

        app(ConversationService::class)->rename($conversation, $title);
        unset($this->conversations);
    }

    public function toggleArchive(string $uuid): void
    {
        $conversation = Conversation::forUser(auth()->user())->where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $conversation);

        app(ConversationService::class)->archive($conversation, ! $conversation->is_archived);

        if ($conversation->uuid === $this->conversationUuid) {
            $this->conversationUuid = '';
        }

        unset($this->conversations, $this->messages);
    }

    public function deleteConversation(string $uuid): void
    {
        $conversation = Conversation::forUser(auth()->user())->where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $conversation);

        app(ConversationService::class)->delete($conversation);

        if ($conversation->uuid === $this->conversationUuid) {
            $this->conversationUuid = '';
        }

        unset($this->conversations, $this->messages);
    }

    public function updatedSelectedModelId(): void
    {
        // Choosing a model pins it; clearing the choice returns to Auto.
        $this->conversation()?->forceFill([
            'pinned_model_id' => $this->selectedModelId ?: null,
            'routing_mode' => $this->selectedModelId
                ? Conversation::ROUTING_SPECIFIC_MODEL
                : Conversation::ROUTING_AUTO,
        ])->save();
    }

    public function updatedSelectedPersonaId(): void
    {
        $this->conversation()?->forceFill(['persona_id' => $this->selectedPersonaId ?: null])->save();
    }

    // -- sending -------------------------------------------------------------

    public function send(ChatService $chat, ConversationService $conversations): void
    {
        $this->error = '';

        $conversation = $this->conversation()
            ?? $conversations->start(auth()->user(), $this->selectedPersonaId, $this->selectedModelId);

        $this->conversationUuid = $conversation->uuid;

        try {
            $turn = $chat->beginTurn($conversation, $this->draft);
        } catch (ChatRefused $e) {
            // A limit the product set, phrased for the person who hit it.
            $this->error = $e->getMessage();

            return;
        }

        $this->draft = '';
        $this->streamingUuid = $turn['assistant']->uuid;

        unset($this->conversations, $this->messages);

        // The browser opens the stream; Livewire never pumps tokens.
        $this->dispatch('start-stream', uuid: $turn['assistant']->uuid);
    }

    public function regenerate(string $uuid, ChatService $chat): void
    {
        $this->error = '';

        $previous = Message::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $previous);

        try {
            $turn = $chat->beginRegeneration($previous);
        } catch (ChatRefused $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->streamingUuid = $turn['assistant']->uuid;
        unset($this->messages);

        $this->dispatch('start-stream', uuid: $turn['assistant']->uuid);
    }

    /** Called by the browser once a stream has settled. */
    public function streamFinished(): void
    {
        $this->streamingUuid = null;
        unset($this->messages, $this->conversations);
    }

    public function rate(string $uuid, int $rating): void
    {
        $message = Message::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $message);

        $existing = MessageFeedback::where('message_id', $message->getKey())
            ->where('user_id', auth()->id())
            ->first();

        // Clicking the same thumb again clears it — an opinion you can give
        // but not withdraw is a trap.
        if ($existing && $existing->rating === $rating) {
            $existing->delete();
        } else {
            MessageFeedback::updateOrCreate(
                ['message_id' => $message->getKey(), 'user_id' => auth()->id()],
                ['rating' => $rating],
            );
        }

        unset($this->messages);
    }

    public function render()
    {
        return view('livewire.chat');
    }
}
