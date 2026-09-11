<?php

namespace Tests\Feature\AI;

use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\Security\Models\ActivityLog;
use App\Domains\Security\Services\ActivityLogger;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\RelationManagers\CredentialsRelationManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Phase 3 security gate: "credential encrypted at rest and never present
 * in any response body" (Rule 6, §25).
 *
 * Each of these asserts a DIFFERENT escape route, because a credential can
 * leak from the database, from a serialised model, from a rendered screen or
 * from an audit entry, and closing one says nothing about the others.
 */
class CredentialSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk-live-SUPERSECRET-ABCDEFGH9876';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function provider(): AiProvider
    {
        return AiProvider::create([
            'name' => 'Test Provider',
            'adapter_type' => 'openai_compatible',
            'api_base_url' => 'https://api.example.test/v1',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);
    }

    private function credential(?AiProvider $provider = null): AiProviderCredential
    {
        return AiProviderCredential::create([
            'provider_id' => ($provider ?? $this->provider())->getKey(),
            'label' => 'Main key',
            'credential' => self::SECRET,
        ]);
    }

    public function test_the_stored_value_is_ciphertext(): void
    {
        $credential = $this->credential();

        $stored = DB::table('ai_provider_credentials')->where('id', $credential->id)->value('credential');

        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertStringNotContainsString('sk-live', $stored);
        $this->assertNotSame(self::SECRET, $stored);
    }

    public function test_it_decrypts_back_to_the_original(): void
    {
        // Encryption that cannot round-trip is not encryption, it is loss.
        $this->assertSame(self::SECRET, $this->credential()->fresh()->secret());
    }

    /**
     * The structural control. `$hidden` means every toArray(), toJson(), API
     * resource and Livewire snapshot omits it — not by remembering to strip
     * it, but by never including it.
     */
    public function test_the_secret_cannot_reach_a_serialised_model(): void
    {
        $credential = $this->credential()->fresh();

        $this->assertArrayNotHasKey('credential', $credential->toArray());
        $this->assertArrayNotHasKey('extra_config', $credential->toArray());
        $this->assertStringNotContainsString(self::SECRET, $credential->toJson());
        $this->assertStringNotContainsString(self::SECRET, json_encode($credential));
        $this->assertStringNotContainsString(self::SECRET, serialize($credential->toArray()));
    }

    public function test_only_the_last_four_characters_are_ever_displayed(): void
    {
        $credential = $this->credential()->fresh();

        $this->assertSame('9876', $credential->hint);
        $this->assertSame('••••••••9876', $credential->masked());
        $this->assertStringNotContainsString('SUPERSECRET', $credential->masked());
    }

    /** A short value is not a key, and showing its last 4 would reveal most of it. */
    public function test_a_short_value_gets_no_hint_at_all(): void
    {
        $credential = AiProviderCredential::create([
            'provider_id' => $this->provider()->getKey(),
            'label' => 'Short',
            'credential' => 'abc123',
        ]);

        $this->assertNull($credential->fresh()->hint);
        $this->assertSame('••••••••', $credential->fresh()->masked());
    }

    public function test_replacing_a_key_updates_the_hint(): void
    {
        $credential = $this->credential();

        $credential->update(['credential' => 'sk-live-DIFFERENTKEY-ZZZZ1111']);

        // A hint describing a key that has been replaced would send an owner
        // hunting for the wrong key in a provider's dashboard.
        $this->assertSame('1111', $credential->fresh()->hint);
        $this->assertSame('sk-live-DIFFERENTKEY-ZZZZ1111', $credential->fresh()->secret());
    }

    /** The admin screen must not put the secret into the page it renders. */
    public function test_the_provider_screen_never_renders_the_secret(): void
    {
        $provider = $this->provider();
        $this->credential($provider);

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $response = $this->actingAs($admin->fresh())->get('/admin/ai-providers/'.$provider->uuid.'/edit');

        $response->assertOk();
        $response->assertDontSee(self::SECRET);
        $response->assertDontSee('SUPERSECRET');
    }

    /**
     * The key list is a separate Livewire component, so it needs asserting in
     * its own right — the edit page it sits on loads it lazily, and a secret
     * leaking there would not show up in the page's first render.
     */
    public function test_the_key_list_shows_the_hint_and_never_the_secret(): void
    {
        $provider = $this->provider();
        $this->credential($provider);

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        Livewire::actingAs($admin->fresh())
            ->test(CredentialsRelationManager::class, [
                'ownerRecord' => $provider,
                'pageClass' => EditAiProvider::class,
            ])
            ->assertSee('9876')
            ->assertDontSee(self::SECRET)
            ->assertDontSee('SUPERSECRET');
    }

    public function test_the_test_console_never_renders_the_secret(): void
    {
        $provider = $this->provider();
        $this->credential($provider);

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);

        $response = $this->actingAs($admin->fresh())->get('/admin/ai-test-console');

        $response->assertOk();
        $response->assertDontSee(self::SECRET);
        $response->assertDontSee('SUPERSECRET');
    }

    /**
     * An audit log that records a credential defeats the point of encrypting
     * it — and audit entries are read by more people than the key ever is.
     */
    public function test_the_audit_trail_records_the_key_by_hint_only(): void
    {
        $provider = $this->provider();
        $credential = $this->credential($provider);

        app(ActivityLogger::class)->log(
            'credential.added',
            $credential,
            null,
            ['label' => $credential->label, 'hint' => $credential->hint],
        );

        $entry = ActivityLog::latest('id')->first();

        $this->assertStringNotContainsString(self::SECRET, json_encode($entry->toArray()));
        $this->assertSame('9876', $entry->after['hint']);
    }

    public function test_credential_screens_are_permission_gated(): void
    {
        $provider = $this->provider();
        $this->credential($provider);

        $customer = User::factory()->create();
        $customer->assignRole(PermissionRegistry::CUSTOMER);

        $this->actingAs($customer->fresh())
            ->get('/admin/ai-providers/'.$provider->uuid.'/edit')
            ->assertForbidden();

        $this->actingAs($customer->fresh())
            ->get('/admin/ai-test-console')
            ->assertForbidden();
    }

    /**
     * Rule 7. Multiple keys exist for rotation and redundancy; per-key usage
     * is counted so limits can be RESPECTED. Nothing in the codebase may cycle
     * keys on a quota error, because that has no purpose but evasion.
     */
    public function test_no_automatic_key_cycling_exists(): void
    {
        $provider = $this->provider();

        $first = AiProviderCredential::create([
            'provider_id' => $provider->getKey(), 'label' => 'A',
            'credential' => 'sk-aaaaaaaaaaaaaaaa1111', 'priority' => 1,
        ]);
        AiProviderCredential::create([
            'provider_id' => $provider->getKey(), 'label' => 'B',
            'credential' => 'sk-bbbbbbbbbbbbbbbb2222', 'priority' => 2,
        ]);

        // The selection is by priority and status only — never by "the last
        // one hit its quota".
        $this->assertSame($first->id, $provider->activeCredential()->id);

        $first->update(['status' => AiProviderCredential::STATUS_REVOKED]);

        // A revoked key is skipped because an administrator marked it revoked,
        // which is a human decision, not an automatic response to a 429.
        $this->assertSame('B', $provider->fresh()->activeCredential()->label);
    }

    /**
     * Rule 7, asserted against the source rather than against behaviour.
     *
     * Behaviour tests can only show that cycling does not happen on the paths
     * they exercise. Reading the code shows that no path does it at all, which
     * is the actual commitment — and it fails loudly if someone adds one later
     * believing it to be a helpful retry.
     */
    public function test_no_quota_triggered_key_rotation_is_present_in_the_source(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Domains/AI'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            // Selecting another credential anywhere near quota or rate-limit
            // handling is the shape Rule 7 forbids.
            if (preg_match('/(QUOTA_EXCEEDED|RATE_LIMIT)/', $source)
                && preg_match('/(nextCredential|rotateCredential|tryNextKey|cycleCredential)/i', $source)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders,
            'Automatic key rotation on a quota or rate-limit error breaks provider terms (Rule 7): '
            .implode(', ', $offenders));
    }
}
