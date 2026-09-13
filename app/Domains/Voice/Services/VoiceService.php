<?php

namespace App\Domains\Voice\Services;

use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Routing\AiRouter;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Billing\Services\EntitlementService;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Chat\Models\Message;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Voice\Jobs\SynthesiseSpeechJob;
use App\Domains\Voice\Jobs\TranscribeRecordingJob;
use App\Domains\Voice\Models\VoiceJob;
use App\Domains\Voice\Support\VoiceRefused;
use App\Models\User;

/**
 * Speaking to Aziv AI, and hearing it answer (§18).
 *
 * TWO DIRECTIONS, ONE SET OF RULES. A transcription and a synthesis are asked
 * the same four questions before either is allowed to start — is the feature
 * on, is there a model, is the customer within their allowance, and can they
 * afford it — and every one of those is answered HERE, before anything is
 * queued. A request that reaches the queue is one that will be paid for.
 *
 * NOTHING NAMES A PROVIDER. `Capability::TRANSCRIPTION` and
 * `Capability::SPEECH` go to `AiRouter` exactly as chat and embeddings do.
 *
 * THE QUOTA IS IN MINUTES AND COVERS BOTH DIRECTIONS, because that is how a
 * customer thinks about it and how every provider bills it. Speech is charged
 * by characters at most providers, so it is converted at a deliberately
 * pessimistic reading speed for the quota only — the CHARGE always uses the
 * provider's own unit and the model's own price.
 */
class VoiceService
{
    /**
     * Characters per second of speech, for quota arithmetic only.
     *
     * Deliberately slow — a real voice reads faster — so the estimate
     * over-counts against the allowance rather than under-counting. Erring the
     * other way would let a customer exceed a quota that exists to bound the
     * owner's bill.
     */
    private const CHARACTERS_PER_SECOND = 12.0;

    public function __construct(
        private readonly AiRouter $router,
        private readonly UsageRecorder $usage,
        private readonly CreditService $credits,
        private readonly EntitlementService $entitlements,
        private readonly SubscriptionService $subscriptions,
        private readonly FileStorage $files,
    ) {}

    public function inputEnabled(): bool
    {
        return (bool) settings('voice.input_enabled');
    }

    public function outputEnabled(): bool
    {
        return (bool) settings('voice.output_enabled');
    }

    /**
     * Is there a model for this direction?
     *
     * Asked WITHOUT recording a routing decision: the chat screen asks it on
     * every render to decide whether to show the microphone, and a routing log
     * per page view would bury the real ones.
     */
    public function availableModel(string $capability): ?AiModel
    {
        return $this->router->route([$capability], RoutingMode::LOWEST_COST, record: false)->model;
    }

    public function canTranscribe(): bool
    {
        return $this->inputEnabled() && $this->availableModel(Capability::TRANSCRIPTION) !== null;
    }

    public function canSpeak(): bool
    {
        return $this->outputEnabled() && $this->availableModel(Capability::SPEECH) !== null;
    }

    // -- speech in --------------------------------------------------------------

    /**
     * Take a recording and queue it for transcription.
     *
     * THE AUDIO IS STORED FIRST, before the provider is called, for the same
     * reason a chat turn saves the customer's message before calling one: a
     * failure must never lose what somebody said. The recording is on the
     * private disk under its own retention, and the transcript that comes out
     * of it becomes an ordinary chat message the customer owns.
     *
     * @param  string  $bytes  the recording, as the browser produced it
     *
     * @throws VoiceRefused
     */
    public function transcribe(User $user, string $bytes, float $seconds, ?string $language = null): VoiceJob
    {
        if (! $this->inputEnabled()) {
            throw VoiceRefused::inputDisabled();
        }

        if ($bytes === '') {
            throw VoiceRefused::empty();
        }

        $maximum = (int) settings('voice.max_recording_seconds');

        if ($seconds > $maximum + 2) {
            // Two seconds of slack: the browser's own duration is measured
            // from frames and is routinely a fraction over what the customer
            // saw on the timer.
            throw VoiceRefused::tooLong($maximum);
        }

        $this->subscriptions->ensureSubscription($user);
        $this->assertWithinAllowance($user, $seconds);

        $model = $this->availableModel(Capability::TRANSCRIPTION);

        if (! $model) {
            throw VoiceRefused::noModel();
        }

        // Validated by CONTENT against a fixed allowlist and a size ceiling
        // the panel cannot widen — a recording arrives without an upload
        // envelope, so there is nothing declared to check.
        $file = $this->files->storeGenerated($bytes, $user, 'voice_recording', __('recording'));

        $job = VoiceJob::create([
            'user_id' => $user->getKey(),
            'kind' => VoiceJob::TRANSCRIPTION,
            'model_id' => $model->getKey(),
            'file_id' => $file->getKey(),
            'language' => $language,
            'seconds' => round($seconds, 2),
            'status' => VoiceJob::QUEUED,
            'expires_at' => $this->expiryDate(),
        ]);

        $hold = $this->authoriseSpend($user, $model, new UsageMetrics(seconds: $seconds, requests: 1), $job);

        TranscribeRecordingJob::dispatch($job->getKey(), $hold?->getKey());

        return $job;
    }

    // -- speech out -------------------------------------------------------------

    /**
     * Read a reply aloud.
     *
     * ONE JOB PER MESSAGE, reused. Pressing play twice on the same reply must
     * not synthesise it twice and charge twice — the second press finds the
     * finished job and plays it.
     *
     * @throws VoiceRefused
     */
    public function speak(User $user, Message $message): VoiceJob
    {
        if (! $this->outputEnabled()) {
            throw VoiceRefused::outputDisabled();
        }

        if ($message->conversation?->user_id !== $user->getKey()) {
            throw VoiceRefused::notYours();
        }

        $existing = VoiceJob::ownedBy($user)
            ->where('kind', VoiceJob::SPEECH)
            ->where('message_id', $message->getKey())
            ->whereIn('status', [VoiceJob::QUEUED, VoiceJob::WORKING, VoiceJob::COMPLETED])
            ->latest('id')
            ->first();

        if ($existing && ($existing->isWorking() || $existing->file_id !== null)) {
            return $existing;
        }

        $text = trim((string) $message->content);

        if ($text === '') {
            throw VoiceRefused::empty();
        }

        // Truncated rather than refused: a customer pressing play on a long
        // reply wants to hear it, and turning one click into a large bill is
        // the thing to prevent, not the click.
        $text = mb_substr($text, 0, max(200, (int) settings('voice.max_speech_characters')));

        $this->subscriptions->ensureSubscription($user);
        $this->assertWithinAllowance($user, mb_strlen($text) / self::CHARACTERS_PER_SECOND);

        $model = $this->availableModel(Capability::SPEECH);

        if (! $model) {
            throw VoiceRefused::noModel();
        }

        $job = VoiceJob::create([
            'user_id' => $user->getKey(),
            'kind' => VoiceJob::SPEECH,
            'model_id' => $model->getKey(),
            'message_id' => $message->getKey(),
            'text' => $text,
            'characters' => mb_strlen($text),
            'status' => VoiceJob::QUEUED,
            'expires_at' => $this->expiryDate(),
        ]);

        $hold = $this->authoriseSpend(
            $user,
            $model,
            // Both units, because providers differ on which one they charge:
            // whichever the model is priced in is the one that produces a
            // number, and the other contributes nothing.
            new UsageMetrics(
                inputTokens: (int) ceil(mb_strlen($text) / 4),
                seconds: mb_strlen($text) / self::CHARACTERS_PER_SECOND,
                requests: 1,
            ),
            $job,
        );

        SynthesiseSpeechJob::dispatch($job->getKey(), $hold?->getKey());

        return $job;
    }

    // -- shared rules -----------------------------------------------------------

    /**
     * How much audio this customer has used in a window.
     *
     * Counts BOTH directions and includes jobs still working, so a customer
     * cannot queue their way past a quota while the first one is running.
     */
    public function secondsUsedSince(User $user, \DateTimeInterface $since): float
    {
        return (float) VoiceJob::ownedBy($user)
            ->where('created_at', '>=', $since)
            ->whereIn('status', [VoiceJob::QUEUED, VoiceJob::WORKING, VoiceJob::COMPLETED])
            ->sum('seconds');
    }

    /**
     * @throws VoiceRefused
     */
    private function assertWithinAllowance(User $user, float $seconds): void
    {
        $perDay = (int) settings('voice.max_minutes_per_day');

        if ($perDay > 0 && $this->secondsUsedSince($user, now()->startOfDay()) + $seconds > $perDay * 60) {
            throw VoiceRefused::dailyLimit($perDay);
        }

        $limit = $this->entitlements->limit($user, 'voice_minutes_per_period');

        if ($limit === null) {
            return;
        }

        $since = $this->entitlements->subscription($user)?->current_period_start ?? now()->startOfMonth();

        if ($this->secondsUsedSince($user, $since) + $seconds > $limit * 60) {
            throw VoiceRefused::planLimit((int) $limit);
        }
    }

    /**
     * @throws VoiceRefused
     */
    private function authoriseSpend(User $user, AiModel $model, UsageMetrics $estimate, VoiceJob $job): ?CreditHold
    {
        if (! $this->entitlements->meteringIsActive()) {
            return null;
        }

        $amount = (float) $this->usage->cost($model, $estimate)['credit_cost'];

        $hold = $this->credits->hold(
            user: $user,
            amount: $amount,
            referenceType: 'voice_job',
            referenceId: (string) $job->getKey(),
        );

        if (! $hold) {
            // The row was created before the hold, so it has to go: a job
            // nobody paid for that never runs is a row that says "queued" for
            // ever on the customer's screen.
            $job->forceFill([
                'status' => VoiceJob::FAILED,
                'failure_reason' => __('Not enough credits.'),
                'completed_at' => now(),
            ])->save();

            throw VoiceRefused::outOfCredits();
        }

        return $hold;
    }

    private function expiryDate(): ?\DateTimeInterface
    {
        $days = (int) settings('voice.retention_days');

        return $days > 0 ? now()->addDays($days) : null;
    }
}
