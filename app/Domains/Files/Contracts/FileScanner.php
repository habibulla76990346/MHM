<?php

namespace App\Domains\Files\Contracts;

use App\Domains\Files\Models\File;
use App\Domains\Files\Services\ScanVerdict;

/**
 * The extensible scanning layer (Owner Addendum H, US-10).
 *
 * Scanning is optional and environment-dependent: ClamAV needs a daemon that
 * shared hosting cannot provide, while an API scanner needs only outbound
 * HTTPS and therefore works anywhere. The nine controls in UploadValidator
 * carry the security either way — a scanner is defence in depth, not the
 * defence.
 */
interface FileScanner
{
    public function key(): string;

    /** Reported by diagnostics, so an unavailable scanner shows as GREY rather than a false green. */
    public function isAvailable(): bool;

    public function scan(File $file): ScanVerdict;
}
