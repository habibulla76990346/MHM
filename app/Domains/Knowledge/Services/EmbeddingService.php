<?php

namespace App\Domains\Knowledge\Services;

use App\Domains\AI\Contracts\SupportsEmbeddings;
use App\Domains\AI\DTO\UsageMetrics;
use App\Domains\AI\Exceptions\ProviderFailed;
use App\Domains\AI\Models\AiModel;
use App\Domains\AI\Routing\AiRouter;
use App\Domains\AI\Routing\RoutingMode;
use App\Domains\AI\Services\ProviderRegistry;
use App\Domains\AI\Support\Capability;
use App\Domains\AI\Usage\UsageRecorder;
use App\Domains\Knowledge\Support\EmbeddingBatch;
use App\Models\User;
use RuntimeException;

/**
 * Turning text into vectors, through the layer that already exists.
 *
 * NOTHING HERE NAMES A PROVIDER OR A MODEL. It asks `AiRouter` for something
 * that can do `Capability::EMBEDDINGS` and gets whatever the owner has
 * enabled — so a knowledge base built while OpenAI was configured keeps
 * working when the owner switches to a self-hosted model, and an owner with no
 * embedding model gets a sentence rather than a stack trace.
 *
 * IT IS METERED LIKE EVERYTHING ELSE. Embedding a 400-page manual is a real
 * bill from a real provider, and an owner who cannot see that cost on the same
 * screen as their chat cost has no idea what knowledge bases are costing them.
 * So every batch goes through `UsageRecorder` with the capability recorded as
 * `embeddings`.
 *
 * BATCHES ARE BOUNDED. Providers cap both the number of inputs and the total
 * tokens per request, and the failure when you exceed it is a 400 that looks
 * like a bug in your code. Splitting here means the caller passes a whole
 * document and never thinks about it.
 */
class EmbeddingService
{
    /**
     * Inputs per request.
     *
     * Comfortably under every provider's limit rather than at anyone's
     * maximum: the cost of a second request is one round trip, and the cost of
     * guessing a limit wrong is a document that fails to index on one provider
     * and not another.
     */
    private const BATCH_SIZE = 64;

    /** Characters per batch, so one enormous chunk cannot blow the token cap. */
    private const BATCH_CHARACTERS = 60000;

    public function __construct(
        private readonly AiRouter $router,
        private readonly ProviderRegistry $registry,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * The model this text will be embedded with.
     *
     * Resolved once per document rather than per batch, because a document
     * whose first half was embedded by one model and second half by another is
     * a document whose search results are meaningless — the vectors are not in
     * the same space and the similarity between them is arithmetic, not
     * meaning.
     */
    public function model(?AiModel $preferred = null): AiModel
    {
        if ($preferred && $preferred->supports(Capability::EMBEDDINGS) && $preferred->is_enabled) {
            return $preferred;
        }

        $decision = $this->router->route([Capability::EMBEDDINGS], RoutingMode::LOWEST_COST);

        if (! $decision->model) {
            throw new RuntimeException(__(
                'No embedding model is available. Add a provider that offers embeddings, sync its catalog, and enable one of its embedding models.'
            ));
        }

        return $decision->model;
    }

    /**
     * Embed a list of texts, in order.
     *
     * @param  array<int, string>  $texts
     *
     * @throws ProviderFailed when the provider fails — the caller decides
     *                        whether that is worth retrying
     */
    public function embed(array $texts, AiModel $model, ?User $user = null): EmbeddingBatch
    {
        $adapter = $this->registry->for($model->provider);

        if (! $adapter instanceof SupportsEmbeddings) {
            // Should be unreachable — the router filters by capability — so if
            // it happens, the catalog claims something the adapter cannot do.
            throw new RuntimeException(__(
                ':model is listed as an embedding model, but :provider cannot produce embeddings.',
                ['model' => $model->display_name, 'provider' => $model->provider?->name],
            ));
        }

        $vectors = [];
        $characters = 0;
        $startedAt = hrtime(true);

        foreach ($this->batches($texts) as $batch) {
            $vectors = array_merge($vectors, $adapter->embed((string) $model->model_identifier, $batch));
            $characters += array_sum(array_map('mb_strlen', $batch));
        }

        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        // Providers do not report token counts on embedding calls
        // consistently, so this is an ESTIMATE and is recorded as one. A
        // visible approximation beats an invisible zero: an owner needs to see
        // that indexing cost something.
        $this->usage->record(
            model: $model,
            usage: new UsageMetrics(
                inputTokens: (int) ceil($characters / 4),
                outputTokens: 0,
            ),
            user: $user,
            latencyMs: $latencyMs,
            capability: Capability::EMBEDDINGS,
        );

        return new EmbeddingBatch(
            vectors: $vectors,
            model: (string) $model->model_identifier,
            dimensions: $vectors === [] ? 0 : count($vectors[0]),
            latencyMs: $latencyMs,
        );
    }

    /**
     * One text, for a search query.
     *
     * The same path as indexing, deliberately: a query embedded by a different
     * model than the documents cannot be compared with them at all.
     */
    public function embedQuery(string $text, AiModel $model, ?User $user = null): array
    {
        return $this->embed([$text], $model, $user)->vectors[0] ?? [];
    }

    /**
     * @param  array<int, string>  $texts
     * @return \Generator<int, array<int, string>>
     */
    private function batches(array $texts): \Generator
    {
        $batch = [];
        $characters = 0;

        foreach ($texts as $text) {
            $length = mb_strlen($text);

            if ($batch !== [] && (count($batch) >= self::BATCH_SIZE || $characters + $length > self::BATCH_CHARACTERS)) {
                yield $batch;
                $batch = [];
                $characters = 0;
            }

            $batch[] = $text;
            $characters += $length;
        }

        if ($batch !== []) {
            yield $batch;
        }
    }
}
