<?php

namespace App\Providers;

use App\Domains\Knowledge\Contracts\VectorStore;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Policies\DocumentPolicy;
use App\Domains\Knowledge\Policies\KnowledgeBasePolicy;
use App\Domains\Knowledge\Stores\DatabaseVectorStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for file analysis and knowledge bases (§17).
 *
 * The vector store is BOUND, not instantiated at the call site — decision D-03
 * says the backend must be swappable without a rewrite, and a binding is what
 * makes that a configuration change rather than a search-and-replace.
 */
class KnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VectorStore::class, function () {
            return match (settings('knowledge.vector_store')) {
                // D-03: MySQL today. A pgvector or Qdrant implementation is a
                // second case here and nothing else changes.
                default => new DatabaseVectorStore,
            };
        });
    }

    public function boot(): void
    {
        Gate::policy(KnowledgeBase::class, KnowledgeBasePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
