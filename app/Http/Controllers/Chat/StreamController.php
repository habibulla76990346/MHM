<?php

namespace App\Http\Controllers\Chat;

use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Support\ErrorClass;
use App\Domains\Chat\Models\Message;
use App\Domains\Chat\Services\ChatService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Server-sent events for a reply in progress (§15).
 *
 * WHY SSE AND NOT WEBSOCKETS. A reply is one-way, server to browser. SSE is
 * plain HTTP — it needs no extra service, no persistent worker and no open
 * port, so it works on shared hosting where it works at all and needs nothing
 * new on a VPS. A WebSocket would add infrastructure for a feature that does
 * not need a return channel.
 *
 * RISK R-01. Shared hosting frequently buffers output and cuts long
 * connections, which breaks streaming outright. The fallback is not an error
 * path — `/chat/{message}/complete` produces the same answer in one response —
 * and the browser uses it whenever streaming is switched off or the stream
 * fails to start. The platform degrades; it does not stop working.
 */
class StreamController extends Controller
{
    public function __invoke(Message $message, ChatService $chat): StreamedResponse
    {
        $this->mustOwn($message);

        return response()->stream(function () use ($message, $chat) {
            // Anything already buffered must go now, or the first token sits
            // in a buffer until the whole reply is done — which looks exactly
            // like streaming being broken.
            $this->flush();

            try {
                foreach ($chat->stream($message) as $fragment) {
                    $this->event('delta', ['text' => $fragment]);
                }

                $settled = $message->fresh();

                $this->event('done', [
                    'status' => $settled->status,
                    'content' => $settled->content,
                    'model' => $settled->model?->display_name,
                    'tokens' => $settled->totalTokens(),
                    'latency_ms' => $settled->latency_ms,
                ]);
            } catch (ProviderFailed $e) {
                // A class and a remedy. The provider's own words never reach a
                // browser: several APIs echo the failing request, and that
                // request carried the key.
                $this->event('error', [
                    'error_class' => $e->errorClass,
                    'message' => ErrorClass::label($e->errorClass),
                    'detail' => $e->action(),
                    'retryable' => $e->isRetryable(),
                ]);
            } catch (\Throwable) {
                $this->event('error', [
                    'error_class' => ErrorClass::UNKNOWN,
                    'message' => ErrorClass::label(ErrorClass::UNKNOWN),
                    'detail' => ErrorClass::action(ErrorClass::UNKNOWN),
                    'retryable' => false,
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            // Nginx buffers proxied responses by default, which defeats SSE
            // entirely. This is the documented way to ask it not to.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The non-streaming path (R-01).
     *
     * Same answer, one response. Used when the owner has switched streaming
     * off, or when the browser could not open a stream.
     */
    public function complete(Message $message, ChatService $chat): JsonResponse
    {
        $this->mustOwn($message);

        try {
            $content = $chat->complete($message);
        } catch (ProviderFailed $e) {
            return response()->json([
                'status' => 'failed',
                'error_class' => $e->errorClass,
                'message' => ErrorClass::label($e->errorClass),
                'detail' => $e->action(),
                'retryable' => $e->isRetryable(),
            ], 502);
        }

        $settled = $message->fresh();

        return response()->json([
            'status' => $settled->status,
            'content' => $content,
            'model' => $settled->model?->display_name,
            'tokens' => $settled->totalTokens(),
            'latency_ms' => $settled->latency_ms,
        ]);
    }

    public function stop(Message $message, ChatService $chat): JsonResponse
    {
        $this->mustOwn($message);

        $chat->requestStop($message);

        return response()->json(['stopping' => true]);
    }

    /**
     * Ownership, checked WITHOUT the Gate.
     *
     * Spatie registers its own `Gate::before` that grants a Super Admin every
     * ability, so `$this->authorize()` here would let an administrator read a
     * customer's conversation as it streamed. Reading someone's chats is a
     * support action with its own permission and its own audit trail — it is
     * not a side effect of being an administrator.
     *
     * An explicit comparison cannot be short-circuited by a Gate callback,
     * which is exactly why it is used for this one thing.
     */
    private function mustOwn(Message $message): void
    {
        abort_unless(
            $message->conversation?->user_id === auth()->id(),
            403,
        );
    }

    private function event(string $name, array $payload): void
    {
        echo 'event: '.$name."\n";
        echo 'data: '.json_encode($payload)."\n\n";

        $this->flush();
    }

    private function flush(): void
    {
        // ob_flush() errors when there is no buffer, which is the normal case
        // on some SAPIs — hence the guard rather than a bare call.
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }
}
