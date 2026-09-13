<?php

namespace App\Domains\Files\Services;

use App\Domains\Files\Contracts\FileScanner;
use App\Domains\Files\Exceptions\UploadRejected;
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
    /**
     * What may be written for each machine-driven purpose, keyed by the type
     * read from the BYTES.
     *
     * NOT A SETTING, and stricter than the upload allowlist. An administrator
     * decides what customers may upload through the file picker; these are the
     * purposes where there is no upload envelope to check — an image a
     * provider returned, speech it synthesised, a recording the composer
     * captured — so the type is read from the content and matched against a
     * list the panel cannot widen. A provider or a browser sending something
     * outside it is a failure to report, never a list to grow.
     */
    private const GENERATED_TYPES = [
        'image_generation' => [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ],
        'speech' => [
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'opus',
        ],
        // What a browser's MediaRecorder actually produces. Chromium gives
        // webm/opus, Safari gives mp4/aac, and neither asks first.
        'voice_recording' => [
            'audio/webm' => 'webm',
            'video/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/opus' => 'opus',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
        ],
    ];

    /**
     * A ceiling per purpose, in bytes.
     *
     * Not the administrator's upload limit: these bytes never passed through
     * it. A minute of Opus is under a megabyte, so twenty-five is generous for
     * a recording and small enough that a loop, a wrong Content-Length or a
     * provider returning a video cannot fill the disk.
     */
    private const GENERATED_MAX_BYTES = [
        'image_generation' => 25 * 1024 * 1024,
        'speech' => 25 * 1024 * 1024,
        'voice_recording' => 25 * 1024 * 1024,
    ];

    public function __construct(
        private readonly UploadValidator $validator,
        private readonly FileScanner $scanner,
    ) {}

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

    /**
     * Store raw bytes that arrived without an upload envelope — a generated
     * image, synthesised speech, a recording the composer captured (§16, §18).
     *
     * WHY THIS IS NOT `store()`. `UploadValidator` enforces the nine Phase 1
     * controls against an `UploadedFile`: a client filename, a declared MIME,
     * a multi-extension check, an allowlist an administrator edits. Those
     * inputs do not exist here — there is no file picker, no client filename
     * and no declared type — so running that validator would be checking
     * claims nobody made.
     *
     * WHY IT IS STILL VALIDATED, AND MORE STRICTLY. A provider is not a
     * trusted source and neither is a browser. A compromised or simply broken
     * provider can return an HTML error page, a redirect, or something
     * crafted, and writing those bytes under a `.png` we chose is how a file
     * that is not an image ends up served as one. So the type is read from the
     * BYTES and matched against a per-purpose allowlist an administrator
     * cannot widen, under a per-purpose size ceiling — because the set of
     * things written without an upload behind them is a property of the code,
     * not a setting.
     *
     * @param  string  $bytes  the raw content, already fetched
     * @param  string  $purpose  which allowlist applies
     *
     * @throws UploadRejected when the bytes are not a type this purpose may write
     */
    public function storeGenerated(string $bytes, User $owner, string $purpose, ?string $displayName = null): File
    {
        if ($bytes === '') {
            throw new UploadRejected(__('The provider returned an empty file.'));
        }

        $allowed = self::GENERATED_TYPES[$purpose] ?? null;

        if ($allowed === null) {
            // A new purpose must declare what it may write. Falling back to
            // "anything" would make this method the hole the rest of the
            // upload rules exist to close.
            throw new UploadRejected(__('Nothing is allowed to write files for that purpose.'));
        }

        $maximum = self::GENERATED_MAX_BYTES[$purpose] ?? 0;

        if ($maximum > 0 && strlen($bytes) > $maximum) {
            throw new UploadRejected(__('That is larger than :size MB, which is more than Aziv AI stores for that purpose.', [
                'size' => (int) round($maximum / 1048576),
            ]));
        }

        // The real type, from the content. `finfo` reads magic bytes; a
        // Content-Type header — a provider's or a browser's — is a claim and
        // is not consulted.
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';

        if (! isset($allowed[$detected])) {
            throw new UploadRejected(__('The provider returned :type, which is not something Aziv AI stores for that purpose.', [
                'type' => $detected,
            ]));
        }

        $extension = $allowed[$detected];
        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = 'generated/'.$owner->getKey().'/'.now()->format('Y/m');
        $path = $directory.'/'.$storedName;

        Storage::disk('private')->put($path, $bytes);

        $file = DB::transaction(fn () => File::create([
            'user_id' => $owner->getKey(),
            'disk' => 'private',
            'path' => $path,
            'stored_name' => $storedName,
            'original_name' => $this->generatedDisplayName($displayName, $extension),
            'detected_mime' => $detected,
            // Nothing declared anything: there was no client. Recording the
            // detected type in both columns would invent a claim that was
            // never made.
            'declared_mime' => null,
            'extension' => $extension,
            'size_bytes' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'purpose' => $purpose,
        ]));

        $this->scan($file);

        return $file->refresh();
    }

    private function generatedDisplayName(?string $displayName, string $extension): string
    {
        $name = trim((string) $displayName);
        $name = str_replace(["\0", "\r", "\n", '/', '\\'], '', $name);
        $name = preg_replace('/[[:cntrl:]]/', '', $name) ?? $name;
        $name = mb_substr($name, 0, 80);

        return ($name !== '' ? $name : __('generated')).'.'.$extension;
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
