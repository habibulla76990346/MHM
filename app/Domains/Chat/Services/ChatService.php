<?php

namespace App\Domains\Chat\Services;

use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\CredentialUsageCounter;
use App\Domains\AI\Models\ProviderHealthLog;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Models\MessageAttachment;
use App\Domains\Chat\Support\ChatRefused;
use App\Domains\Files\Models\File;
use App\Models\User;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One turn of a conversation, end to end (§15).
 *
 * The orchestration that has to be right:
 *
 *  - The customer's message is saved BEFORE the provider is called, so a
 *    failure never loses what they typed.
 *  - The assistant row is created immediately in `pending`, so the UI has
 *    something to attach a stream to and a crash leaves a visible state rather
 *    than a conversation that silently stops.
 *  - A stop, a failure and a completion all SETTLE that row. A message left in
 *    `streaming` forever is worse than one marked failed.
 */
class ChatService
{
    private const STOP_CACHE_PREFIX = 'aziv:chat:stop:';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ContextBuilder $context,
        private readonly ModelSelector $selector,
        private readonly ChatRateLimiter $limiter,
    ) {}

    // -- starting a turn -----------------------------------------------------

    /**
     * Validate and store what the customer sent, then create the row the
     * answer will be written into.
     *
     * @param  array<int, int>  $fileIds
     * @return array{user: Message, assistant: Message, model: AiModel}
     *
     * @throws ChatRefused
     */
    public function beginTurn(Conversation $conversation, string $text, array $fileIds = []): array
    {
        $user = $conversation->user;
        $text = trim($text);

        $this->guard($conversation, $user, $text, $fileIds);

        $model = $this->resolveModel($conversation, $fileIds !== []);

        return DB::transaction(function () use ($conversation, $text, $fileIds, $model, $user) {
            $userMessage = Message::create([
                'conversation_id' => $conversation->getKey(),
                'role' => Message::ROLE_USER,
                'content' => $text,
                'status' => Message::STATUS_COMPLETE,
            ]);

            $this->attach($userMessage, $fileIds, $user);

            $assistant = Message::create([
                'conversation_id' => $conversation->getKey(),
                'role' => Message::ROLE_ASSISTANT,
                'content' => '',
                'status' => Message::STATUS_PENDING,
                'model_id' => $model->getKey(),
                'provider_id' => $model->provider_id,
                'parent_message_id' => $userMessage->getKey(),
            ]);

            // The first message names the conversation. Not an AI call: that
            // would double what a chat costs for something the first few words
            // already answer.
            if (blank($conversation->title)) {
                $conversation->forceFill(['title' => $conversation->titleFrom($text)])->save();
            }

            $conversation->touchLastMessage();
            $this->limiter->hit($user);

            return ['user' => $userMessage, 'assistant' => $assistant, 'model' => $model];
        });
    }

    /**
     * Regenerate an answer.
     *
     * §15 requires the original to be preserved, so this creates a NEW row
     * pointing back at the one it replaces. Both survive; only the replacement
     * is shown.
     */
    public function beginRegeneration(Message $previous): array
    {
        $conversation = $previous->conversation;
        $user = $conversation->user;

        if ($this->limiter->tooManyAttempts($user)) {
            throw ChatRefused::rateLimited($this->limiter->availableIn($user));
        }

        $model = $this->resolveModel($conversation, false);

        $replacement = Message::create([
            'conversation_id' => $conversation->getKey(),
            'role' => Message::ROLE_ASSISTANT,
            'content' => '',
            'status' => Message::STATUS_PENDING,
            'model_id' => $model->getKey(),
            'provider_id' => $model->provider_id,
            'parent_message_id' => $previous->parent_message_id,
            // The link that keeps history intact.
            'regenerated_from_id' => $previous->getKey(),
        ]);

        $this->limiter->hit($user);

        return ['assistant' => $replacement, 'model' => $model];
    }

    // -- producing the answer ------------------------------------------------

    /**
     * Stream an answer, yielding text as it arrives.
     *
     * @return Generator<int, string>
     */
    public function stream(Message $assistant): Generator
    {
        $model = $assistant->model;
        $adapter = $this->registry->for($model->provider);

        if (! $adapter instanceof SupportsStreaming) {
            // Not a failure: the provider simply cannot stream, so the whole
            // answer is produced at once and yielded in one piece. R-01's
            // fallback, reached without the caller needing to know.
            yield $this->complete($assistant);

            return;
        }

        $request = $this->buildRequest($assistant, stream: true);
        $startedAt = hrtime(true);
        $buffer = '';
        $stopped = false;

        $assistant->forceFill(['status' => Message::STATUS_STREAMING])->save();

        try {
            foreach ($adapter->streamChat($request) as $fragment) {
                if ($this->shouldStop($assistant)) {
                    $stopped = true;
                    break;
                }

                $buffer .= $fragment;

                // Persisted as it goes, so a dropped connection leaves the
                // customer with what had arrived rather than with nothing.
                $assistant->forceFill(['content' => $buffer])->save();

                yield $fragment;
            }
        } catch (ProviderFailed $e) {
            $this->settleFailure($assistant, $e, $buffer, $this->elapsed($startedAt));

            throw $e;
        }

        $this->settleStream($assistant, $buffer, $this->elapsed($startedAt), $stopped);
    }

    /**
     * Produce the whole answer in one call.
     *
     * The non-streaming path required by risk R-01: shared hosting buffers
     * output and times connections out, so streaming cannot be the only way to
     * get an answer. The platform degrades to this rather than failing.
     */
    public function complete(Message $assistant): string
    {
        $model = $assistant->model;
        $adapter = $this->registry->for($model->provider);

        if (! $adapter instanceof SupportsChat) {
            $failure = new ProviderFailed(ErrorClass::INVALID_REQUEST);
            $this->settleFailure($assistant, $failure, '', 0);

            throw $failure;
        }

        $assistant->forceFill(['status' => Message::STATUS_STREAMING])->save();

        try {
            $response = $adapter->chat($this->buildRequest($assistant, stream: false));
        } catch (ProviderFailed $e) {
            $this->settleFailure($assistant, $e, '', $e->latencyMs);

            throw $e;
        }

        $assistant->forceFill([
            'content' => $response->content,
            'status' => Message::STATUS_COMPLETE,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'latency_ms' => $response->latencyMs,
            'finished_at' => now(),
        ])->save();

        $this->recordHealth($assistant, true, $response->latencyMs, null, 200);
        $this->recordUsage($assistant, $response->usage->totalTokens());
        $assistant->conversation->touchLastMessage();

        return $response->content;
    }

    // -- stopping ------------------------------------------------------------

    /**
     * Ask the in-flight generation to stop.
     *
     * A flag rather than a signal: the streaming loop and the request that
     * started it may be in different processes, and a cache flag is the one
     * mechanism that works on every deployment mode without assuming Redis or
     * a persistent worker.
     */
    public function requestStop(Message $assistant): void
    {
        Cache::put(self::STOP_CACHE_PREFIX.$assistant->uuid, true, now()->addMinutes(10));
    }

    public function shouldStop(Message $assistant): bool
    {
        return (bool) Cache::get(self::STOP_CACHE_PREFIX.$assistant->uuid, false);
    }

    public function clearStop(Message $assistant): void
    {
        Cache::forget(self::STOP_CACHE_PREFIX.$assistant->uuid);
    }

    // -- internals -----------------------------------------------------------

    private function guard(Conversation $conversation, User $user, string $text, array $fileIds): void
    {
        if ($text === '' && $fileIds === []) {
            throw ChatRefused::empty();
        }

        $maxLength = (int) settings('chat.max_message_length');

        if (mb_strlen($text) > $maxLength) {
            throw ChatRefused::tooLong($maxLength);
        }

        $maxAttachments = (int) settings('chat.max_attachments');

        if (count($fileIds) > $maxAttachments) {
            throw ChatRefused::tooManyAttachments($maxAttachments);
        }

        if ($this->limiter->tooManyAttempts($user)) {
            throw ChatRefused::rateLimited($this->limiter->availableIn($user));
        }

        // One answer at a time per conversation. Two concurrent streams would
        // interleave into the same thread and bill twice for one question.
        $inFlight = $conversation->messages()
            ->whereIn('status', [Message::STATUS_PENDING, Message::STATUS_STREAMING])
            ->exists();

        if ($inFlight) {
            throw ChatRefused::alreadyAnswering();
        }
    }

    private function resolveModel(Conversation $conversation, bool $hasImages): AiModel
    {
        $required = $this->selector->requirementsFor($hasImages);
        $model = $this->selector->select($conversation, $required);

        if ($model) {
            return $model;
        }

        // Three different situations with three different remedies. Saying
        // "no model available" to all of them sends an owner looking in the
        // wrong place.
        if ($conversation->routing_mode === Conversation::ROUTING_SPECIFIC_MODEL) {
            throw ChatRefused::pinnedModelUnavailable();
        }

        if ($hasImages && $this->selector->auto([Capability::CHAT])) {
            throw ChatRefused::needsVision();
        }

        throw ChatRefused::noModel();
    }

    /** @param array<int, int> $fileIds */
    private function attach(Message $message, array $fileIds, User $user): void
    {
        if ($fileIds === []) {
            return;
        }

        // Only the customer's OWN files, and never a quarantined one. A file
        // id arrives from a browser, so ownership is proved here rather than
        // assumed.
        $files = File::whereIn('id', $fileIds)
            ->where('user_id', $user->getKey())
            ->whereNull('quarantined_at')
            ->get();

        foreach ($files as $file) {
            MessageAttachment::create([
                'message_id' => $message->getKey(),
                'file_id' => $file->getKey(),
                'kind' => str_starts_with((string) $file->detected_mime, 'image/') ? 'image' : 'file',
            ]);
        }
    }

    private function buildRequest(Message $assistant, bool $stream): ChatRequest
    {
        $conversation = $assistant->conversation;
        $model = $assistant->model;

        // For a regeneration, history must be the conversation AS IT WAS —
        // the answer being replaced must not be fed back in as context.
        $upTo = $assistant->regenerated_from_id
            ? $assistant->replacementSource()
            : $assistant;

        return new ChatRequest(
            modelIdentifier: $model->model_identifier,
            messages: $this->context->build($conversation, $model, $upTo),
            maxTokens: min(
                (int) settings('chat.max_output_tokens'),
                $model->max_output_tokens ?: (int) settings('chat.max_output_tokens'),
            ),
            stream: $stream,
        );
    }

    private function settleStream(Message $assistant, string $content, int $latencyMs, bool $stopped): void
    {
        $outputTokens = $this->context->estimateTokens($content);

        $assistant->forceFill([
            'content' => $content,
            // A stopped answer keeps what arrived. It was produced and paid
            // for; discarding it would lose work the customer already owns.
            'status' => $stopped ? Message::STATUS_STOPPED : Message::STATUS_COMPLETE,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            'finished_at' => now(),
        ])->save();

        $this->clearStop($assistant);
        $this->recordHealth($assistant, true, $latencyMs, null, 200);
        $this->recordUsage($assistant, $outputTokens);
        $assistant->conversation->touchLastMessage();
    }

    private function settleFailure(Message $assistant, ProviderFailed $failure, string $partial, int $latencyMs): void
    {
        $assistant->forceFill([
            'content' => $partial,
            'status' => Message::STATUS_FAILED,
            // A class, never the provider's words.
            'error_class' => $failure->errorClass,
            'latency_ms' => $latencyMs,
            'finished_at' => now(),
        ])->save();

        $this->clearStop($assistant);
        $this->recordHealth($assistant, false, $latencyMs, $failure->errorClass, $failure->httpStatus);
    }

    private function recordHealth(Message $assistant, bool $success, int $latencyMs, ?string $errorClass, ?int $status): void
    {
        ProviderHealthLog::create([
            'provider_id' => $assistant->provider_id,
            'model_id' => $assistant->model_id,
            'checked_at' => now(),
            'success' => $success,
            'latency_ms' => $latencyMs,
            'error_class' => $errorClass,
            'http_status' => $status,
        ]);
    }

    /** Rule 7: usage is counted per key so limits can be respected. */
    private function recordUsage(Message $assistant, int $tokens): void
    {
        $credential = $assistant->provider?->activeCredential();

        if ($credential) {
            CredentialUsageCounter::record($credential->getKey(), $tokens);
            $credential->forceFill(['last_used_at' => now()])->save();
        }
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
