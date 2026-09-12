<?php

namespace Tests\Feature\Knowledge;

use App\Domains\Chat\Services\ConversationService;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Models\KnowledgeBaseGrant;
use App\Domains\Knowledge\Services\IndexingService;
use App\Domains\Security\Services\PermissionRegistry;
use App\Livewire\Library;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Support\IndexesDocuments;
use Tests\TestCase;

/**
 * The customer's side of §17: upload a document, watch it become searchable,
 * remove it.
 */
class LibraryScreenTest extends TestCase
{
    use IndexesDocuments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpKnowledgeFixtures();
        $this->installEmbeddingStub();
    }

    public function test_a_customer_can_create_a_collection_and_add_a_document(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(Library::class)
            ->set('newBaseName', 'Contracts')
            ->call('createBase');

        $base = KnowledgeBase::where('name', 'Contracts')->firstOrFail();

        $this->assertSame(KnowledgeBase::SCOPE_PERSONAL, $base->scope);
        $this->assertSame($this->user->getKey(), $base->user_id);

        $component
            ->set('baseUuid', $base->uuid)
            ->set('upload', UploadedFile::fake()->createWithContent(
                'terms.txt',
                'The renewal grace period lasts seven days after the billing period ends.',
            ))
            ->call('store')
            ->assertSet('error', '');

        $document = Document::where('knowledge_base_id', $base->getKey())->firstOrFail();

        $this->assertSame(Document::STATUS_READY, $document->status, (string) $document->failure_reason);
        $this->assertGreaterThan(0, $document->chunk_count);
    }

    public function test_the_screen_lists_only_what_this_customer_may_read(): void
    {
        $mine = $this->base(['name' => 'Mine']);

        $stranger = User::factory()->create();
        $stranger->assignRole(PermissionRegistry::CUSTOMER);

        KnowledgeBase::create([
            'name' => 'Somebody else',
            'scope' => KnowledgeBase::SCOPE_PERSONAL,
            'user_id' => $stranger->fresh()->getKey(),
        ]);

        KnowledgeBase::create([
            'name' => 'Ungranted handbook',
            'scope' => KnowledgeBase::SCOPE_SHARED,
        ]);

        $component = Livewire::actingAs($this->user)->test(Library::class);

        $component->assertSee('Mine');
        $component->assertDontSee('Somebody else');
        // A shared base with no grant is not "shared with everybody".
        $component->assertDontSee('Ungranted handbook');
    }

    public function test_a_customer_granted_a_shared_base_can_read_it_but_not_add_to_it(): void
    {
        $shared = KnowledgeBase::create([
            'name' => 'Company handbook',
            'scope' => KnowledgeBase::SCOPE_SHARED,
            'embedding_model_id' => $this->embedModel->getKey(),
        ]);

        KnowledgeBaseGrant::create([
            'knowledge_base_id' => $shared->getKey(),
            'user_id' => $this->user->getKey(),
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(Library::class)
            ->set('baseUuid', $shared->uuid)
            ->set('upload', UploadedFile::fake()->createWithContent('sneak.txt', 'Text that should never be indexed here.'))
            ->call('store');

        // Curating a collection other customers search is the administrator's
        // job. Read access must not become write access.
        $component->assertSet('error', 'You can read this collection but not add to it.');
        $this->assertSame(0, Document::count());
    }

    public function test_a_type_nothing_can_read_is_refused_with_a_sentence(): void
    {
        $base = $this->base();

        Livewire::actingAs($this->user)
            ->test(Library::class)
            ->set('baseUuid', $base->uuid)
            ->set('upload', UploadedFile::fake()->create('sheet.xlsx', 4))
            ->call('store')
            ->assertSee('cannot read');

        $this->assertSame(0, Document::count());
    }

    public function test_removing_a_document_stops_it_appearing_in_answers_at_once(): void
    {
        $base = $this->base();

        app(IndexingService::class)->ingest(
            $base,
            $this->upload('notes.txt', 'txt'),
            $this->user,
        );

        $document = Document::firstOrFail();
        $this->assertGreaterThan(0, $document->chunks()->count());

        Livewire::actingAs($this->user)
            ->test(Library::class)
            ->set('baseUuid', $base->uuid)
            ->call('remove', $document->uuid);

        // The chunks go with it. Leaving them would keep answering from a
        // document the customer believes they deleted.
        $this->assertSame(0, $document->chunks()->count());
        $this->assertSoftDeleted('documents', ['id' => $document->getKey()]);
    }

    public function test_a_customer_cannot_remove_somebody_elses_document(): void
    {
        $base = $this->base();
        app(IndexingService::class)->ingest($base, $this->upload('notes.txt', 'txt'), $this->user);
        $document = Document::firstOrFail();

        $stranger = User::factory()->create();
        $stranger->assignRole(PermissionRegistry::CUSTOMER);

        Livewire::actingAs($stranger->fresh())
            ->test(Library::class)
            ->call('remove', $document->uuid);

        $this->assertDatabaseHas('documents', ['id' => $document->getKey(), 'deleted_at' => null]);
    }

    public function test_the_screen_only_polls_while_something_is_working(): void
    {
        $base = $this->base();

        // Nothing in flight: no polling, because a page that polls for ever
        // costs a request a second on shared hosting.
        Livewire::actingAs($this->user)
            ->test(Library::class)
            ->set('baseUuid', $base->uuid)
            ->assertDontSee('wire:poll');

        Document::create([
            'knowledge_base_id' => $base->getKey(),
            'file_id' => $this->upload('notes.txt', 'txt')->getKey(),
            'user_id' => $this->user->getKey(),
            'title' => 'notes.txt',
            'status' => Document::STATUS_EMBEDDING,
        ]);

        Livewire::actingAs($this->user)
            ->test(Library::class)
            ->set('baseUuid', $base->uuid)
            ->assertSee('wire:poll', false);
    }

    public function test_a_new_conversation_can_search_what_the_customer_uploaded(): void
    {
        $base = $this->base();
        app(IndexingService::class)->ingest($base, $this->upload('notes.txt', 'txt'), $this->user);

        $conversation = app(ConversationService::class)->start($this->user);

        // Automatic, because relevance is already the filter: a customer who
        // uploads a document expects to ask about it, not to find a second
        // switch that must also be turned on.
        $this->assertTrue($conversation->knowledgeBases()->where('knowledge_bases.id', $base->getKey())->exists());
    }
}
