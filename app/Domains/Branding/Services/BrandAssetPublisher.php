<?php

namespace App\Domains\Branding\Services;

use App\Domains\Branding\Support\BrandAsset;
use App\Domains\Files\Models\File;
use Illuminate\Support\Facades\Storage;

/**
 * Copies an uploaded brand image out to the web root (owner decision D-13,
 * option C).
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * Phase 1 put every upload on a private disk with no URL, served only through
 * an authorised controller. Brand assets cannot live under that rule: a logo
 * must render on the login page for someone with no account, and an installed
 * PWA's icon is fetched by the operating system with no session whatsoever.
 *
 * The owner chose to write derived public copies rather than pay a PHP request
 * per logo on every page (option A) or depend on a symlink that many cPanel
 * accounts refuse (option B).
 *
 * WHAT CONTAINS THE COST
 * ----------------------
 * The web root becoming writable is the price, so the containment is the
 * point of this class, not an afterthought:
 *
 *   - The private disk stays the record of truth. The original upload has
 *     already been through UploadValidator and the scanner; this only ever
 *     copies bytes that are already stored.
 *   - Raster images only. Never an uploaded SVG: SVG is a document format
 *     that can carry script, and it would be served same-origin. The mark.svg
 *     that ships with Aziv AI is ours and is committed, not published here.
 *   - A quarantined file is never published.
 *   - The published name is `<purpose>-<first 8 of the checksum>.<ext>`. It is
 *     derived from content, so no part of a client filename reaches the
 *     filesystem and a cached copy can never be stale for the wrong reason.
 *   - Replacing removes the file it replaced, so the web root cannot grow
 *     without bound.
 *
 * On a host where the web root is not writable this fails cleanly and the
 * shipped default branding continues to be used — Addendum E: shared hosting
 * differs in speed, never in capability.
 */
class BrandAssetPublisher
{
    /** Formats safe to serve from our own origin. Deliberately no SVG. */
    public const PUBLISHABLE_MIMES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    public const DIRECTORY = 'brand';

    /**
     * Publish a stored upload as the named brand asset.
     *
     * @return string the public path, relative to the web root
     *
     * @throws BrandAssetRejected
     */
    public function publish(File $file, string $purpose): string
    {
        if (! in_array($purpose, BrandAsset::purposes(), true)) {
            throw BrandAssetRejected::unknownPurpose($purpose);
        }

        $extension = self::PUBLISHABLE_MIMES[strtolower($file->detected_mime)] ?? null;

        if ($extension === null) {
            throw BrandAssetRejected::unsupportedFormat($file->detected_mime);
        }

        if ($file->quarantined_at !== null) {
            throw BrandAssetRejected::quarantined();
        }

        $bytes = Storage::disk($file->disk)->get($file->path);

        if ($bytes === null) {
            throw BrandAssetRejected::missing();
        }

        // Verify the bytes really are the image the record claims, rather than
        // trusting a mime recorded at upload time. A file swapped on disk
        // between upload and publish would otherwise be copied out unchecked.
        if (! $this->looksLikeImage($bytes)) {
            throw BrandAssetRejected::notAnImage();
        }

        $name = $purpose.'-'.substr($file->checksum, 0, 8).'.'.$extension;
        $target = public_path(self::DIRECTORY.'/'.$name);

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true)) {
            throw BrandAssetRejected::notWritable();
        }

        if (@file_put_contents($target, $bytes) === false) {
            throw BrandAssetRejected::notWritable();
        }

        @chmod($target, 0644);

        return self::DIRECTORY.'/'.$name;
    }

    /**
     * Remove a previously published file.
     *
     * Refuses to touch anything outside public/brand, and refuses to delete a
     * file that ships with the product — an administrator replacing their logo
     * must not be able to delete the default that Aziv AI falls back to.
     */
    public function unpublish(?string $path, array $protected = []): void
    {
        if (! $path || ! str_starts_with($path, self::DIRECTORY.'/')) {
            return;
        }

        if (in_array($path, $protected, true)) {
            return;
        }

        $full = public_path($path);
        $root = realpath(public_path(self::DIRECTORY));
        $real = realpath($full);

        // Path traversal guard: the resolved file must actually sit inside
        // public/brand, not merely start with the string.
        if ($root && $real && str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            @unlink($real);
        }
    }

    public function webRootIsWritable(): bool
    {
        $directory = public_path(self::DIRECTORY);

        return is_dir($directory) ? is_writable($directory) : is_writable(public_path());
    }

    /**
     * Magic-byte check. getimagesizefromstring() parses the header rather than
     * trusting an extension or a recorded mime type, so a PHP script renamed
     * to .png does not reach the web root.
     */
    private function looksLikeImage(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        return $info !== false && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0;
    }
}
