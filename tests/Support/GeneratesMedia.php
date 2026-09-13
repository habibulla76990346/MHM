<?php

namespace Tests\Support;

use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiModelPrice;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fixtures for the image and voice suites: a customer, a provider that can do
 * all three media capabilities, and real bytes to hand back.
 *
 * A TRAIT RATHER THAN A BASE CLASS. Extending a test class re-runs every test
 * it declares in each subclass — three knowledge suites sharing setup by
 * inheritance ran the indexing tests three times before that was noticed.
 */
trait GeneratesMedia
{
    protected User $user;

    protected AiProvider $provider;

    /** Which failure, if any, the fake provider should produce. */
    protected ?int $providerStatus = null;

    /** Bytes the fake provider returns for an image. Not a PNG by default → set per test. */
    protected string $imageBytes = '';

    protected string $audioBytes = '';

    /**
     * How many images the fake provider returns, whatever was asked for.
     *
     * Null means "as many as asked". A NUMBER here is how a partial batch is
     * simulated — a second `Http::fake()` would not replace the first stub, so
     * every such test would silently keep the healthy response.
     */
    protected ?int $imagesReturned = null;

    /**
     * The duration the fake transcription provider reports, or null for none.
     *
     * Null is not unusual in the wild — several providers return the plain
     * form with no duration at all — and it is the case where the caller has
     * to fall back on what the browser measured.
     */
    protected ?float $transcriptSeconds = 4.5;

    protected function setUpMediaFixtures(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('private');

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $this->imageBytes = self::realPng();
        $this->audioBytes = self::realMp3();

        $this->provider = AiProvider::create([
            'name' => 'Media Provider',
            'adapter_type' => OpenAiCompatibleAdapter::KEY,
            'api_base_url' => 'https://api.media.test/v1',
            'auth_method' => 'bearer',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $this->provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-media-KEYKEYKEY1234',
        ]);
    }

    protected function mediaModel(string $capability, string $identifier, array $prices = []): AiModel
    {
        $model = AiModel::create([
            'provider_id' => $this->provider->getKey(),
            'model_identifier' => $identifier,
            'display_name' => ucfirst($identifier),
            'is_enabled' => true,
        ]);

        AiModelCapability::syncForModel($model, [$capability]);

        foreach ($prices as $unit => [$providerCost, $creditCost]) {
            AiModelPrice::create([
                'model_id' => $model->getKey(),
                'unit' => $unit,
                'provider_cost' => $providerCost,
                'currency' => 'USD',
                'credit_cost' => $creditCost,
                'effective_from' => now()->subDay(),
            ]);
        }

        return $model->fresh('provider');
    }

    /**
     * ONE stub, reading mutable state.
     *
     * A second `Http::fake()` for the same pattern does NOT replace the first
     * — Laravel keeps the first match — so a test that re-fakes an endpoint to
     * simulate a failure silently keeps the healthy response and every
     * assertion passes vacuously. That trap is documented in CLAUDE.md and it
     * has bitten this project.
     */
    protected function installMediaStub(): void
    {
        Http::fake(['*' => function ($request) {
            if ($this->providerStatus !== null) {
                return Http::response(['error' => ['message' => 'no']], $this->providerStatus);
            }

            $url = $request->url();

            if (str_contains($url, '/images/generations')) {
                $count = $this->imagesReturned ?? (int) ($request->data()['n'] ?? 1);

                if ($count < 1) {
                    return Http::response(['data' => []]);
                }

                return Http::response([
                    'data' => array_map(fn () => [
                        'b64_json' => base64_encode($this->imageBytes),
                        'revised_prompt' => 'a revised description',
                    ], range(1, max(1, $count))),
                ]);
            }

            if (str_contains($url, '/audio/transcriptions')) {
                return Http::response(array_filter([
                    'text' => 'the transcript of what was said',
                    'language' => 'en',
                    'duration' => $this->transcriptSeconds,
                ], static fn ($value) => $value !== null));
            }

            if (str_contains($url, '/audio/speech')) {
                return Http::response($this->audioBytes, 200, ['Content-Type' => 'audio/mpeg']);
            }

            return Http::response(['data' => []]);
        }]);
    }

    /**
     * A real 1x1 PNG.
     *
     * REAL BYTES, NOT A PLACEHOLDER STRING, because `storeGenerated()` reads
     * the type from the content — a fixture of "fake image data" would be
     * rejected exactly as a broken provider would be, and every test would
     * pass for the wrong reason.
     */
    protected static function realPng(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
    }

    /** An MPEG audio frame header, which is what finfo reads to say audio/mpeg. */
    protected static function realMp3(): string
    {
        return "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00".str_repeat("\x00", 100), 12);
    }

    /** A WebM container header, which is what a browser's recorder produces. */
    protected static function realWebm(): string
    {
        // EBML magic, then the DocType "webm" the sniffer looks for.
        return "\x1A\x45\xDF\xA3\x01\x00\x00\x00\x00\x00\x00\x23\x42\x86\x81\x01"
            ."\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm"
            .str_repeat("\x00", 512);
    }
}
