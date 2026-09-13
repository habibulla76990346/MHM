<?php

namespace Tests\Feature\Images;

use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanFeature;
use App\Domains\Billing\Services\SubscriptionService;
use App\Domains\Credits\Models\CreditHold;
use App\Domains\Credits\Services\CreditService;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Images\Jobs\GenerateImageJob;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Images\Services\ImageService;
use App\Domains\Images\Support\ImageRefused;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GeneratesMedia;
use Tests\TestCase;

/**
 * Image generation, end to end against fixtures (§16).
 *
 * THE GATE THE PLAN NAMES is "image generation deducts correct credits" and
 * "failed generation refunds", and both are here as the arithmetic rather than
 * as a status check: a test that asserts a job ran without asserting what the
 * balance became would pass while charging twice.
 */
class ImageGenerationTest extends TestCase
{
    use GeneratesMedia, RefreshDatabase;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpMediaFixtures();
        $this->installMediaStub();

        // Two credits an image, so a wrong charge is visible arithmetic
        // rather than a rounding argument.
        $this->model = $this->mediaModel(Capability::IMAGE_GENERATION, 'painter-1', [
            'per_image' => [0.04, 2.0],
        ]);
    }

    /** Metering is off until a plan is published — this switches it on. */
    private function meter(int $credits = 100, array $features = []): void
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

        // No explicit grant: landing on the default plan is what credits an
        // account, and granting again on top would test a balance the product
        // never produces.
        app(SubscriptionService::class)->ensureSubscription($this->user);
    }

    private function images(): ImageService
    {
        return app(ImageService::class);
    }

    private function balance(): float
    {
        return (float) app(CreditService::class)->balance($this->user->fresh())->confirmed_balance;
    }

    // -- the happy path ---------------------------------------------------------

    public function test_a_picture_is_generated_stored_privately_and_charged_for(): void
    {
        $this->meter();

        $rows = $this->images()->request($this->user, 'a quiet street at dawn');

        $this->assertCount(1, $rows);
        $this->assertSame(ImageGeneration::QUEUED, $rows->first()->status);

        $generation = ImageGeneration::first()->fresh('file');

        $this->assertSame(ImageGeneration::COMPLETED, $generation->status);
        $this->assertNotNull($generation->file_id);

        // The bytes are on the PRIVATE disk. There is no code path in the
        // platform that writes an uploaded or generated file anywhere else.
        $this->assertSame('private', $generation->file->disk);
        $this->assertTrue(Storage::disk('private')->exists($generation->file->path));
        $this->assertSame('image/png', $generation->file->detected_mime);

        // Priced from the model's own per-image price, and STORED — editing
        // the price later must never rewrite what somebody paid.
        $this->assertSame(98.0, $this->balance(), 'One image at two credits should leave 98.');
        $this->assertEqualsWithDelta(2.0, (float) $generation->credit_cost, 0.000001);

        // The provider's rewrite is kept: it is the only way to answer "I
        // asked for a red car and got a blue one".
        $this->assertSame('a revised description', $generation->revised_prompt);
    }

    public function test_the_usage_log_records_an_image_rather_than_zero_tokens(): void
    {
        $this->meter();
        $this->images()->request($this->user, 'a lighthouse');

        $log = ApiUsageLog::where('capability', Capability::IMAGE_GENERATION)->firstOrFail();

        // "0 tokens, ₹4.20" reads as a bug. "1 image" reads as an image.
        $this->assertSame(1, (int) $log->images);
        $this->assertGreaterThan(0, (float) $log->credit_cost);
    }

    public function test_a_batch_takes_one_call_and_charges_for_every_image(): void
    {
        $this->meter();

        $this->images()->request($this->user, 'four moods of the same street', count: 3);

        $this->assertSame(3, ImageGeneration::where('status', ImageGeneration::COMPLETED)->count());
        $this->assertSame(1, ImageGeneration::distinct('batch_uuid')->count('batch_uuid'));
        $this->assertSame(94.0, $this->balance(), 'Three images at two credits should leave 94.');
    }

    // -- what happens when it goes wrong ----------------------------------------

    public function test_a_failed_generation_charges_nothing_and_says_why(): void
    {
        $this->meter();
        $this->providerStatus = 500;

        try {
            $this->images()->request($this->user, 'anything at all');
        } catch (\Throwable) {
            // The job rethrows so the queue can retry; the sync queue surfaces
            // it here. What matters is the state it left behind.
        }

        $generation = ImageGeneration::first();

        $this->assertSame(ImageGeneration::FAILED, $generation->status);
        $this->assertNotEmpty($generation->failure_reason);

        // A customer pays for pictures, never for attempts.
        $this->assertSame(100.0, $this->balance(), 'A failed generation must charge nothing.');
        $this->assertSame(0, CreditHold::where('status', CreditHold::HELD)->count(),
            'The hold was left open, so the balance is reserved for ever.');
    }

    public function test_a_provider_returning_something_that_is_not_an_image_is_refused(): void
    {
        $this->meter();

        // Exactly what a compromised or simply broken provider sends: an HTML
        // error page, with a 200, base64-encoded into the usual field.
        $this->imageBytes = '<!doctype html><html><body>error</body></html>';

        $this->images()->request($this->user, 'a portrait');

        $generation = ImageGeneration::first();

        $this->assertSame(ImageGeneration::FAILED, $generation->status,
            'HTML was written to disk as a picture.');
        $this->assertSame(0, ImageGeneration::whereNotNull('file_id')->count());
        $this->assertSame(100.0, $this->balance(), 'Nothing arrived, so nothing may be charged.');
    }

    public function test_a_partial_batch_charges_only_for_what_arrived(): void
    {
        $this->meter();

        // Three asked for, two returned — an ordinary outcome when a provider
        // filters one prompt out of a batch. Set as STATE the one stub reads,
        // not as a second `Http::fake()`: Laravel keeps the first match, so
        // re-faking would silently keep returning three.
        $this->imagesReturned = 2;

        $this->images()->request($this->user, 'three attempts', count: 3);

        $this->assertSame(2, ImageGeneration::where('status', ImageGeneration::COMPLETED)->count());
        $this->assertSame(1, ImageGeneration::where('status', ImageGeneration::FAILED)->count());
        $this->assertSame(96.0, $this->balance(), 'Two of three arrived, so four credits.');
    }

    public function test_a_retried_job_cannot_charge_twice(): void
    {
        $this->meter();

        Queue::fake();
        $rows = $this->images()->request($this->user, 'a bridge');
        Queue::assertPushed(GenerateImageJob::class);

        $batch = $rows->first()->batch_uuid;
        $hold = CreditHold::where('reference_id', $batch)->firstOrFail();

        // Run it, then run the SAME job again — which is what a queue does
        // after a worker dies between the provider call and the acknowledgement.
        (new GenerateImageJob($batch, $hold->getKey()))->handle(
            app(ProviderRegistry::class),
            app(FileStorage::class),
            app(UsageRecorder::class),
            app(CreditService::class),
        );

        $afterFirst = $this->balance();

        (new GenerateImageJob($batch, $hold->getKey()))->handle(
            app(ProviderRegistry::class),
            app(FileStorage::class),
            app(UsageRecorder::class),
            app(CreditService::class),
        );

        $this->assertSame($afterFirst, $this->balance(), 'The second run charged again.');
        $this->assertSame(1, ImageGeneration::where('status', ImageGeneration::COMPLETED)->count());
    }

    // -- refusals happen before anything is queued ------------------------------

    public function test_a_customer_without_credits_is_refused_before_a_job_is_queued(): void
    {
        $this->meter(credits: 1);   // one credit; an image costs two
        Queue::fake();

        $this->expectException(ImageRefused::class);

        try {
            $this->images()->request($this->user, 'something expensive');
        } finally {
            Queue::assertNothingPushed();
            $this->assertSame(0, ImageGeneration::count(),
                'A row was created for a request that was never going to run.');
        }
    }

    public function test_the_plan_allowance_is_enforced(): void
    {
        $this->meter(features: ['image_credits_per_period' => 2]);

        $this->images()->request($this->user, 'one');
        $this->images()->request($this->user, 'two');

        $this->expectException(ImageRefused::class);
        $this->images()->request($this->user, 'three');
    }

    public function test_the_platform_wide_daily_ceiling_applies_to_everybody(): void
    {
        // On top of any plan: it exists so one account cannot spend a month of
        // provider budget in an afternoon.
        settings()->set('images.max_per_day', 1);
        $this->meter();

        $this->images()->request($this->user, 'one');

        $this->expectException(ImageRefused::class);
        $this->images()->request($this->user, 'two');
    }

    public function test_the_feature_switch_refuses_before_anything_else(): void
    {
        settings()->set('images.enabled', false);

        $this->expectException(ImageRefused::class);
        $this->images()->request($this->user, 'anything');
    }

    public function test_no_model_is_a_sentence_rather_than_a_stack_trace(): void
    {
        $this->model->update(['is_enabled' => false]);

        $this->expectExceptionMessage('No image model is available');
        $this->images()->request($this->user, 'anything');
    }

    // -- regeneration and history -----------------------------------------------

    public function test_regenerating_creates_a_new_row_pointing_at_the_old_one(): void
    {
        $this->meter();

        $first = $this->images()->request($this->user, 'a heron')->first();
        $second = $this->images()->regenerate($this->user, $first->fresh())->first();

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame($first->getKey(), $second->regenerated_from_id);
        // BOTH survive, so the customer can compare them.
        $this->assertSame(2, ImageGeneration::count());
        $this->assertSame($first->prompt, $second->prompt);
    }

    public function test_regenerating_somebody_elses_image_is_refused(): void
    {
        $this->meter();
        $mine = $this->images()->request($this->user, 'mine')->first();

        $stranger = User::factory()->create();

        $this->expectException(ImageRefused::class);
        $this->images()->regenerate($stranger, $mine->fresh());
    }

    public function test_prompt_history_is_a_query_over_generations_not_a_second_table(): void
    {
        $this->meter();

        $this->images()->request($this->user, 'a heron');
        $this->images()->request($this->user, 'a heron');   // the same prompt twice
        $this->images()->request($this->user, 'a kingfisher');

        $history = $this->images()->promptHistory($this->user);

        $this->assertCount(2, $history, 'The same prompt should appear once.');
        $this->assertContains('a heron', $history);
        $this->assertContains('a kingfisher', $history);
    }

    // -- unmetered platforms still work -----------------------------------------

    public function test_an_owner_who_has_published_no_plan_still_generates_images(): void
    {
        // Metering begins when the owner publishes a plan, not when this code
        // shipped. No plan means no hold and no charge.
        $rows = $this->images()->request($this->user, 'a free picture');

        $this->assertSame(ImageGeneration::COMPLETED, $rows->first()->fresh()->status);
        $this->assertSame(0, CreditHold::count());
    }
}
