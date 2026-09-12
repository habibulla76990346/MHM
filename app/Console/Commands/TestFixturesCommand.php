<?php

namespace App\Console\Commands;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\PlanPrice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Files\Models\File;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Payments\Adapters\FixtureGatewayAdapter;
use App\Domains\Payments\Models\PaymentGatewayCredential;
use App\Domains\Payments\Models\PaymentGatewayRecord;
use App\Models\User;
use Illuminate\Console\Command;

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
        $this->seedLibrary();

        $this->info('Test plan ready: '.$plan->uuid);
        $this->line('Checkout: /checkout/'.$plan->uuid);

        return self::SUCCESS;
    }
}
