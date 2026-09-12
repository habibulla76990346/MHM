<?php

namespace Tests\Feature\Knowledge;

use App\Domains\AI\Models\ApiUsageLog;
use App\Domains\AI\Support\Capability;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\DocumentChunk;
use App\Domains\Knowledge\Services\IndexingService;
use App\Domains\Knowledge\Support\Vector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEmbedder;
use Tests\Support\IndexesDocuments;
use Tests\TestCase;

/**
 * Upload → extract → chunk → embed → searchable (§17).
 *
 * The pipeline end to end against real files, with a fake embedding provider
 * whose vectors genuinely encode word overlap — so every similarity assertion
 * here is arithmetic rather than fixture ordering.
 */
class DocumentIndexingTest extends TestCase
{
    use IndexesDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpKnowledgeFixtures();
        $this->installEmbeddingStub();
    }

    // -- the pipeline --------------------------------------------------------

    public function test_a_document_goes_from_uploaded_to_searchable(): void
    {
        $base = $this->base();
        $file = $this->upload('notes.txt', 'txt');

        $document = app(IndexingService::class)->ingest($base, $file, $this->user);

        // The queue runs synchronously under test, so by the time ingest
        // returns the chained jobs have run.
        $document = $document->fresh();

        $this->assertSame(Document::STATUS_READY, $document->status, (string) $document->failure_reason);
        $this->assertSame('plain-text', $document->extractor_key);
        $this->assertGreaterThan(0, $document->chunk_count);
        $this->assertGreaterThan(0, $document->character_count);
        $this->assertNotNull($document->extracted_at);
        $this->assertNotNull($document->embedded_at);

        $chunk = $document->chunks()->first();

        $this->assertNotNull($chunk->embedding);
        $this->assertSame(FakeEmbedder::DIMENSIONS, $chunk->dimensions);
        $this->assertSame('embed-small', $chunk->embedding_model);

        // The vector survives the round trip through the BLOB.
        $stored = Vector::unpack((string) $chunk->embedding, (int) $chunk->dimensions);
        $this->assertCount(FakeEmbedder::DIMENSIONS, $stored);
        $this->assertEqualsWithDelta(1.0, Vector::cosine($stored, FakeEmbedder::vector($chunk->content)), 0.0001);
    }

    public function test_indexing_is_metered_like_every_other_provider_call(): void
    {
        // Embedding a 400-page manual is a real bill. An owner who cannot see
        // it on the same screen as their chat cost has no idea what knowledge
        // bases are costing them.
        app(IndexingService::class)->ingest($this->base(), $this->upload('report.pdf', 'pdf'), $this->user);

        $log = ApiUsageLog::where('capability', Capability::EMBEDDINGS)->first();

        $this->assertNotNull($log, 'Embedding was not recorded as usage.');
        $this->assertSame($this->embedModel->getKey(), $log->model_id);
        $this->assertSame($this->user->getKey(), $log->user_id);
        $this->assertGreaterThan(0, $log->input_tokens);
    }

    public function test_re_adding_the_same_file_re_indexes_it_rather_than_duplicating(): void
    {
        $base = $this->base();
        $file = $this->upload('notes.txt', 'txt');
        $indexing = app(IndexingService::class);

        $first = $indexing->ingest($base, $file, $this->user);
        $chunksAfterFirst = DocumentChunk::count();

        $second = $indexing->ingest($base, $file, $this->user);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Document::count());
        // Two copies of a document compete with themselves in every search
        // result and each answer cites the wrong one half the time.
        $this->assertSame($chunksAfterFirst, DocumentChunk::count());
    }

    public function test_a_file_type_nothing_can_read_is_refused_with_a_sentence(): void
    {
        $file = $this->upload('sheet.xlsx', 'xlsx', 'binary rubbish');

        $this->expectExceptionMessage('cannot read XLSX');

        app(IndexingService::class)->ingest($this->base(), $file, $this->user);
    }

    public function test_a_quarantined_file_never_becomes_searchable(): void
    {
        $file = $this->upload('notes.txt', 'txt');
        $file->forceFill(['quarantined_at' => now()])->save();

        $this->expectExceptionMessage('quarantined');

        app(IndexingService::class)->ingest($this->base(), $file->fresh(), $this->user);
    }

    public function test_an_unreadable_file_fails_with_words_the_customer_can_act_on(): void
    {
        // A .doc renamed to .docx — the commonest real failure.
        $file = $this->upload('broken.docx', 'docx', 'this is not a zip archive');

        $document = app(IndexingService::class)->ingest($this->base(), $file, $this->user)->fresh();

        $this->assertSame(Document::STATUS_FAILED, $document->status);
        $this->assertStringContainsString('.docx', (string) $document->failure_reason);
        // Never a path, never a class name, never a stack frame.
        $this->assertStringNotContainsString('/', (string) $document->failure_reason);
        $this->assertSame(0, $document->chunks()->count());
    }

    public function test_a_pdf_keeps_the_page_each_passage_came_from(): void
    {
        $document = app(IndexingService::class)
            ->ingest($this->base(), $this->upload('report.pdf', 'pdf'), $this->user)
            ->fresh();

        $this->assertSame(Document::STATUS_READY, $document->status, (string) $document->failure_reason);
        // A citation somebody can open the document and check is what
        // separates an answer from an assertion.
        $this->assertSame('page 1', $document->chunks()->first()->locator);
    }

    public function test_every_status_the_customer_sees_is_a_sentence_not_a_state(): void
    {
        foreach (Document::STATUSES as $state => $label) {
            $this->assertNotSame($state, $label, $state.' is shown to customers as a machine word.');
            $this->assertMatchesRegularExpression('/^[A-Z]/', $label);
        }
    }
}
