<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Notifications\Support\TemplateRenderer;
use Illuminate\Database\Eloquent\Model;

/**
 * An owner's wording for one event on one channel (§22).
 *
 * A ROW IS AN OVERRIDE. `NotificationEvent` ships wording for everything, so
 * an empty table sends complete, correct email — and deleting a row restores
 * the shipped words rather than silencing the event. Switching an event off is
 * a separate, deliberate act (`is_active`), which is what an owner means when
 * they say "stop sending this".
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'event_key', 'channel', 'subject', 'body', 'variables', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The template in force for this event and channel.
     *
     * Returns null when the event is switched off — distinct from "no row",
     * which means "use the shipped wording".
     *
     * @return array{subject: string, body: string}|null
     */
    public static function inForce(string $eventKey, string $channel): ?array
    {
        $definition = NotificationEvent::definition($eventKey);

        if (! $definition || ! NotificationEvent::supportsChannel($eventKey, $channel)) {
            return null;
        }

        $override = static::query()
            ->where('event_key', $eventKey)
            ->where('channel', $channel)
            ->first();

        if ($override && ! $override->is_active) {
            return null;
        }

        return [
            'subject' => (string) ($override?->subject ?: $definition['subject']),
            'body' => (string) ($override?->body ?: $definition['body']),
        ];
    }

    /**
     * Placeholders used here that the event does not provide.
     *
     * @return array<int, string>
     */
    public function unknownPlaceholders(): array
    {
        $declared = NotificationEvent::variables((string) $this->event_key);

        return array_values(array_unique(array_merge(
            TemplateRenderer::unknownPlaceholders((string) $this->subject, $declared),
            TemplateRenderer::unknownPlaceholders((string) $this->body, $declared),
        )));
    }
}
