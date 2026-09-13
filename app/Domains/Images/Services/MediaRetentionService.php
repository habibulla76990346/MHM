<?php

namespace App\Domains\Images\Services;

use App\Domains\Files\Models\File;
use App\Domains\Files\Services\FileStorage;
use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Voice\Models\VoiceJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deleting media once its time is up (§16, §18).
 *
 * WHAT GOES AND WHAT STAYS IS NOT THE SAME DECISION for the two features, and
 * this is the whole reason the sweeper is one class rather than two calls:
 *
 *   - An IMAGE is the thing the customer made. When its retention expires the
 *     picture and its row both go, because a gallery entry with no picture is
 *     a puzzle rather than a record.
 *   - AUDIO is a large file of somebody's voice, and the thing they made from
 *     it is the TRANSCRIPT — which is a chat message and lives as long as
 *     their conversation. So retention deletes the AUDIO and keeps the row:
 *     the cost happened, the owner's reporting needs it, and the customer
 *     loses nothing they can see.
 *
 * IT DELETES BYTES THROUGH `FileStorage`, never by dropping a row. A row
 * deleted on its own leaves the file on disk for ever — a deletion the
 * customer believes happened and did not, which is the worse of the two
 * failures by a long way.
 *
 * BOUNDED PER RUN. This is scheduled and the queue is often cron on shared
 * hosting; a sweep that tried to delete forty thousand files in one pass would
 * be killed halfway through with no record of where it got to. It runs again
 * tomorrow.
 */
class MediaRetentionService
{
    private const BATCH = 250;

    public function __construct(private readonly FileStorage $files) {}

    /** @return array{images: int, audio: int} how many of each were swept */
    public function sweep(): array
    {
        return [
            'images' => $this->sweepImages(),
            'audio' => $this->sweepAudio(),
        ];
    }

    private function sweepImages(): int
    {
        $swept = 0;

        ImageGeneration::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('file')
            ->limit(self::BATCH)
            ->get()
            ->each(function (ImageGeneration $generation) use (&$swept) {
                if ($this->forget($generation->file)) {
                    $swept++;
                }

                $generation->delete();
            });

        return $swept;
    }

    private function sweepAudio(): int
    {
        $swept = 0;

        VoiceJob::whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNotNull('file_id')
            ->with('file')
            ->limit(self::BATCH)
            ->get()
            ->each(function (VoiceJob $job) use (&$swept) {
                if ($this->forget($job->file)) {
                    $swept++;
                }

                // The ROW stays. The transcript is a chat message and the cost
                // is the owner's record; only the audio was ever temporary.
                $job->forceFill(['file_id' => null, 'expires_at' => null])->save();
            });

        return $swept;
    }

    private function forget(?File $file): bool
    {
        if (! $file) {
            return false;
        }

        try {
            $this->files->delete($file);

            return true;
        } catch (Throwable $e) {
            // A missing file is not a reason to leave the row expiring for
            // ever, so this is logged and the sweep continues.
            Log::warning('Media retention could not delete a file', [
                'file' => $file->uuid,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
