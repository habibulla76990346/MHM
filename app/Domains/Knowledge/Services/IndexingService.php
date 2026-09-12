<?php

namespace App\Domains\Knowledge\Services;

use App\Domains\Files\Models\File;
use App\Domains\Files\Services\ExtractorRegistry;
use App\Domains\Knowledge\Jobs\ExtractDocumentJob;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\DocumentChunk;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Support\Chunk;
use App\Domains\Knowledge\Support\Vector;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Taking a file from "uploaded" to "searchable" (§17).
 *
 * THE WHOLE PIPELINE IS QUEUED, because extracting a 400-page PDF and
 * embedding 900 chunks is minutes of work and a customer pressing upload must
 * get their page back. On shared hosting the queue runs from cron, so this is
 * slower there and never absent — the rule the whole platform follows.
 *
 * EVERY FAILURE ENDS AS A SENTENCE, not an exception. A document that cannot
 * be read is a normal outcome of accepting files from people, and the customer
 * needs to know which file and why, not a retry loop that fails identically
 * three times.
 */
class IndexingService
{
    public function __construct(
        private readonly ExtractorRegistry $extractors,
        private readonly Chunker $chunker,
        private readonly EmbeddingService $embeddings,
    ) {}

    /**
     * Put a file into a base and start the work.
     *
     * Idempotent per (base, file): the unique index means re-adding the same
     * file re-indexes it rather than creating a second copy that competes with
     * itself in every search result.
     */
    public function ingest(KnowledgeBase $base, File $file, ?User $actor = null): Document
    {
        if (! $this->extractors->for($file)) {
            throw new RuntimeException(__(
                'Aziv AI cannot read :ext files yet. It can read: :list.',
                ['ext' => strtoupper((string) $file->extension), 'list' => strtoupper(implode(', ', $this->extractors->extensions()))],
            ));
        }

        // A file that failed its scan never becomes searchable. The scan
        // verdict is Phase 1's decision and this only honours it.
        if ($file->isQuarantined()) {
            throw new RuntimeException(__('This file was quarantined by the security scan and cannot be indexed.'));
        }

        $document = Document::updateOrCreate(
            ['knowledge_base_id' => $base->getKey(), 'file_id' => $file->getKey()],
            [
                'user_id' => $actor?->getKey() ?? $file->user_id,
                'title' => $file->original_name,
                'status' => Document::STATUS_PENDING,
                'failure_reason' => null,
            ],
        );

        ExtractDocumentJob::dispatch($document->getKey());

        return $document->refresh();
    }

    /**
     * Read the file and cut it into passages.
     *
     * Chunks are replaced wholesale rather than merged: a re-index of a
     * changed document that kept old chunks would answer questions from text
     * the document no longer contains.
     */
    public function extract(Document $document): void
    {
        $file = $document->file;

        if (! $file) {
            $this->fail($document, __('The uploaded file is no longer available.'));

            return;
        }

        $extractor = $this->extractors->for($file);

        if (! $extractor) {
            $this->fail($document, __('Aziv AI cannot read this kind of file.'));

            return;
        }

        $document->forceFill(['status' => Document::STATUS_EXTRACTING])->save();

        $path = $this->localPath($file);

        if ($path === null) {
            $this->fail($document, __('The uploaded file could not be opened.'));

            return;
        }

        try {
            $result = $extractor->extract($file, $path);
        } catch (Throwable) {
            // An extractor that throws is a defect, but the customer's
            // document should still end in a state they can act on.
            $this->fail($document, __('This file could not be read.'));

            return;
        } finally {
            $this->cleanUp($file, $path);
        }

        if (! $result->usable) {
            $this->fail($document, (string) $result->reason);

            return;
        }

        $base = $document->knowledgeBase;

        $chunks = $this->chunker->chunk(
            $result->text,
            (int) $base->chunk_size,
            (int) $base->chunk_overlap,
            $result->markers,
        );

        if ($chunks === []) {
            $this->fail($document, __('No readable text was found in this file.'));

            return;
        }

        DB::transaction(function () use ($document, $base, $chunks, $result, $extractor) {
            $document->chunks()->delete();

            foreach ($chunks as $chunk) {
                DocumentChunk::create([
                    'document_id' => $document->getKey(),
                    'knowledge_base_id' => $base->getKey(),
                    'ordinal' => $chunk->ordinal,
                    'content' => $chunk->content,
                    'token_estimate' => $chunk->tokenEstimate(),
                    'locator' => $chunk->locator,
                    'checksum' => $chunk->checksum(),
                ]);
            }

            $document->forceFill([
                'status' => Document::STATUS_EXTRACTED,
                'extractor_key' => $extractor->key(),
                'character_count' => $result->length(),
                'chunk_count' => count($chunks),
                'token_estimate' => array_sum(array_map(fn (Chunk $c) => $c->tokenEstimate(), $chunks)),
                'extracted_at' => now(),
                'failure_reason' => null,
            ])->save();
        });
    }

    /**
     * Give every chunk a vector.
     *
     * ONE MODEL FOR THE WHOLE DOCUMENT, resolved once. A document whose first
     * half was embedded by one model and second half by another has vectors in
     * two different spaces, and the similarity between them is arithmetic
     * rather than meaning — a search that returns confident nonsense with no
     * error anywhere.
     */
    public function embed(Document $document): void
    {
        $chunks = $document->chunks()->orderBy('ordinal')->get();

        if ($chunks->isEmpty()) {
            $this->fail($document, __('There is nothing to index in this file.'));

            return;
        }

        $document->forceFill(['status' => Document::STATUS_EMBEDDING])->save();

        try {
            $model = $this->embeddings->model($document->knowledgeBase?->embeddingModel);

            $batch = $this->embeddings->embed(
                $chunks->pluck('content')->all(),
                $model,
                $document->owner,
            );
        } catch (Throwable $e) {
            // The owner's problem, phrased for the customer: something is
            // wrong with the platform's configuration, not with their file.
            $this->fail($document, __('This file could not be indexed. The platform could not reach its indexing service — the administrator has been shown the details.'));

            throw $e;
        }

        DB::transaction(function () use ($chunks, $batch, $document) {
            foreach ($chunks as $index => $chunk) {
                $vector = $batch->vectors[$index] ?? null;

                if ($vector === null) {
                    continue;
                }

                // forceFill: a vector is written with its model and dimensions
                // together or not at all, so nothing can store a vector that
                // cannot later be compared safely.
                $chunk->forceFill([
                    'embedding' => Vector::pack($vector),
                    'embedding_model' => $batch->model,
                    'dimensions' => $batch->dimensions,
                ])->save();
            }

            $document->forceFill([
                'status' => Document::STATUS_READY,
                'embedded_at' => now(),
                'failure_reason' => null,
            ])->save();
        });
    }

    public function fail(Document $document, string $reason): void
    {
        $document->forceFill([
            'status' => Document::STATUS_FAILED,
            // Plain words that reached here from an extractor or from this
            // service. Never an exception message — those carry paths.
            'failure_reason' => mb_substr($reason, 0, 250),
        ])->save();
    }

    /**
     * A readable local path for the file.
     *
     * Local disks are read in place. A remote disk (S3 later) is copied to a
     * temporary file first, because none of the extractors can stream and
     * pretending otherwise would work on a developer's machine and fail in
     * production.
     */
    private function localPath(File $file): ?string
    {
        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            return null;
        }

        try {
            return $disk->path($file->path);
        } catch (Throwable) {
            $temporary = tempnam(sys_get_temp_dir(), 'aziv-doc');
            file_put_contents($temporary, $disk->get($file->path));

            return $temporary;
        }
    }

    private function cleanUp(File $file, string $path): void
    {
        // Only the copy we made. Never the original.
        if (str_starts_with($path, sys_get_temp_dir().'/aziv-doc')) {
            @unlink($path);
        }
    }
}
