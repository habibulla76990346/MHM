<?php

namespace App\Domains\Notifications\Services;

use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Notifications\Notifications\TemplatedNotification;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Notifications\Support\TemplateRenderer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The one way anything in Aziv AI tells a customer something (§22).
 *
 * Everything goes through here: renewal notices, invoices, payment results,
 * announcements. There is no second path, because a second path would be a
 * second place where the rules below have to be remembered.
 *
 * THE RULES
 *
 *  - An UNDECLARED event throws. `NotificationEvent` is the catalogue, and a
 *    typo must be a failure at the call site rather than an email nobody can
 *    find, edit or switch off.
 *  - Only the channels the event DECLARES are used, whatever a template row
 *    says. Turning an event into an email by editing a database row is not
 *    something an administrator should be able to do by accident.
 *  - Only DECLARED VARIABLES reach the template, and every one is scrubbed on
 *    the way (see `TemplateRenderer`). A credential cannot reach an email even
 *    if a caller passes one, because the renderer will not place a value the
 *    event never declared.
 *  - Email is QUEUED; in-app is written immediately. A slow SMTP server must
 *    never be why a customer's checkout hangs — and on shared hosting the
 *    queue runs from cron, so this differs in speed and never in capability.
 *  - Every attempt leaves a DELIVERY RECORD, including the ones deliberately
 *    not sent. "We never sent it" and "we sent it and it bounced" need
 *    different answers from support.
 */
class Notifier
{
    /**
     * @param  array<string, mixed>  $values  keyed by the event's declared variables
     * @param  array<int, string>|null  $onlyChannels  narrow the event's channels; never widens them
     * @return array<int, NotificationDelivery>
     */
    public function send(
        User $user,
        string $eventKey,
        array $values = [],
        ?Model $reference = null,
        ?array $onlyChannels = null,
    ): array {
        if (! NotificationEvent::exists($eventKey)) {
            throw new InvalidArgumentException(
                "Unknown notification event [{$eventKey}]. Declare it in NotificationEvent first."
            );
        }

        $definition = NotificationEvent::definition($eventKey);
        $values = $this->withDefaults($user, $values);
        $deliveries = [];

        // A caller may NARROW the channels — an announcement the owner chose
        // not to email goes in the app only. It can never widen them: the
        // event's declaration is the ceiling, so nothing can be turned into
        // an email by a call site.
        $channels = $onlyChannels === null
            ? $definition['channels']
            : array_values(array_intersect($definition['channels'], $onlyChannels));

        foreach ($channels as $channel) {
            $deliveries[] = $channel === NotificationEvent::CHANNEL_MAIL
                ? $this->sendMail($user, $eventKey, $values, $reference)
                : $this->sendInApp($user, $eventKey, $values, $reference);
        }

        return array_values(array_filter($deliveries));
    }

    // -- channels ------------------------------------------------------------

    /** @param array<string, mixed> $values */
    private function sendMail(User $user, string $eventKey, array $values, ?Model $reference): NotificationDelivery
    {
        $delivery = $this->record($user, $eventKey, NotificationEvent::CHANNEL_MAIL, $reference);

        if (! settings('notifications.email_enabled')) {
            return $this->suppress($delivery);
        }

        if (blank($user->email)) {
            return $this->suppress($delivery);
        }

        $rendered = $this->render($eventKey, NotificationEvent::CHANNEL_MAIL, $values);

        if (! $rendered) {
            // The owner switched this event off. A record still exists, so
            // "why did they not get it?" has an answer.
            return $this->suppress($delivery);
        }

        SendNotificationJob::dispatch($delivery->getKey(), $rendered['subject'], $rendered['body']);

        return $delivery;
    }

    /** @param array<string, mixed> $values */
    private function sendInApp(User $user, string $eventKey, array $values, ?Model $reference): NotificationDelivery
    {
        $delivery = $this->record($user, $eventKey, NotificationEvent::CHANNEL_DATABASE, $reference);

        $rendered = $this->render($eventKey, NotificationEvent::CHANNEL_DATABASE, $values);

        if (! $rendered) {
            return $this->suppress($delivery);
        }

        $user->notify(new TemplatedNotification(
            eventKey: $eventKey,
            subject: $rendered['subject'],
            body: $rendered['body'],
            // Only the link, never the whole variable bag: a stored
            // notification is read back by the app and should carry what it
            // needs to render, not everything the sender happened to have.
            url: $this->linkFrom($values),
        ));

        $delivery->forceFill([
            'status' => NotificationDelivery::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        return $delivery;
    }

    // -- internals -----------------------------------------------------------

    /** @return array{subject: string, body: string}|null */
    private function render(string $eventKey, string $channel, array $values): ?array
    {
        $template = NotificationTemplate::inForce($eventKey, $channel);

        if (! $template) {
            return null;
        }

        $declared = NotificationEvent::variables($eventKey);

        return [
            'subject' => TemplateRenderer::render($template['subject'], $declared, $values),
            'body' => TemplateRenderer::render($template['body'], $declared, $values),
        ];
    }

    /**
     * Values every event can rely on.
     *
     * Supplied here rather than by each caller, so "the customer's name" means
     * the same thing in every email and no caller has to remember it.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withDefaults(User $user, array $values): array
    {
        return array_merge([
            'name' => $user->name ?: __('there'),
            'app_name' => (string) settings('branding.app_name'),
        ], $values);
    }

    private function record(User $user, string $eventKey, string $channel, ?Model $reference): NotificationDelivery
    {
        return NotificationDelivery::create([
            'event_key' => $eventKey,
            'channel' => $channel,
            'user_id' => $user->getKey(),
            'status' => NotificationDelivery::STATUS_QUEUED,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id' => $reference ? (string) $reference->getKey() : null,
        ]);
    }

    private function suppress(NotificationDelivery $delivery): NotificationDelivery
    {
        $delivery->forceFill(['status' => NotificationDelivery::STATUS_SUPPRESSED])->save();

        return $delivery;
    }

    /** @param array<string, mixed> $values */
    private function linkFrom(array $values): ?string
    {
        foreach (['pay_url', 'invoice_url', 'billing_url', 'url'] as $key) {
            if (filled($values[$key] ?? null)) {
                return (string) $values[$key];
            }
        }

        return null;
    }
}
