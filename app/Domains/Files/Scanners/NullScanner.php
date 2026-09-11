<?php

namespace App\Domains\Files\Scanners;

use App\Domains\Files\Contracts\FileScanner;
use App\Domains\Files\Models\File;
use App\Domains\Files\Services\ScanVerdict;

/**
 * The default. Records `skipped` — deliberately NOT `clean`.
 *
 * Reporting an unscanned file as clean would put a reassuring green tick on a
 * check that never ran, which is worse than no scanning at all. Diagnostics
 * report scanning as GREY / not configured, and say so plainly.
 */
class NullScanner implements FileScanner
{
    public function key(): string
    {
        return 'none';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function scan(File $file): ScanVerdict
    {
        return new ScanVerdict(
            verdict: ScanVerdict::SKIPPED,
            scannerKey: $this->key(),
            details: 'No malware scanner is configured. Uploads are validated but not scanned.',
        );
    }
}
