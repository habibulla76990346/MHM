<?php

namespace App\Domains\Chat\Services;

use App\Domains\AI\Contracts\SupportsChat;
use App\Domains\AI\Contracts\SupportsStreaming;
use App\Domains\AI\DTO\ChatRequest;
use App\Domains\AI\DTO\ChatResponse;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\ProviderHealthLog;
use App\Domains\AI\Models\RoutingLog;
use App\Domains\AI\Routing\AiRouter;
use App\Domains\AI\Routing\CircuitBreaker;
use App\Domains\AI\Routing\RejectionReason;
use App\Domains\AI\Routing\RetryPolicy;
use App\Domains\AI\Routing\RoutingDecision;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Models\MessageAttachment;
use App\Domains\Chat\Models\MessageUsage;
use App\Domains\Chat\Support\ChatRefused;
use App\Domains\Files\Models\File;
use App\Models\User;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One turn of a conversation, end to end (§14, §15).
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
 *
 * PHASE 5 ADDS THE ROUTER. Which model answers is no longer a lookup, it is a
 * decision: capabilities are resolved from the request, every model is scored
 * against what this conversation's mode cares about, a transient failure is
 * retried on the same provider, and a provider that is genuinely down is
 * SUBSTITUTED — re-running the whole pipeline, so the substitute is filtered
 * by the same capability requirement the first choice was. That is what stops
 * a question about a picture being answered by a model that cannot see it.
 */
class ChatService
{
    private const STOP_CACHE_PREFIX = 'aziv:chat:stop:';

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly ContextBuilder $context,
        private readonly ModelSelector $selector,
        private readonly ChatRateLimiter $limiter,
        private readonly AiRouter $router,
        private readonly RetryPolicy $retries,
        private readonly CircuitBreaker $breaker,
        private readonly UsageRecorder $usage,
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

        $decision = $this->decide($conversation, $this->containsImages($fileIds, $user), $text);
        $model = $this->modelOrRefuse($decision, $conversation);

        return DB::transaction(function () use ($conversation, $text, $fileIds, $model, $decision, $user) {
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
                'routing_log_id' => $decision->log?->getKey(),
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

        $decision = $this->decide($conversation, $this->messageHasImages($previous->replacementSource() ?? $previous));
        $model = $this->modelOrRefuse($decision, $conversation);

        $replacement = Message::create([
            'conversation_id' => $conversation->getKey(),
            'role' => Message::ROLE_ASSISTANT,
            'content' => '',
            'status' => Message::STATUS_PENDING,
            'model_id' => $model->getKey(),
            'provider_id' => $model->provider_id,
            'routing_log_id' => $decision->log?->getKey(),
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
     * FALLBACK IS ONLY POSSIBLE BEFORE THE FIRST FRAGMENT. Once the customer
     * has seen text, switching models would splice two different answers
     * together in one bubble; the partial answer is settled as a failure
     * instead. A provider that dies on connect — the common case, and the one
     * §14's fallback exists for — is invisible to them.
     *
     * @return Generator<int, string>
     */
    public function stream(Message $assistant): Generator
    {
        $tried = [];

        while (true) {
            $model = $assistant->model;

            if (! $model) {
                throw $this->settleAndReturn($assistant, new ProviderFailed(ErrorClass::MODEL_NOT_FOUND), '', 0);
            }

            $tried[] = $model->getKey();
            $adapter = $this->registry->for($model->provider);

            if (! $adapter instanceof SupportsStreaming) {
                // Not a failure: the provider simply cannot stream, so the
                // whole answer is produced at once and yielded in one piece.
                // R-01's fallback, reached without the caller needing to know.
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
                $latency = $this->elapsed($startedAt);
                $this->recordAttempt($assistant, $model, false, $latency, $e);

                if ($buffer === '' && $this->substitute($assistant, $tried)) {
                    continue;
                }

                $this->settleFailure($assistant, $e, $buffer, $latency);

                throw $e;
            }

            $latency = $this->elapsed($startedAt);
            $this->recordAttempt($assistant, $model, true, $latency, null);
            $this->settleStream($assistant, $model, $buffer, $latency, $stopped);

            return;
        }
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
        $tried = [];

        while (true) {
            $model = $assistant->model;

            if (! $model) {
                throw $this->settleAndReturn($assistant, new ProviderFailed(ErrorClass::MODEL_NOT_FOUND), '', 0);
            }

            $tried[] = $model->getKey();
            $adapter = $this->registry->for($model->provider);

            if (! $adapter instanceof SupportsChat) {
                throw $this->settleAndReturn($assistant, new ProviderFailed(ErrorClass::INVALID_REQUEST), '', 0);
            }

            $assistant->forceFill(['status' => Message::STATUS_STREAMING])->save();

            try {
                $response = $this->attempt($assistant, $model, $adapter);
            } catch (ProviderFailed $e) {
                if ($this->substitute($assistant, $tried)) {
                    continue;
                }

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

            $this->recordUsage($assistant, $model, $response->usage, $response->latencyMs, 200);
            $assistant->conversation->touchLastMessage();

            return $response->content;
        }
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

    // -- routing -------------------------------------------------------------

    /**
     * Ask the router which model should answer.
     *
     * @param  array<int, int>  $exclude  models already tried on this request
     */
    public function decide(
        Conversation $conversation,
        bool $hasImages = false,
        string $pending = '',
        array $exclude = [],
        int $depth = 0,
    ): RoutingDecision {
        $mode = $conversation->effectiveRoutingMode();

        return $this->router->route(
            required: $this->router->resolveCapabilities($hasImages),
            mode: $mode,
            user: $conversation->user,
            pinnedProviderId: $mode === RoutingMode::SPECIFIC_PROVIDER ? $conversation->pinned_provider_id : null,
            pinnedModelId: $mode === RoutingMode::SPECIFIC_MODEL ? $conversation->pinned_model_id : null,
            conversationTokens: $this->conversationTokens($conversation, $pending),
            excludeModelIds: $exclude,
            fallbackDepth: $depth,
        );
    }

    /**
     * Three different situations with three different remedies. Saying "no
     * model available" to all of them sends an owner looking in the wrong
     * place, so the router's own account of why nothing survived is used.
     */
    private function modelOrRefuse(RoutingDecision $decision, Conversation $conversation): AiModel
    {
        if ($decision->model) {
            return $decision->model;
        }

        if ($decision->mode === RoutingMode::SPECIFIC_MODEL) {
            throw ChatRefused::pinnedModelUnavailable();
        }

        // A vision request where a text-only model WOULD have served: the
        // remedy is about the attachment, not about the platform.
        if (in_array(Capability::VISION, $decision->required, true)
            && $this->selector->auto([Capability::CHAT])) {
            throw ChatRefused::needsVision();
        }

        // WHAT A CUSTOMER IS TOLD IS NOT WHAT THE LOG RECORDS. The routing log
        // keeps the exact reason for the owner; a customer hears only the
        // things they can themselves act on. "The model is disabled" is an
        // administrator's sentence, not an answer to somebody trying to chat.
        throw match ($decision->dominantRejection()) {
            RejectionReason::CONTEXT_TOO_SMALL => ChatRefused::conversationTooLong(),
            RejectionReason::BUDGET_EXHAUSTED,
            RejectionReason::CIRCUIT_OPEN,
            RejectionReason::PROVIDER_MAINTENANCE => ChatRefused::temporarilyUnavailable(),
            default => ChatRefused::noModel(),
        };
    }

    /**
     * STAGE 5. Move this answer onto another model after a failure.
     *
     * Returns false when there is nothing left to try — a pinned conversation,
     * the fallback limit, or genuinely no other model that can do the job.
     *
     * @param  array<int, int>  $tried
     */
    private function substitute(Message $assistant, array $tried): bool
    {
        $conversation = $assistant->conversation;
        $depth = count($tried);

        // "One model" means one model. Substituting silently would make the
        // setting a lie, so a pinned conversation fails rather than quietly
        // becoming something else.
        if (RoutingMode::forbidsFallback($conversation->effectiveRoutingMode())) {
            return false;
        }

        // The owner's own limit on how far to chase an answer. At 0 there is
        // no fallback at all, which is a legitimate choice: some owners would
        // rather see a provider failing than have it papered over.
        if ($depth > $this->retries->maxFallbackDepth()) {
            return false;
        }

        $next = $this->decide(
            $conversation,
            $this->messageHasImages($assistant),
            exclude: $tried,
            depth: $depth,
        );

        if (! $next->model) {
            return false;
        }

        $assistant->forceFill([
            'model_id' => $next->model->getKey(),
            'provider_id' => $next->model->provider_id,
            'routing_log_id' => $next->log?->getKey() ?? $assistant->routing_log_id,
            'fallback_depth' => $depth,
            // The previous attempt's class must not stay on a row that is now
            // being answered by someone else.
            'error_class' => null,
        ])->save();

        $assistant->setRelation('model', $next->model);
        $assistant->unsetRelation('provider');

        return true;
    }

    // -- attempts ------------------------------------------------------------

    /**
     * STAGE 4. One model, with retries.
     *
     * Retries happen HERE rather than around the substitution, because the two
     * answer different questions: a retry is for a blip on a provider that is
     * otherwise fine, and a substitution is for a provider that is not. Trying
     * a different provider for a 429 would spread the load of one impatient
     * request across every provider the owner has.
     */
    private function attempt(Message $assistant, AiModel $model, SupportsChat $adapter): ChatResponse
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $adapter->chat($this->buildRequest($assistant, stream: false));
            } catch (ProviderFailed $e) {
                if ($this->retries->shouldRetry($e->errorClass, $attempt)) {
                    $this->pause($this->retries->delayMs($attempt, $e->retryAfterSeconds));

                    continue;
                }

                $this->recordAttempt($assistant, $model, false, $e->latencyMs, $e);

                throw $e;
            }

            $this->recordAttempt($assistant, $model, true, $response->latencyMs, null);

            return $response;
        }
    }

    /**
     * What happened, told to the two things that need to know: the health
     * record the router scores on, and the circuit breaker.
     */
    private function recordAttempt(Message $assistant, AiModel $model, bool $success, int $latencyMs, ?ProviderFailed $failure): void
    {
        ProviderHealthLog::create([
            'provider_id' => $model->provider_id,
            'model_id' => $model->getKey(),
            'checked_at' => now(),
            'success' => $success,
            'latency_ms' => $latencyMs,
            'error_class' => $failure?->errorClass,
            'http_status' => $failure?->httpStatus ?? ($success ? 200 : null),
        ]);

        $provider = $model->provider;

        if (! $provider) {
            return;
        }

        if ($success) {
            $this->breaker->recordSuccess($provider);

            return;
        }

        // A rejected key or a malformed request is not the provider being
        // down, and opening the circuit on it would take a healthy provider
        // out of rotation over a configuration mistake.
        if ($this->countsAgainstProvider($failure?->errorClass)) {
            $this->breaker->recordFailure($provider);
        }
    }

    private function countsAgainstProvider(?string $errorClass): bool
    {
        return in_array($errorClass, [
            ErrorClass::TIMEOUT,
            ErrorClass::NETWORK,
            ErrorClass::PROVIDER_ERROR,
            ErrorClass::RATE_LIMIT,
            ErrorClass::QUOTA_EXCEEDED,
            ErrorClass::UNKNOWN,
        ], true);
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

    /**
     * Does this turn need a model that can see?
     *
     * Asked of the FILES, before anything is stored — the same question stage
     * 1 of the router asks, phrased about the request rather than the model.
     *
     * @param  array<int, int>  $fileIds
     */
    private function containsImages(array $fileIds, User $user): bool
    {
        if ($fileIds === []) {
            return false;
        }

        return File::whereIn('id', $fileIds)
            ->where('user_id', $user->getKey())
            ->where('detected_mime', 'like', 'image/%')
            ->exists();
    }

    /**
     * The same question, asked of a turn that is already under way.
     *
     * This is what makes a FALLBACK safe: the substitute is chosen against the
     * requirement the original request had, not against a blank one.
     */
    private function messageHasImages(?Message $assistant): bool
    {
        $userMessage = $assistant?->parent_message_id
            ? Message::with('attachments')->find($assistant->parent_message_id)
            : null;

        return (bool) $userMessage?->attachments->contains(fn ($a) => $a->kind === 'image');
    }

    /**
     * Roughly how big this conversation is, so a model whose context window
     * cannot hold it is rejected rather than discovered mid-request.
     */
    private function conversationTokens(Conversation $conversation, string $pending = ''): int
    {
        $tokens = $this->context->estimateTokens($pending.' '.($this->context->systemPrompt($conversation) ?? ''));

        foreach ($conversation->messages()->pluck('content') as $content) {
            $tokens += $this->context->estimateTokens((string) $content);
        }

        return $tokens;
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

    private function settleStream(Message $assistant, AiModel $model, string $content, int $latencyMs, bool $stopped): void
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

        // A stream reports no token counts, so input is estimated from what
        // was sent. Estimated is recorded as a real figure deliberately: a
        // cost that silently reads zero for every streamed answer would make
        // the margin report worse than useless.
        $usage = new UsageMetrics(
            inputTokens: $assistant->input_tokens ?: $this->conversationTokens($assistant->conversation),
            outputTokens: $outputTokens,
        );

        $this->recordUsage($assistant, $model, $usage, $latencyMs, 200);
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
    }

    /** Settle a row and hand back the failure for the caller to throw. */
    private function settleAndReturn(Message $assistant, ProviderFailed $failure, string $partial, int $latencyMs): ProviderFailed
    {
        $this->settleFailure($assistant, $failure, $partial, $latencyMs);

        return $failure;
    }

    /**
     * Cost and tokens, recorded against the answer and against the account.
     *
     * Best-effort by construction (see UsageRecorder): analytics must never
     * cost a customer their answer.
     */
    private function recordUsage(Message $assistant, AiModel $model, UsageMetrics $usage, int $latencyMs, int $status): void
    {
        $assistant->forceFill([
            'input_tokens' => $usage->inputTokens ?: $assistant->input_tokens,
            'output_tokens' => $usage->outputTokens ?: $assistant->output_tokens,
        ])->save();

        $log = $this->usage->record(
            model: $model,
            usage: $usage,
            user: $assistant->conversation?->user,
            routingLog: $assistant->routing_log_id
                ? RoutingLog::find($assistant->routing_log_id)
                : null,
            latencyMs: $latencyMs,
            httpStatus: $status,
            capability: 'chat',
        );

        try {
            MessageUsage::updateOrCreate(
                ['message_id' => $assistant->getKey()],
                [
                    'usage_log_id' => $log?->getKey(),
                    'input_tokens' => $usage->inputTokens,
                    'output_tokens' => $usage->outputTokens,
                    'credit_cost' => $log?->credit_cost ?? 0,
                ],
            );
        } catch (\Throwable) {
            // See above: never at the cost of the answer.
        }
    }

    /**
     * Wait out a back-off.
     *
     * Extracted so a test can prove the retry policy without actually waiting
     * — and so there is exactly one place that sleeps in a request path.
     */
    protected function pause(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
