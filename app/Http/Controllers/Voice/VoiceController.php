<?php

namespace App\Http\Controllers\Voice;

use App\Domains\Chat\Models\Message;
use App\Domains\Voice\Models\VoiceJob;
use App\Domains\Voice\Services\VoiceService;
use App\Domains\Voice\Support\VoiceRefused;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Voice, over HTTP (§18).
 *
 * WHY NOT LIVEWIRE. A recording is binary and can be several megabytes;
 * Livewire's temporary-upload path would base64 it into a component payload
 * and back out again. These are three small JSON endpoints the recorder posts
 * to directly, which is also what makes the browser gate able to drive them.
 *
 * OWNERSHIP IS CHECKED DIRECTLY, not through the Gate. Spatie registers a
 * `Gate::before` that grants a Super Admin everything, and the result of that
 * here would be an administrator able to read back a customer's recording.
 * This is the same reasoning as the streaming routes, and for the same reason.
 *
 * A REFUSAL IS A SENTENCE, never a provider's text. Every message that reaches
 * the browser comes from `VoiceRefused`, which the product wrote.
 */
class VoiceController extends Controller
{
    /** Take a recording and start transcribing it. */
    public function transcribe(Request $request, VoiceService $voice): JsonResponse
    {
        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
            'seconds' => ['required', 'numeric', 'min:0.2', 'max:3600'],
            'language' => ['nullable', 'string', 'max:12'],
        ]);

        $upload = $validated['audio'];

        try {
            $job = $voice->transcribe(
                user: $request->user(),
                // The BYTES, read once. Everything after this validates them
                // by content — there is no declared type to trust here.
                bytes: (string) file_get_contents($upload->getRealPath()),
                seconds: (float) $validated['seconds'],
                language: $validated['language'] ?? null,
            );
        } catch (VoiceRefused $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable) {
            // Anything else — a rejected file type, storage failing — is one
            // sentence. A raw exception message here could carry a path.
            return response()->json(['error' => __('That recording could not be used.')], 422);
        }

        return response()->json($this->state($job), 202);
    }

    /** Read a reply aloud. */
    public function speak(Request $request, Message $message, VoiceService $voice): JsonResponse
    {
        try {
            $job = $voice->speak($request->user(), $message);
        } catch (VoiceRefused $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($this->state($job), 202);
    }

    /** Where a job has got to. Polled while it is working. */
    public function show(Request $request, VoiceJob $voiceJob): JsonResponse
    {
        // Directly, not through the Gate — see the class docblock.
        abort_unless($voiceJob->user_id === $request->user()?->getKey(), 404);

        return response()->json($this->state($voiceJob));
    }

    /**
     * What the browser is told.
     *
     * Deliberately four fields. No model name, no cost, no file path: the
     * recorder needs to know whether it is done, what was heard, and where to
     * play it from.
     *
     * @return array<string, mixed>
     */
    private function state(VoiceJob $job): array
    {
        // REFRESHED, because the model in hand was read before the job ran.
        // On a host running the queue synchronously the work is already
        // finished by the time this returns, and reporting "queued" would send
        // the browser polling for something that has already happened.
        $job = $job->fresh(['file']) ?? $job;

        return [
            'uuid' => $job->uuid,
            'status' => $job->status,
            'text' => $job->kind === VoiceJob::TRANSCRIPTION ? $job->text : null,
            'audio' => $job->isPlayable() ? route('media.show', $job->file) : null,
            'error' => $job->failure_reason,
        ];
    }
}
