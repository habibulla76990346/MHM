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

    /**
     * Rebuild from the array form.
     *
     * Diagnostics results are cached between views, and a cache store
     * serialises whatever it is handed. A serialised OBJECT outlives the class
     * that wrote it: after a deploy that touches this class — or on any store
     * that cannot resolve it at unserialize time — it returns as
     * __PHP_Incomplete_Class and the System Health page dies with a fatal type
     * error. That is the one page an administrator needs most when something
     * is wrong, so what gets cached is a plain array and this puts it back
     * together.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: (string) ($data['key'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            category: Category::from($data['category']),
            status: Status::from($data['status']),
            severity: Severity::from($data['severity']),
            responsibility: Responsibility::from($data['responsibility']),
            // Already scrubbed when the original was constructed; Redactor is
            // idempotent, so passing it through again changes nothing.
            technicalReason: (string) ($data['technical_reason'] ?? ''),
            recommendedAction: (string) ($data['recommended_action'] ?? ''),
            adminAction: (string) ($data['admin_action'] ?? ''),
            requiresHostingSupport: (bool) ($data['requires_hosting_support'] ?? false),
            supportWording: (string) ($data['support_wording'] ?? ''),
            logReference: $data['log_reference'] ?? null,
            durationMs: (float) ($data['duration_ms'] ?? 0.0),
        );
    }
}
