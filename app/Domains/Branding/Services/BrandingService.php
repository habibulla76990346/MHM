<?php

namespace App\Domains\Branding\Services;

use App\Domains\Branding\Support\BrandAsset;
use App\Domains\Files\Exceptions\UploadRejected;
use App\Domains\Files\Services\FileStorage;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Branding: what the product is called and what it looks like (blueprint §4).
 *
 * Replacing an asset is two steps, deliberately kept separate:
 *
 *   1. STORE — the upload goes onto the private disk through the ordinary
 *      Phase 1 pipeline, so it passes every one of Owner Addendum H's nine
 *      controls and is scanned like any other file. This is the record.
 *   2. PUBLISH — a derived copy is written to the web root (D-13).
 *
 * Doing it in one step would mean a file reaching public/ before the scanner
 * had seen it.
 */
class BrandingService
{
    public function __construct(
        private readonly FileStorage $files,
        private readonly BrandAssetPublisher $publisher,
    ) {}

    /**
     * Replace one brand asset.
     *
     * @return array{path: string, previous: ?string}
     *
     * @throws BrandAssetRejected
     * @throws UploadRejected
     */
    public function replace(string $purpose, UploadedFile $upload, User $actor): array
    {
        $file = $this->files->store($upload, $actor, BrandAsset::filePurpose($purpose));

        // Publishing can still refuse — a PNG that is really a script, or a
        // web root that is not writable. The stored file stays either way, so
        // nothing is lost and the reason can be shown.
        $path = $this->publisher->publish($file, $purpose);

        $key = BrandAsset::settingKey($purpose);
        $previous = settings($key);

        settings()->set($key, $path, $actor->getKey());

        // Only remove the file this one replaced, and never one of the
        // defaults that ship with the product.
        $this->publisher->unpublish($previous, $this->shippedDefaults());

        return ['path' => $path, 'previous' => $previous];
    }

    /**
     * Put a brand asset back to the artwork that ships with Aziv AI.
     * D-09: the official artwork is preserved as the master and is always
     * the thing an owner can return to.
     */
    public function reset(string $purpose, ?int $actorId = null): void
    {
        $key = BrandAsset::settingKey($purpose);
        $previous = settings($key);

        settings()->set($key, null, $actorId);

        $this->publisher->unpublish($previous, $this->shippedDefaults());
    }

    /** The web-root-relative path currently in use for an asset. */
    public function path(string $purpose): string
    {
        $custom = settings(BrandAsset::settingKey($purpose));

        if (is_string($custom) && $custom !== '' && is_file(public_path($custom))) {
            return $custom;
        }

        // Falls back whenever the published file has gone — a botched deploy
        // that did not copy public/brand, for instance. Branding degrades to
        // the shipped artwork rather than to a broken image.
        return BrandAsset::all()[$purpose]['default'] ?? BrandAsset::all()['logo_light']['default'];
    }

    public function url(string $purpose): string
    {
        return asset($this->path($purpose));
    }

    public function isCustomised(string $purpose): bool
    {
        return $this->path($purpose) !== (BrandAsset::all()[$purpose]['default'] ?? null);
    }

    /** @return array<int, string> */
    private function shippedDefaults(): array
    {
        return array_column(BrandAsset::all(), 'default');
    }
}
