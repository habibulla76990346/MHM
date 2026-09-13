<?php

namespace App\Console\Commands;

use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Support\Capability;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Files\Models\File;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Payments\Adapters\FixtureGatewayAdapter;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Domains\Voice\Models\VoiceJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The purchasable plan and no-money gateway the responsive gate needs.
 *
 * WHY THE GATE NEEDS THIS. "Checkout completes on a 320px viewport" cannot be
 * checked against a page that redirects because no plan is on sale and no
 * gateway is configured. A screenshot of an error page is not a checked
 * screen.
 *
 * Development only, twice over: this command refuses to run in production, and
 * the gateway it configures refuses to operate there even if the row somehow
 * arrived.
 */
class TestFixturesCommand extends Command
{
    protected $signature = 'aziv:test-fixtures';

    protected $description = 'Create the local plan and development gateway the responsive gate checks checkout against';

    /**
     * A renewal that is genuinely due, for the test account.
     *
     * WHY THE GATE NEEDS THIS TOO. The renewal payment page is reached by a
     * SIGNED link, so the responsive runner cannot construct a URL for it —
     * it has to find one the way a customer does, from the billing page. That
     * only appears when a manual subscription is inside its notice window.
     *
     * Without it the newest money screen in the product is the one screen
     * nobody checks at 320px.
     */
    private function seedRenewal(): void
    {
        $user = User::where('email', TestUserCommand::EMAIL)->first();

        if (! $user) {
            $this->line('No test account yet — run aziv:test-user to include the renewal screen in the gate.');

            return;
        }

        // A SEPARATE PLAN from the one checkout buys, and not a public one.
        // Subscribing the test account to the purchasable plan would replace
        // its "Buy" button with "Your current plan", and the checkout screen
        // — the one Addendum A names by name — would stop being checked.
        $plan = Plan::firstOrCreate(
            ['slug' => 'responsive-renewal'],
            [
                'name' => 'Test Renewal Plan',
                'description' => 'A local plan with a renewal falling due, so the renewal payment page has something real to render.',
                'billing_cycle' => 'monthly',
                'credits_per_period' => 100,
                'status' => Plan::STATUS_ACTIVE,
                'is_public' => false,
                'sort_order' => 901,
            ],
        );

        PlanPrice::updateOrCreate(
            ['plan_id' => $plan->getKey(), 'currency' => strtoupper((string) settings('billing.base_currency'))],
            ['amount' => 499, 'is_active' => true],
        );

        Subscription::updateOrCreate(
            ['user_id' => $user->getKey(), 'plan_id' => $plan->getKey()],
            [
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now()->subMonth(),
                // Inside the notice window, so the invoice and the link exist.
                'current_period_end' => now()->addDay(),
                'currency' => strtoupper((string) settings('billing.base_currency')),
                'amount' => 499,
                'renewal_mechanism' => 'manual',
            ],
        );

        $this->line('Renewal due for '.$user->email.' — the billing page carries the payment link.');
    }

    /**
     * A collection with a document in it, for the test account.
     *
     * WHY THE GATE NEEDS THIS. Every admin table in this project was once
     * checked while empty, so no row action was ever measured. The Library is
     * the same shape: an empty state is a different screen from a list of
     * documents with a status and a Remove button on each.
     *
     * The document is written straight into the ready state. Indexing it for
     * real would need an embedding provider and a live API key, and a gate
     * fixture must not depend on either.
     */
    private function seedLibrary(): void
    {
        $user = User::where('email', TestUserCommand::EMAIL)->first();

        if (! $user) {
            return;
        }

        $base = KnowledgeBase::firstOrCreate(
            ['user_id' => $user->getKey(), 'name' => 'Responsive test collection'],
            ['scope' => KnowledgeBase::SCOPE_PERSONAL, 'created_by' => $user->getKey()],
        );

        $file = File::firstOrCreate(
            ['user_id' => $user->getKey(), 'original_name' => 'handbook.txt'],
            [
                'disk' => 'private',
                'path' => 'uploads/'.$user->getKey().'/handbook.txt',
                'stored_name' => 'handbook.txt',
                'detected_mime' => 'text/plain',
                'extension' => 'txt',
                'size_bytes' => 128,
                'checksum' => hash('sha256', 'responsive-fixture'),
                'purpose' => 'knowledge',
            ],
        );

        $ready = Document::updateOrCreate(
            ['knowledge_base_id' => $base->getKey(), 'file_id' => $file->getKey()],
            [
                'user_id' => $user->getKey(),
                'title' => 'handbook.txt',
                'status' => Document::STATUS_READY,
                'extractor_key' => 'plain-text',
                'character_count' => 128,
                'chunk_count' => 2,
                'extracted_at' => now(),
                'embedded_at' => now(),
            ],
        );

        // A failed one too: the row that shows a reason is a different layout
        // from the row that shows a passage count, and both have to survive
        // 320px.
        $failedFile = File::firstOrCreate(
            ['user_id' => $user->getKey(), 'original_name' => 'scan.pdf'],
            [
                'disk' => 'private',
                'path' => 'uploads/'.$user->getKey().'/scan.pdf',
                'stored_name' => 'scan.pdf',
                'detected_mime' => 'application/pdf',
                'extension' => 'pdf',
                'size_bytes' => 2048,
                'checksum' => hash('sha256', 'responsive-fixture-failed'),
                'purpose' => 'knowledge',
            ],
        );

        Document::updateOrCreate(
            ['knowledge_base_id' => $base->getKey(), 'file_id' => $failedFile->getKey()],
            [
                'user_id' => $user->getKey(),
                'title' => 'scan.pdf',
                'status' => Document::STATUS_FAILED,
                'failure_reason' => 'No readable text was found in this file. If it is a scan or a photo of a document, it needs to be converted to text first.',
            ],
        );

        $this->line('Library fixture ready: '.$ready->title.' in "'.$base->name.'".');
    }

    /**
     * A generated picture, a failed one, and some voice history (§16, §18).
     *
     * WHY THE GATE NEEDS THIS. An empty gallery has no row actions to measure,
     * and every admin table in the product was once checked while empty — so
     * no row action was measured for two whole phases. A completed row and a
     * failed row are different layouts and both have to survive 320px.
     *
     * The picture is a real PNG written to the private disk, so the gallery
     * renders an actual image through the media route rather than a
     * placeholder box that would pass a width check by being empty.
     */
    private function seedMedia(): void
    {
        $user = User::where('email', TestUserCommand::EMAIL)->first();

        if (! $user) {
            return;
        }

        $bytes = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        $path = 'generated/'.$user->getKey().'/responsive-fixture.png';
        Storage::disk('private')->put($path, $bytes);

        $file = File::firstOrCreate(
            ['user_id' => $user->getKey(), 'original_name' => 'responsive-fixture.png'],
            [
                'disk' => 'private',
                'path' => $path,
                'stored_name' => 'responsive-fixture.png',
                'detected_mime' => 'image/png',
                'extension' => 'png',
                'size_bytes' => strlen($bytes),
                'checksum' => hash('sha256', $bytes),
                'purpose' => 'image_generation',
            ],
        );

        ImageGeneration::updateOrCreate(
            ['user_id' => $user->getKey(), 'prompt' => 'a quiet street at dawn, watercolour'],
            [
                'file_id' => $file->getKey(),
                'status' => ImageGeneration::COMPLETED,
                'size' => '1024x1024',
                'quality' => 'standard',
                'credit_cost' => 2,
                'completed_at' => now(),
            ],
        );

        ImageGeneration::updateOrCreate(
            ['user_id' => $user->getKey(), 'prompt' => 'something the provider would not make'],
            [
                'status' => ImageGeneration::FAILED,
                'failure_reason' => 'The provider would not generate that. Try describing it differently.',
                'size' => '1024x1024',
                'completed_at' => now(),
            ],
        );

        VoiceJob::updateOrCreate(
            ['user_id' => $user->getKey(), 'kind' => VoiceJob::TRANSCRIPTION, 'text' => 'a recording that was transcribed'],
            [
                'status' => VoiceJob::COMPLETED,
                'seconds' => 12.5,
                'characters' => 32,
                'credit_cost' => 1,
                'completed_at' => now(),
            ],
        );

        $this->line('Media fixtures ready: one generated image, one failure, one transcription.');
    }

    /**
     * A provider and models the gates can actually reach a decision through.
     *
     * WHY THIS EXISTS. `ChatService` refuses a turn when no chat model is
     * enabled — correctly — and the voice gate needs the composer to ACCEPT a
     * message so it can prove the transcript reached the server. Without a
     * model in the catalog the gate cannot tell "Livewire never saw the
     * transcript" apart from "there was nothing to send it to", which is the
     * difference between a gate and a coin toss.
     *
     * The base URL points nowhere on purpose. The reply fails, which is fine:
     * `beginTurn()` saves the customer's message BEFORE calling a provider, so
     * the words are on the screen either way — and no test ever depends on a
     * network the CI runner may not have.
     */
    private function seedCatalog(): void
    {
        $provider = AiProvider::firstOrCreate(
            ['slug' => 'responsive-test-provider'],
            [
                'name' => 'Responsive test provider (reaches nothing)',
                'adapter_type' => OpenAiCompatibleAdapter::KEY,
                'api_base_url' => 'https://api.responsive-test.invalid/v1',
                'auth_method' => 'bearer',
                'status' => AiProvider::STATUS_ACTIVE,
                'timeout_seconds' => 5,
            ],
        );

        AiProviderCredential::firstOrCreate(
            ['provider_id' => $provider->getKey()],
            ['label' => 'Local', 'credential' => 'sk-responsive-KEYKEYKEY1234'],
        );

        foreach ([
            Capability::CHAT => 'talker-local',
            Capability::IMAGE_GENERATION => 'painter-local',
            Capability::TRANSCRIPTION => 'ears-local',
            Capability::SPEECH => 'mouth-local',
        ] as $capability => $identifier) {
            $model = AiModel::firstOrCreate(
                ['provider_id' => $provider->getKey(), 'model_identifier' => $identifier],
                ['display_name' => ucfirst(str_replace('-', ' ', $identifier)), 'is_enabled' => true],
            );

            $model->forceFill(['is_enabled' => true])->save();

            AiModelCapability::syncForModel($model, [$capability]);
        }

        $this->line('Catalog fixture ready: chat, image, transcription and speech models.');
        // Said out loud, because the next thing a developer runs is usually
        // aziv:diagnose and a red AI-provider row would otherwise look like a
        // defect rather than the fixture doing exactly what it says.
        $this->line('  These point at an address that does not resolve, on purpose — so no gate can');
        $this->line('  reach a network. `aziv:diagnose` will report AI provider connectivity RED here.');
    }

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to configure a no-money gateway in production.');

            return self::FAILURE;
        }

        $plan = Plan::firstOrCreate(
            ['slug' => 'responsive-test'],
            [
                'name' => 'Test Plan',
                'description' => 'A local plan the responsive gate buys, so checkout has something real to render.',
                'billing_cycle' => 'monthly',
                'credits_per_period' => 100,
                'status' => Plan::STATUS_ACTIVE,
                'is_public' => true,
                'sort_order' => 900,
            ],
        );

        $plan->forceFill(['status' => Plan::STATUS_ACTIVE, 'is_public' => true])->save();

        PlanPrice::updateOrCreate(
            ['plan_id' => $plan->getKey(), 'currency' => strtoupper((string) settings('billing.base_currency'))],
            ['amount' => 499, 'is_active' => true],
        );

        $gateway = PaymentGatewayRecord::firstOrCreate(
            ['key' => FixtureGatewayAdapter::KEY],
            [
                'name' => 'Development gateway (takes no money)',
                'adapter_class' => FixtureGatewayAdapter::class,
                'mode' => PaymentGatewayRecord::MODE_SANDBOX,
                'priority' => 900,
                'capabilities' => app(FixtureGatewayAdapter::class)->capabilities(),
                'checkout_mode' => app(FixtureGatewayAdapter::class)->checkoutMode(),
                'supported_currencies' => [],
                'supported_countries' => [],
            ],
        );

        $gateway->forceFill(['status' => PaymentGatewayRecord::STATUS_ACTIVE])->save();

        PaymentGatewayCredential::updateOrCreate(
            ['gateway_id' => $gateway->getKey(), 'mode' => PaymentGatewayRecord::MODE_SANDBOX],
            [
                'label' => 'Development',
                'credentials' => ['key_id' => 'fixture', 'key_secret' => 'fixture'],
                'webhook_secret' => 'fixture',
                'publishable_key' => 'fixture-publishable',
                'status' => 'active',
            ],
        );

        $this->seedRenewal();
        $this->seedCatalog();
        $this->seedLibrary();
        $this->seedMedia();

        $this->info('Test plan ready: '.$plan->uuid);
        $this->line('Checkout: /checkout/'.$plan->uuid);

        return self::SUCCESS;
    }
}
