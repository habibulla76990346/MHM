<?php

namespace App\Domains\Files\Services;

final class ScanVerdict
{
    public const CLEAN = 'clean';
    public const INFECTED = 'infected';
    public const ERROR = 'error';
    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly string $verdict,
        public readonly string $scannerKey,
        public readonly ?string $details = null,
        public readonly int $durationMs = 0,
    ) {
    }

    public function isClean(): bool
    {
        return $this->verdict === self::CLEAN;
    }

    public function isInfected(): bool
    {
        return $this->verdict === self::INFECTED;
    }
}
