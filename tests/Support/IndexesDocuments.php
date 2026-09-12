<?php

namespace Tests\Support;

use App\Domains\AI\Adapters\OpenAiCompatibleAdapter;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Models\AiModelCapability;
use App\Domains\AI\Models\AiProvider;
use App\Domains\AI\Models\AiProviderCredential;
use App\Domains\AI\Support\Capability;
use App\Domains\Files\Models\File;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The fixtures every knowledge-base test needs: a customer, a fake embedding
 * provider, and a way to put a real file on the disk.
 *
 * A TRAIT RATHER THAN A BASE CLASS. Extending a test class re-runs every test
 * it declares in each subclass, so three suites sharing setup by inheritance
 * ran the indexing tests three times — slower, and a failure reported against
 * a class that does not contain it.
 */
trait IndexesDocuments
{
    protected User $user;

    protected AiModel $embedModel;

    /**
     * Whether the embedding provider is pretending to be down.
     *
     * Mutable state read by ONE stub, because a second `Http::fake()` for the
     * same pattern does not replace the first — Laravel keeps the first match,
     * so re-faking to simulate an outage silently keeps serving the healthy
     * response and the assertion passes vacuously.
     */
    protected bool $providerIsDown = false;

    protected function setUpKnowledgeFixtures(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('private');

        $this->user = User::factory()->create();
        $this->user->assignRole(PermissionRegistry::CUSTOMER);
        $this->user = $this->user->fresh();

        $this->embedModel = $this->configureEmbeddingProvider();
    }

    protected function configureEmbeddingProvider(): AiModel
    {
        $provider = AiProvider::create([
            'name' => 'Vectors Inc',
            'adapter_type' => OpenAiCompatibleAdapter::KEY,
            'api_base_url' => 'https://api.vectors.test/v1',
            'auth_method' => 'bearer',
            'status' => AiProvider::STATUS_ACTIVE,
        ]);

        AiProviderCredential::create([
            'provider_id' => $provider->getKey(),
            'label' => 'Key',
            'credential' => 'sk-vectors-KEYKEYKEY1234',
        ]);

        $model = AiModel::create([
            'provider_id' => $provider->getKey(),
            'model_identifier' => 'embed-small',
            'display_name' => 'Embed Small',
            'is_enabled' => true,
        ]);

        AiModelCapability::syncForModel($model, [Capability::EMBEDDINGS]);

        return $model->fresh('provider');
    }

    /** ONE stub reading mutable state. */
    protected function installEmbeddingStub(): void
    {
        Http::fake(['*' => function ($request) {
            if ($this->providerIsDown) {
                return Http::response(['error' => ['message' => 'down']], 500);
            }

            if (str_contains($request->url(), '/embeddings')) {
                return Http::response(FakeEmbedder::response($request->data()));
            }

            return Http::response(['data' => []]);
        }]);
    }

    protected function base(array $attributes = []): KnowledgeBase
    {
        return KnowledgeBase::create(array_merge([
            'name' => 'My documents',
            'scope' => KnowledgeBase::SCOPE_PERSONAL,
            'user_id' => $this->user->getKey(),
            'embedding_model_id' => $this->embedModel->getKey(),
            'chunk_size' => 300,
            'chunk_overlap' => 40,
            'min_score' => 0.05,
            'top_k' => 3,
        ], $attributes));
    }

    /** A real file on the fake disk, as the upload pipeline would leave it. */
    protected function upload(string $fixture, string $extension, ?string $contents = null): File
    {
        $contents ??= (string) file_get_contents(base_path('tests/Fixtures/documents/'.$fixture));
        $path = 'uploads/'.$this->user->getKey().'/'.$fixture;

        Storage::disk('private')->put($path, $contents);

        return File::create([
            'user_id' => $this->user->getKey(),
            'disk' => 'private',
            'path' => $path,
            'stored_name' => $fixture,
            'original_name' => $fixture,
            'detected_mime' => 'application/octet-stream',
            'extension' => $extension,
            'size_bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
            'purpose' => 'knowledge',
        ]);
    }
}
