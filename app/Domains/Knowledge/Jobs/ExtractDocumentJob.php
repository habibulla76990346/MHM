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
 * Read a file into passages, off the request.
 *
 * Extraction of a large PDF is tens of seconds. Doing it in the upload request
 * would time out on shared hosting and hold a PHP worker on a VPS.
 *
 * ONE ATTEMPT, deliberately. A file that cannot be read cannot be read the
 * second time either, and retrying turns one clear failure into three
 * identical ones and a customer waiting three times as long to be told.
 */
class ExtractDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** A single very large document must not hold the queue for ever. */
    public int $timeout = 300;

    public function __construct(private readonly int $documentId) {}

    public function handle(IndexingService $indexing): void
    {
        $document = Document::with(['file', 'knowledgeBase'])->find($this->documentId);

        if (! $document) {
            return;
        }

        $indexing->extract($document);

        // Chained rather than done inline: extraction is CPU and embedding is
        // network, and a queue that dies mid-embedding should not have to
        // re-read a 400-page PDF to try again.
        if ($document->fresh()->status === Document::STATUS_EXTRACTED) {
            EmbedDocumentJob::dispatch($document->getKey());
        }
    }

    public function failed(Throwable $e): void
    {
        $document = Document::find($this->documentId);

        $document && app(IndexingService::class)->fail(
            $document,
            __('This file could not be read.'),
        );
    }
}
