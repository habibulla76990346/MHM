<?php

namespace App\Domains\Knowledge\Jobs;

use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Services\IndexingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Give every passage a vector.
 *
 * RETRIED, unlike extraction — and for the opposite reason. A file that cannot
 * be read will never be readable, but a provider that returned a rate limit
 * will answer in a minute. The back-off is generous because the usual cause is
 * a per-minute cap that hammering only extends.
 */
class EmbedDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 600;

    public function __construct(private readonly int $documentId) {}

    public function handle(IndexingService $indexing): void
    {
        $document = Document::with('knowledgeBase')->find($this->documentId);

        if (! $document) {
            return;
        }

        $indexing->embed($document);
    }

    public function failed(Throwable $e): void
    {
        $document = Document::find($this->documentId);

        // After the last attempt the customer needs a sentence rather than a
        // document stuck at "Indexing" for ever.
        $document && app(IndexingService::class)->fail(
            $document,
            __('This file could not be indexed. Please try again, or contact support if it keeps happening.'),
        );
    }
}
