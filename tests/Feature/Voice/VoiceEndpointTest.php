<?php

namespace Tests\Feature\Voice;

use App\Domains\AI\Support\Capability;
use App\Domains\Chat\Models\Conversation;
use App\Domains\Chat\Models\Message;
use App\Domains\Security\Services\PermissionRegistry;
use App\Domains\Voice\Models\VoiceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\GeneratesMedia;
use Tests\TestCase;

/**
 * The three endpoints the recorder posts to (§18).
 *
 * WHAT THEY MUST NEVER DO is as important as what they do: they must not hand
 * one customer another's recording, must not leak a provider's own error text,
 * and must not let a Super Admin read a conversation. That last one is the
 * reason ownership is compared DIRECTLY here rather than through the Gate —
 * Spatie registers a `Gate::before` that grants a Super Admin everything, and
 * the streaming routes had to make the same choice for the same reason.
 */
class VoiceEndpointTest extends TestCase
{
    use GeneratesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMediaFixtures();
        $this->installMediaStub();
        $this->mediaModel(Capability::TRANSCRIPTION, 'ears-1');
        $this->mediaModel(Capability::SPEECH, 'mouth-1');

        $this->user->forceFill(['email_verified_at' => now()])->save();
        $this->user = $this->user->fresh();
    }

    private function recording(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('recording.webm', self::realWebm());
    }

    public function test_a_recording_is_accepted_and_a_job_is_reported_back(): void
    {
        $response = $this->actingAs($this->user)->post(route('voice.transcribe'), [
            'audio' => $this->recording(),
            'seconds' => 4.0,
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['uuid', 'status', 'text', 'audio', 'error']);

        $this->assertSame(1, VoiceJob::where('kind', VoiceJob::TRANSCRIPTION)->count());
    }

    public function test_the_response_says_nothing_about_which_provider_answered(): void
    {
        $response = $this->actingAs($this->user)->post(route('voice.transcribe'), [
            'audio' => $this->recording(),
            'seconds' => 4.0,
        ]);

        $body = $response->getContent();

        // Four fields and no more: no model name, no cost, no file path.
        $this->assertStringNotContainsString('Media Provider', $body);
        $this->assertStringNotContainsString('api.media.test', $body);
        $this->assertStringNotContainsString('ears-1', $body);
        $this->assertStringNotContainsString('sk-media', $body);
    }

    public function test_polling_somebody_elses_job_is_a_404(): void
    {
        $this->actingAs($this->user)->post(route('voice.transcribe'), [
            'audio' => $this->recording(),
            'seconds' => 2.0,
        ]);

        $job = VoiceJob::firstOrFail();

        $stranger = User::factory()->create(['email_verified_at' => now()]);
        $stranger->assignRole(PermissionRegistry::CUSTOMER);

        $this->actingAs($stranger->fresh())
            ->get(route('voice.show', $job))
            ->assertNotFound();
    }

    public function test_a_super_admin_cannot_read_a_customers_voice_job(): void
    {
        // Spatie's Gate::before grants a Super Admin everything, so ownership
        // here is compared directly — the same decision the streaming routes
        // had to make, and for the same reason.
        $this->actingAs($this->user)->post(route('voice.transcribe'), [
            'audio' => $this->recording(),
            'seconds' => 2.0,
        ]);

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $this->actingAs($admin->fresh())
            ->get(route('voice.show', VoiceJob::firstOrFail()))
            ->assertNotFound();
    }

    public function test_a_refusal_arrives_as_a_sentence_the_product_wrote(): void
    {
        settings()->set('voice.input_enabled', false);

        $response = $this->actingAs($this->user)->post(route('voice.transcribe'), [
            'audio' => $this->recording(),
            'seconds' => 2.0,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Speaking to Aziv AI is switched off.']);
    }

    public function test_something_that_is_not_audio_is_refused_without_leaking_a_path(): void
    {
        $response = $this->actingAs($this->user)->post(route('voice.transcribe'), [
            'audio' => UploadedFile::fake()->createWithContent('not-audio.webm', self::realPng()),
            'seconds' => 2.0,
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('/', (string) $response->json('error'));
    }

    public function test_an_enormous_recording_is_refused_by_validation(): void
    {
        $this->actingAs($this->user)->post(route('voice.transcribe'), [
            'audio' => UploadedFile::fake()->create('huge.webm', 30 * 1024),
            'seconds' => 2.0,
        ])->assertStatus(302);   // a validation redirect, before anything is read
    }

    public function test_reading_a_reply_aloud_returns_a_job(): void
    {
        $conversation = Conversation::create(['user_id' => $this->user->getKey(), 'title' => 'Chat']);
        $message = Message::create([
            'conversation_id' => $conversation->getKey(),
            'role' => Message::ROLE_ASSISTANT,
            'content' => 'The answer.',
            'status' => Message::STATUS_COMPLETE,
        ]);

        $this->actingAs($this->user)
            ->post(route('voice.speak', $message))
            ->assertStatus(202)
            ->assertJsonPath('status', VoiceJob::COMPLETED);
    }

    public function test_reading_somebody_elses_reply_aloud_is_refused(): void
    {
        $stranger = User::factory()->create(['email_verified_at' => now()]);
        $conversation = Conversation::create(['user_id' => $stranger->getKey(), 'title' => 'Theirs']);
        $message = Message::create([
            'conversation_id' => $conversation->getKey(),
            'role' => Message::ROLE_ASSISTANT,
            'content' => 'Private.',
            'status' => Message::STATUS_COMPLETE,
        ]);

        $this->actingAs($this->user)
            ->post(route('voice.speak', $message))
            ->assertStatus(422);
    }

    public function test_signing_out_is_enough_to_lose_access(): void
    {
        $this->post(route('voice.transcribe'), ['seconds' => 2.0])->assertRedirect(route('login'));
    }
}
