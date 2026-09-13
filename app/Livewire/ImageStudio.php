<?php

namespace App\Livewire;

use App\Domains\Files\Services\FileStorage;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Images\Services\ImageService;
use App\Domains\Images\Support\ImageRefused;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * The image studio and gallery (§16).
 *
 * EVERY PUBLIC PROPERTY IS A SCALAR. A Livewire snapshot is serialised into
 * the page, and putting an Eloquent model there is the mistake that took down
 * the theme service and the System Health page earlier in this build.
 *
 * IT POLLS ONLY WHILE SOMETHING IS WORKING. Generation takes tens of seconds,
 * so the gallery has to refresh itself — but a screen that polls for ever
 * costs a request every few seconds on shared hosting, for every open tab, for
 * ever.
 */
class ImageStudio extends Component
{
    use WithPagination;

    public string $prompt = '';

    public string $negativePrompt = '';

    public string $size = '1024x1024';

    public string $quality = 'standard';

    public int $count = 1;

    public bool $advanced = false;

    public string $error = '';

    public function mount(): void
    {
        $this->size = (string) settings('images.default_size');

        if (! array_key_exists($this->size, ImageGeneration::SIZES)) {
            $this->size = array_key_first(ImageGeneration::SIZES);
        }
    }

    #[Computed]
    public function enabled(): bool
    {
        return app(ImageService::class)->isEnabled();
    }

    /** Whether anything could serve a picture at all. */
    #[Computed]
    public function ready(): bool
    {
        return app(ImageService::class)->availableModel() !== null;
    }

    /** @return Collection<int, ImageGeneration> */
    #[Computed]
    public function generations(): Collection
    {
        $user = auth()->user();

        if (! $user) {
            return collect();
        }

        return ImageGeneration::ownedBy($user)
            ->with('file')
            ->latest('id')
            ->limit(60)
            ->get();
    }

    #[Computed]
    public function working(): bool
    {
        return $this->generations()->contains(fn (ImageGeneration $g) => $g->isWorking());
    }

    /** @return array<int, string> */
    #[Computed]
    public function recentPrompts(): array
    {
        $user = auth()->user();

        return $user ? app(ImageService::class)->promptHistory($user, 8) : [];
    }

    public function generate(ImageService $images): void
    {
        $this->error = '';

        $this->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:4000'],
            'negativePrompt' => ['nullable', 'string', 'max:1000'],
            'count' => ['integer', 'min:1', 'max:'.ImageService::MAX_PER_REQUEST],
        ]);

        try {
            $images->request(
                user: auth()->user(),
                prompt: $this->prompt,
                count: $this->count,
                size: $this->size,
                quality: $this->quality,
                negativePrompt: $this->negativePrompt !== '' ? $this->negativePrompt : null,
            );
        } catch (ImageRefused $e) {
            // Every one of these is a sentence the customer can act on.
            $this->error = $e->getMessage();

            return;
        } catch (Throwable) {
            // A PROVIDER failure, on a host running the queue synchronously:
            // the job rethrows so a real queue can retry it, and on `sync`
            // that exception surfaces right here. The generation row already
            // carries the reason and is visible in the gallery below, so this
            // must not become a 500 — the customer would lose the page and
            // never learn what happened.
            unset($this->generations, $this->working, $this->recentPrompts);

            return;
        }

        unset($this->generations, $this->working, $this->recentPrompts);
    }

    public function again(string $uuid, ImageService $images): void
    {
        $this->error = '';

        $source = ImageGeneration::where('uuid', $uuid)->first();

        if (! $source || ! auth()->user()->can('regenerate', $source)) {
            return;
        }

        try {
            $images->regenerate(auth()->user(), $source);
        } catch (ImageRefused $e) {
            $this->error = $e->getMessage();

            return;
        } catch (Throwable) {
            // Same reasoning as `generate()`: the row says what went wrong.
            unset($this->generations, $this->working);

            return;
        }

        unset($this->generations, $this->working);
    }

    public function reuse(string $prompt): void
    {
        $this->prompt = mb_substr($prompt, 0, 4000);
    }

    public function remove(string $uuid, FileStorage $files): void
    {
        $generation = ImageGeneration::where('uuid', $uuid)->first();

        if (! $generation || ! auth()->user()->can('delete', $generation)) {
            return;
        }

        // The BYTES go too, through the storage service — deleting the row
        // alone would leave the picture on disk, which is a deletion the
        // customer believes happened and did not.
        if ($generation->file) {
            $files->delete($generation->file);
        }

        $generation->delete();

        unset($this->generations, $this->working);
    }

    public function render()
    {
        return view('livewire.image-studio', [
            'sizes' => ImageGeneration::SIZES,
            'qualities' => ImageGeneration::QUALITIES,
            'maxPerRequest' => ImageService::MAX_PER_REQUEST,
        ]);
    }
}
