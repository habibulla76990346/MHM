<?php

namespace App\Http\Controllers;

use App\Domains\Files\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one place a stored file is allowed to RENDER rather than download.
 *
 * WHY THIS IS SEPARATE FROM `FileDownloadController`. That controller forces
 * `Content-Disposition: attachment` on everything, deliberately: stored HTML
 * or SVG rendered inline would execute in this application's origin, with the
 * customer's session. That rule is right and is not relaxed here.
 *
 * But a picture the platform generated has to appear in a gallery, and audio
 * has to play in a `<audio>` element, and neither works as an attachment. So
 * inline serving exists behind FOUR conditions, all of which must hold:
 *
 *   1. the file's PURPOSE is one the platform itself wrote — a generated
 *      image, synthesised speech, or a voice recording; never an upload,
 *      whose bytes came from a browser;
 *   2. the type read from the bytes at storage time is on a short raster and
 *      audio allowlist — no SVG, no HTML, no PDF, nothing that can carry
 *      script;
 *   3. the ordinary file policy still authorises it, on every request; and
 *   4. it is not quarantined.
 *
 * The headers then assume the first three were wrong anyway: `nosniff` so the
 * browser cannot reinterpret the type, a `default-src 'none'; sandbox` policy
 * so anything that did slip through has no origin to act in, and no caching
 * by anything shared.
 */
class MediaController extends Controller
{
    /** Purposes whose bytes this application produced, not a browser. */
    private const INLINE_PURPOSES = ['image_generation', 'speech', 'voice_recording'];

    /**
     * Types that may render inline.
     *
     * Kept next to the purposes rather than derived from them, so widening
     * one does not silently widen the other. Notably absent: `image/svg+xml`,
     * which is a document that can carry JavaScript, not a picture.
     */
    private const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/webp', 'image/gif',
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav',
        'audio/ogg', 'audio/opus', 'audio/webm', 'audio/mp4',
    ];

    public function show(Request $request, File $file): Response|StreamedResponse
    {
        // Authorisation runs on EVERY request. A valid URL is not consent.
        $this->authorize('view', $file);

        abort_unless(in_array((string) $file->purpose, self::INLINE_PURPOSES, true), 404);
        abort_unless(in_array((string) $file->detected_mime, self::INLINE_TYPES, true), 404);
        abort_if($file->isQuarantined(), 404);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        return Storage::disk($file->disk)->response(
            $file->path,
            $file->original_name,
            [
                'Content-Type' => $file->detected_mime,
                'Content-Disposition' => 'inline; filename="'.addslashes((string) $file->original_name).'"',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                // Private: this is one customer's content, and a shared cache
                // holding it is a way for the next person to be served it.
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }
}
