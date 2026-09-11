<?php

namespace App\Domains\Diagnostics\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One diagnostic finding, carrying all eleven fields Owner Addendum G requires.
 *
 * SECURITY: no credential value may ever reach this object. Checks ask
 * "is this valid?" and receive a boolean plus an error class — they never
 * receive the secret itself. `technicalReason` passes through Redactor before
 * storage. Not even masked values are included: the exported report is
 * designed to be forwarded to a hosting provider.
 */
final class CheckResult implements Arrayable
{
    public readonly string $technicalReason;

    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly Category $category,
        public readonly Status $status,
        public readonly Severity $severity,
        public readonly Responsibility $responsibility,
        string $technicalReason = '',
        public readonly string $recommendedAction = '',
        public readonly string $adminAction = '',
        public readonly bool $requiresHostingSupport = false,
        public readonly string $supportWording = '',
        public readonly ?string $logReference = null,
        public readonly float $durationMs = 0.0,
    ) {
        // Scrubbed HERE, at construction, so there is no code path — console,
        // JSON, Admin screen, exported report — that can emit an unscrubbed
        // value. This is what "secret-free by construction" means in practice:
        // not a filter applied at the edges, but a value that never exists
        // unredacted inside the diagnostics layer.
        $this->technicalReason = Redactor::scrub($technicalReason);
    }

    public static function pass(string $key, string $title, Category $c, Responsibility $r, string $reason = ''): self
    {
        return new self($key, $title, $c, Status::Green, Severity::Informational, $r, $reason);
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'severity' => $this->severity->value,
            'responsibility' => $this->responsibility->value,
            'technical_reason' => $this->technicalReason, // already scrubbed at construction
            'recommended_action' => $this->recommendedAction,
            'admin_action' => $this->adminAction,
            'requires_hosting_support' => $this->requiresHostingSupport,
            'support_wording' => $this->supportWording,
            'log_reference' => $this->logReference,
            'duration_ms' => round($this->durationMs, 2),
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
