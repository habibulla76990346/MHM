<?php

namespace App\Domains\Notifications\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One in-app notification, already rendered.
 *
 * Deliberately dumb: the wording was decided by `Notifier` from the owner's
 * template, so nothing here composes text. That keeps one place where a
 * customer's words come from.
 *
 * `via` is database only. Email goes through a queued job with its own
 * delivery record, because "did it send?" and "did it bounce?" are questions
 * Laravel's notification table cannot answer.
 */
class TemplatedNotification extends Notification
{
    public function __construct(
        public readonly string $eventKey,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $url = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->eventKey,
            'subject' => $this->subject,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }
}
