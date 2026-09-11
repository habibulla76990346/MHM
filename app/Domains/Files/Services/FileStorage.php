<?php

namespace App\Domains\Files\Services;

use App\Domains\Files\Contracts\FileScanner;
use App\Domains\Files\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores a validated upload.
 *
 * US-6: always on the PRIVATE disk, outside the web root. There is no code
 * path here that writes to the public disk, so no upload can ever acquire a
 * directly fetchable URL.
 */
class FileStorage
{
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly FileScanner $scanner,
    ) {
    }

    public function store(UploadedFile $upload, User $owner, string $purpose = 'attachment'): File
    {
        $validated = $this->validator->validate($upload);

        // US-5: the stored name is generated. No part of the client filename
        // participates in the path.
        $storedName = Str::uuid()->toString().'.'.$validated->extension;
        $directory = 'uploads/'.$owner->getKey().'/'.now()->format('Y/m');

        $checksum = hash_file('sha256', $validated->file->getRealPath()) ?: '';

        $path = Storage::disk('private')->putFileAs($directory, $validated->file, $storedName);

        $file = DB::transaction(fn () => File::create([
            'user_id' => $owner->getKey(),
            'disk' => 'private',
            'path' => $path,
            'stored_name' => $storedName,
            'original_name' => $validated->originalName,
            'detected_mime' => $validated->detectedMime,
            'declared_mime' => $validated->declaredMime,
            'extension' => $validated->extension,
            'size_bytes' => $validated->sizeBytes,
            'checksum' => $checksum,
            'purpose' => $purpose,
        ]));

        $this->scan($file);

        return $file->refresh();
    }

    public function scan(File $file): void
    {
        $verdict = $this->scanner->scan($file);

        $file->update([
            'scan_status' => $verdict->verdict === ScanVerdict::SKIPPED ? 'skipped' : 'scanned',
            'scan_verdict' => $verdict->verdict,
            'scanner_key' => $verdict->scannerKey,
            'quarantined_at' => $verdict->isInfected() ? now() : null,
        ]);

        $file->scanResults()->create([
            'scanner_key' => $verdict->scannerKey,
            'verdict' => $verdict->verdict,
            'details' => $verdict->details,
            'duration_ms' => $verdict->durationMs,
            'scanned_at' => now(),
        ]);
    }

    public function delete(File $file): void
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();
    }
}
