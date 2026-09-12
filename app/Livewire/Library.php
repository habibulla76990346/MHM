<?php

namespace App\Livewire;

use App\Domains\Files\Services\ExtractorRegistry;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Knowledge\Models\Document;
use App\Domains\Knowledge\Models\KnowledgeBase;
use App\Domains\Knowledge\Services\IndexingService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The Library — a customer's documents and what can be asked about them (§17).
 *
 * Every public property is a SCALAR. A Livewire snapshot is serialised into the
 * page, and putting an Eloquent model there is the mistake that took down the
 * theme service and System Health earlier in this build.
 *
 * The page polls only while something is actually working. A screen that polls
 * for ever is a screen that costs a request a second on shared hosting, and
 * "Indexing" that never resolves is what makes somebody upload the same file
 * four times.
 */
class Library extends Component
{
    use WithFileUploads;

    public string $baseUuid = '';

    public string $newBaseName = '';

    public bool $creating = false;

    public $upload = null;

    public string $error = '';

    public function mount(): void
    {
        $this->baseUuid = (string) ($this->bases()->first()?->uuid ?? '');
    }

    /** @return Collection<int, KnowledgeBase> */
    #[Computed]
    public function bases(): Collection
    {
        $user = auth()->user();

        return $user ? KnowledgeBase::readableBy($user) : collect();
    }

    #[Computed]
    public function base(): ?KnowledgeBase
    {
        return $this->bases()->firstWhere('uuid', $this->baseUuid);
    }

    /** @return Collection<int, Document> */
    #[Computed]
    public function documents(): Collection
    {
        $base = $this->base();

        return $base
            ? $base->documents()->latest('id')->limit(100)->get()
            : collect();
    }

    /** Whether anything is still being read or indexed. Drives the polling. */
    #[Computed]
    public function working(): bool
    {
        return $this->documents()->contains(fn (Document $d) => $d->isWorking());
    }

    #[Computed]
    public function acceptedTypes(): string
    {
        return implode(', ', array_map('strtoupper', app(ExtractorRegistry::class)->extensions()));
    }

    public function createBase(): void
    {
        $this->error = '';

        $this->validate([
            'newBaseName' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        KnowledgeBase::create([
            'name' => $this->newBaseName,
            'scope' => KnowledgeBase::SCOPE_PERSONAL,
            'user_id' => auth()->id(),
            'created_by' => auth()->id(),
        ]);

        $this->newBaseName = '';
        $this->creating = false;
        unset($this->bases);

        $this->baseUuid = (string) ($this->bases()->last()?->uuid ?? '');
    }

    /**
     * Take an uploaded file into the selected base.
     *
     * Validation, storage and scanning are Phase 1's `FileStorage`; this only
     * decides which base it joins. Duplicating any of that here would be a
     * second upload path with a second set of rules.
     */
    public function store(FileStorage $storage, IndexingService $indexing): void
    {
        $this->error = '';
        $base = $this->base();

        if (! $base || ! $this->upload) {
            return;
        }

        if (! $base->isWritableBy(auth()->user())) {
            $this->error = __('You can read this collection but not add to it.');

            return;
        }

        try {
            $file = $storage->store($this->upload->getRealPath()
                ? $this->upload
                : throw new \RuntimeException(__('The upload did not arrive.')),
                auth()->user(),
                'knowledge',
            );

            $indexing->ingest($base, $file, auth()->user());
        } catch (Throwable $e) {
            // Upload rejections and unreadable types both arrive here as a
            // sentence the customer can act on.
            $this->error = $e->getMessage();
        }

        $this->upload = null;
        unset($this->documents, $this->working);
    }

    public function remove(string $uuid): void
    {
        $document = Document::where('uuid', $uuid)->first();

        if (! $document || ! ($document->knowledgeBase?->isWritableBy(auth()->user()) ?? false)) {
            return;
        }

        // Chunks go with it — a deleted document must stop appearing in
        // answers immediately, not at the next re-index.
        $document->chunks()->delete();
        $document->delete();

        unset($this->documents, $this->working);
    }

    public function render()
    {
        return view('livewire.library');
    }
}
