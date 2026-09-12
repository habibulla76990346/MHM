<?php

namespace App\Domains\Notifications\Services;

use App\Domains\Billing\Models\Subscription;
use App\Domains\Notifications\Models\Announcement;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sending an announcement to its audience (§22).
 *
 * IT SENDS NOTHING ITSELF. Every message goes out through `Notifier`, so an
 * announcement obeys the same template, the same scrubbing and the same
 * delivery record as a renewal notice. A broadcast that had its own mail path
 * would be a second notification system, and the second one is always the one
 * that forgets a rule.
 */
class AnnouncementService
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Send one, once.
     *
     * `sent_at` IS STAMPED FIRST, before a single message goes out. An
     * administrator who presses the button twice, a scheduler run that
     * overlaps the previous one, a request that times out halfway through a
     * large audience — all three would otherwise mail everybody again, and an
     * apology for a duplicate is not something a queue can retract.
     *
     * @return int how many people it reached (0 if it had already been sent)
     */
    public function send(Announcement $announcement): int
    {
        if ($announcement->isSent()) {
            return 0;
        }

        $announcement->forceFill([
            'sent_at' => now(),
            'status' => Announcement::STATUS_SENT,
        ])->save();

        $values = [
            'title' => $announcement->title,
            'message' => $announcement->body,
            'url' => route('notifications'),
        ];

        $sent = 0;

        $this->recipients($announcement)->chunkById(200, function ($users) use ($announcement, $values, &$sent) {
            foreach ($users as $user) {
                $this->notifier->send(
                    $user,
                    NotificationEvent::ANNOUNCEMENT,
                    $values,
                    $announcement,
                    // In the app always. By email only when the owner said so
                    // — mailing every customer is not a thing to do by
                    // forgetting a checkbox.
                    $announcement->send_email
                        ? null
                        : [NotificationEvent::CHANNEL_DATABASE],
                );

                $sent++;
            }
        });

        $announcement->forceFill(['recipient_count' => $sent])->save();

        return $sent;
    }

    /** Announcements whose scheduled time has come. Run by the scheduler. */
    public function sendDue(): int
    {
        $sent = 0;

        Announcement::where('status', Announcement::STATUS_SCHEDULED)
            ->whereNull('sent_at')
            ->where(fn (Builder $q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))
            ->orderBy('id')
            ->chunkById(50, function ($announcements) use (&$sent) {
                foreach ($announcements as $announcement) {
                    $this->send($announcement);
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * The people an announcement is addressed to.
     *
     * A closed set, matching `Announcement::AUDIENCES`. An unknown audience
     * reaches NOBODY — failing closed is the only safe direction for something
     * that sends email.
     */
    public function recipients(Announcement $announcement): Builder
    {
        $query = User::query()->whereNotNull('email');

        return match ($announcement->audience) {
            Announcement::AUDIENCE_EVERYONE => $query,

            Announcement::AUDIENCE_SUBSCRIBERS => $query->whereIn(
                'id',
                Subscription::query()->live()->select('user_id'),
            ),

            Announcement::AUDIENCE_TRIALING => $query->whereIn(
                'id',
                Subscription::query()->where('status', Subscription::STATUS_TRIALING)->select('user_id'),
            ),

            Announcement::AUDIENCE_PAST_DUE => $query->whereIn(
                'id',
                Subscription::query()->where('status', Subscription::STATUS_PAST_DUE)->select('user_id'),
            ),

            Announcement::AUDIENCE_PLAN => $query->whereIn(
                'id',
                Subscription::query()
                    ->where('plan_id', $announcement->audience_plan_id)
                    ->live()
                    ->select('user_id'),
            ),

            default => $query->whereRaw('1 = 0'),
        };
    }

    /** How many people it would reach, for the screen that composes it. */
    public function audienceSize(Announcement $announcement): int
    {
        return $this->recipients($announcement)->count();
    }
}
