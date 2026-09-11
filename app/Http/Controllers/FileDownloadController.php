<?php

namespace App\Http\Controllers;

use App\Domains\Files\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONLY way an uploaded file leaves the server (Owner Addendum H, US-9).
 *
 * Files live on a private disk with no URL of their own; this controller
 * authorises every retrieval. Changing the id in the URL therefore gets a 403,
 * not someone else's document.
 */
class FileDownloadController extends Controller
{
    public function show(Request $request, File $file): Response
    {
        // Authorisation runs on EVERY download. A valid URL is not consent.
        $this->authorize('view', $file);

        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        $file->accessLogs()->create([
            'user_id' => $request->user()?->getKey(),
            'action' => 'download',
            'ip' => $request->ip(),
            'occurred_at' => now(),
        ]);

        return Storage::disk($file->disk)->download(
            $file->path,
            $file->original_name,
            [
                // Forces a download rather than inline rendering, so stored
                // HTML or SVG can never execute in the application's origin.
                'Content-Type' => $file->detected_mime,
                'Content-Disposition' => 'attachment',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
