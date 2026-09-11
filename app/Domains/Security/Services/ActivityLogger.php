<?php

namespace App\Domains\Security\Services;

use App\Domains\Security\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * The audit trail required by Rule 8: every sensitive admin action is
 * permission-checked AND audit logged. An admin write missing either is a bug.
 *
 * SECURITY: secret-shaped values are stripped before the snapshot is stored.
 * An audit log that records a credential defeats the point of encrypting it.
 */
class ActivityLogger
{
    /** Keys whose values are never recorded, at any nesting depth. */
    private const REDACT_KEYS = [
        'password', 'password_confirmation', 'secret', 'token', 'api_key',
        'credential', 'webhook_secret', 'private_key', 'remember_token',
        'client_secret', 'access_token', 'refresh_token',
    ];

    public function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $context = null,
    ): ActivityLog {
        $actor = Auth::user();

        return ActivityLog::create([
            'actor_id' => $actor?->getKey(),
            // Kept separately so the trail survives the actor being deleted.
            'actor_label' => $actor?->email ?? 'system',
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'before' => $before ? $this->redact($before) : null,
            'after' => $after ? $this->redact($after) : null,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
            'context' => $context ? $this->redact($context) : null,
        ]);
    }

    /** Convenience for the common "this model changed" case. */
    public function logModelChange(string $action, Model $subject, array $before, ?array $context = null): ActivityLog
    {
        $after = array_intersect_key($subject->getAttributes(), $before);

        return $this->log($action, $subject, $before, $after, $context);
    }

    /** @param array<mixed> $data */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);

                continue;
            }

            foreach (self::REDACT_KEYS as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $data[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $data;
    }
}
