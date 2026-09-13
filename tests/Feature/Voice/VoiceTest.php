<?php

namespace Tests\Feature\Voice;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Support\Capability;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanFeature;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Voice\Models\VoiceJob;
use App\Domains\Voice\Services\VoiceService;
use App\Domains\Voice\Support\VoiceRefused;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeneratesMedia;
use Tests\TestCase;

/**
 * Speaking to Aziv AI and hearing it answer (§18).
 *
 * THE GATE THE PLAN NAMES is "STT/TTS round-trips", and the round trip here is
 * the whole product behaviour: a recording becomes a transcript the customer
 * can read and correct, and a reply becomes audio that plays. Everything else
 * in this suite is about the two things that cost money — credits and quotas —
 * and the one that costs trust: the recording.
 */
class VoiceTest extends TestCase
{
    use GeneratesMedia, RefreshDatabase;

    private AiModel $ears;

    private AiModel $mouth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMediaFixtures();
        $this->installMediaStub();

        // A credit a second, and a credit per thousand characters, so a wrong
        // charge is visible arithmetic rather than a rounding argument.
        $this->ears = $this->mediaModel(Capability::TRANSCRIPTION, 'ears-1', [
            'per_second' => [0.0001, 1.0],
        ]);
        $this->mouth = $this->mediaModel(Capability::SPEECH, 'mouth-1', [
            'per_1k_input' => [0.015, 1.0],
        ]);
    }

    private function meter(int $credits = 500, array $features = []): void
    {
        $plan = Plan::create([
            'name' => 'Standard',
            'slug' => 'standard',
            'status' => Plan::STATUS_ACTIVE,
            'is_public' => true,
            'is_default' => true,
            'billing_cycle' => 'monthly',
            'credits_per_period' => $credits,
        ]);

        foreach ($features as $key => $value) {
            PlanFeature::create([
                'plan_id' => $plan->getKey(),
                'key' => $key,
                'value' => (string) $value,
                'limit_type' => PlanFeature::HARD,
            ]);
        }

        app(SubscriptionService::class)->ensureSubscription($this->user);
    }

    private function voice(): VoiceService
    {
        return app(VoiceService::class);
    }

    private function balance(): float
    {
        return (float) app(CreditService::class)->balance($this->user->fresh())->confirmed_balance;
    }

    private function reply(string $text = 'Here is the answer you asked for.'): Message
    {
        $conversation = Conversation::create([
            'user_id' => $this->user->getKey(),
            'title' => 'A chat',
        ]);

        return Message::create([
            'conversation_id' => $conversation->getKey(),
            'role' => Message::ROLE_ASSISTANT,
            'content' => $text,
            'status' => Message::STATUS_COMPLETE,
        ]);
    }

    // -- speech in ---------------------------------------------------------------

    public function test_a_recording_becomes_a_transcript_and_is_charged_by_the_second(): void
    {
        $this->meter();

        $job = $this->voice()->transcribe($this->user, self::realWebm(), seconds: 5.0);

        $job = $job->fresh();

        $this->assertSame(VoiceJob::COMPLETED, $job->status);
        $this->assertSame('the transcript of what was said', $job->text);
        $this->assertSame('en', $job->language);

        // The PROVIDER'S duration where it gave one, not the browser's guess.
        $this->assertEqualsWithDelta(4.5, (float) $job->seconds, 0.01);
        $this->assertSame(495.5, $this->balance(), '4.5 seconds at one credit each.');
    }

    public function test_the_recording_itself_is_stored_privately_before_the_provider_is_called(): void
    {
        $this->meter();

        $job = $this->voice()->transcribe($this->user, self::realWebm(), seconds: 3.0)->fresh('file');

        // Saved BEFORE the call, for the same reason a chat turn saves the
        // customer's message first: a failure must never lose what was said.
        $this->assertNotNull($job->file_id);
        $this->assertSame('private', $job->file->disk);
        $this->assertSame('video/webm', $job->file->detected_mime);
        $this->assertTrue(Storage::disk('private')->exists($job->file->path));
    }

    public function test_nothing_is_sent_to_a_model_on_the_customers_behalf(): void
    {
        $this->meter();

        $this->voice()->transcribe($this->user, self::realWebm(), seconds: 2.0);

        // Speech recognition gets names, numbers and negations wrong. The
        // transcript is for the customer to read; sending it would be
        // answering a question nobody asked.
        $this->assertSame(0, Message::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_a_recording_longer_than_the_ceiling_is_refused(): void
    {
        settings()->set('voice.max_recording_seconds', 10);
        $this->meter();

        $this->expectException(VoiceRefused::class);
        $this->voice()->transcribe($this->user, self::realWebm(), seconds: 30.0);
    }

    public function test_something_that_is_not_audio_is_refused(): void
    {
        $this->meter();

        $this->expectExceptionMessage('not something Aziv AI stores');
        // A PNG renamed by nothing at all: the type is read from the bytes.
        $this->voice()->transcribe($this->user, self::realPng(), seconds: 2.0);
    }

    // -- speech out --------------------------------------------------------------

    public function test_a_reply_is_read_aloud_and_the_audio_is_playable(): void
    {
        $this->meter();

        $job = $this->voice()->speak($this->user, $this->reply())->fresh('file');

        $this->assertSame(VoiceJob::COMPLETED, $job->status);
        $this->assertTrue($job->isPlayable());
        $this->assertSame('audio/mpeg', $job->file->detected_mime);
        $this->assertSame('private', $job->file->disk);
    }

    public function test_pressing_play_twice_does_not_synthesise_twice(): void
    {
        $this->meter();
        $message = $this->reply();

        $first = $this->voice()->speak($this->user, $message);
        $before = $this->balance();

        $second = $this->voice()->speak($this->user, $message);

        $this->assertSame($first->getKey(), $second->getKey(), 'A second job was created.');
        $this->assertSame($before, $this->balance(), 'The customer was charged twice for one reply.');
        $this->assertSame(1, VoiceJob::where('kind', VoiceJob::SPEECH)->count());
    }

    public function test_a_very_long_reply_is_truncated_rather_than_refused(): void
    {
        settings()->set('voice.max_speech_characters', 200);
        $this->meter();

        $job = $this->voice()->speak($this->user, $this->reply(str_repeat('word ', 500)));

        // A customer pressing play wants to hear it; the thing to prevent is
        // one click becoming a large bill, not the click.
        $this->assertSame(200, $job->characters);
        $this->assertSame(VoiceJob::COMPLETED, $job->fresh()->status);
    }

    public function test_reading_somebody_elses_reply_aloud_is_refused(): void
    {
        $this->meter();
        $mine = $this->reply();

        $this->expectException(VoiceRefused::class);
        $this->voice()->speak(User::factory()->create(), $mine);
    }

    // -- failures cost nothing ----------------------------------------------------

    public function test_a_failed_transcription_charges_nothing_and_says_why(): void
    {
        $this->meter();
        $this->providerStatus = 500;

        try {
            $this->voice()->transcribe($this->user, self::realWebm(), seconds: 5.0);
        } catch (\Throwable) {
            // The job rethrows so the queue can retry.
        }

        $job = VoiceJob::first();

        $this->assertSame(VoiceJob::FAILED, $job->status);
        $this->assertNotEmpty($job->failure_reason);
        $this->assertSame(500.0, $this->balance(), 'A failed transcription must charge nothing.');
        $this->assertSame(0, CreditHold::where('status', CreditHold::HELD)->count());
    }

    public function test_a_provider_returning_something_that_is_not_audio_charges_nothing(): void
    {
        $this->meter();
        $this->audioBytes = '<!doctype html><body>an error page</body>';

        try {
            $this->voice()->speak($this->user, $this->reply());
        } catch (\Throwable) {
        }

        $this->assertSame(VoiceJob::FAILED, VoiceJob::first()->status);
        $this->assertSame(500.0, $this->balance());
    }

    // -- quotas -------------------------------------------------------------------

    public function test_the_platform_wide_daily_ceiling_covers_both_directions(): void
    {
        settings()->set('voice.max_minutes_per_day', 1);
        $this->meter();

        // No duration from the provider, so the figure the browser measured
        // stands — which is the case the quota has to hold for.
        $this->transcriptSeconds = null;

        $this->voice()->transcribe($this->user, self::realWebm(), seconds: 50.0);

        // 50 seconds used; a 20-second recording would exceed the minute.
        $this->expectException(VoiceRefused::class);
        $this->voice()->transcribe($this->user, self::realWebm(), seconds: 20.0);
    }

    public function test_the_plan_allowance_is_enforced(): void
    {
        settings()->set('voice.max_minutes_per_day', 0);
        $this->meter(features: ['voice_minutes_per_period' => 1]);
        $this->transcriptSeconds = null;

        $this->voice()->transcribe($this->user, self::realWebm(), seconds: 55.0);

        $this->expectException(VoiceRefused::class);
        $this->voice()->transcribe($this->user, self::realWebm(), seconds: 30.0);
    }

    public function test_a_customer_without_credits_is_refused_and_leaves_no_stuck_row(): void
    {
        // One credit; five seconds costs five.
        $this->meter(credits: 1);

        try {
            $this->voice()->transcribe($this->user, self::realWebm(), seconds: 5.0);
            $this->fail('The refusal never happened.');
        } catch (VoiceRefused) {
            // A row is created before the hold, so it must be closed out —
            // otherwise the customer's screen says "queued" for ever.
            $this->assertSame(VoiceJob::FAILED, VoiceJob::first()->status);
        }
    }

    // -- switches ------------------------------------------------------------------

    public function test_each_direction_can_be_switched_off_on_its_own(): void
    {
        $this->meter();

        settings()->set('voice.input_enabled', false);
        $this->assertFalse($this->voice()->canTranscribe());
        $this->assertTrue($this->voice()->canSpeak());

        settings()->set('voice.input_enabled', true);
        settings()->set('voice.output_enabled', false);
        $this->assertTrue($this->voice()->canTranscribe());
        $this->assertFalse($this->voice()->canSpeak());
    }

    public function test_a_switch_that_is_on_with_no_model_still_reports_unavailable(): void
    {
        // A control that always fails is worse than one that is not there: the
        // customer learns the product is broken, not that it is unconfigured.
        $this->ears->update(['is_enabled' => false]);

        $this->assertTrue((bool) settings('voice.input_enabled'));
        $this->assertFalse($this->voice()->canTranscribe());
    }

    // -- metering ---------------------------------------------------------------

    public function test_audio_seconds_reach_the_usage_log_rather_than_zero_tokens(): void
    {
        $this->meter();
        $this->voice()->transcribe($this->user, self::realWebm(), seconds: 6.0);

        $log = ApiUsageLog::where('capability', Capability::TRANSCRIPTION)->firstOrFail();

        $this->assertEqualsWithDelta(4.5, (float) $log->audio_seconds, 0.01);
        $this->assertGreaterThan(0, (float) $log->credit_cost);
    }

    public function test_an_owner_who_has_published_no_plan_still_uses_voice(): void
    {
        $job = $this->voice()->transcribe($this->user, self::realWebm(), seconds: 3.0);

        $this->assertSame(VoiceJob::COMPLETED, $job->fresh()->status);
        $this->assertSame(0, CreditHold::count());
    }
}
