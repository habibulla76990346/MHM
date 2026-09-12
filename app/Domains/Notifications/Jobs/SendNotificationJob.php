<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Mail\TemplatedMail;
use App\Domains\Notifications\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Handing one email to the mail server.
 *
 * QUEUED, because SMTP is somebody else's server and a customer pressing
 * "pay" must not wait for it. On shared hosting the queue is drained by cron,
 * so this is slower there and never absent — the rule the whole platform
 * follows.
 *
 * It carries the RENDERED words rather than the model, so a template edited
 * between queueing and sending cannot change an email already promised, and a
 * retry sends exactly what the first attempt would have.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Back off rather than hammering a mail server that is refusing. */
    public array $backoff = [60, 300];

    public function __construct(
        private readonly int $deliveryId,
        private readonly string $subject,
        private readonly string $body,
    ) {}

    public function handle(): void
    {
        $delivery = NotificationDelivery::with('user')->find($this->deliveryId);

        if (! $delivery || ! $delivery->user || blank($delivery->user->email)) {
            return;
        }

        try {
            Mail::to($delivery->user->email)->send(new TemplatedMail($this->subject, $this->body));

            $delivery->forceFill([
                'status' => NotificationDelivery::STATUS_SENT,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            // The message is scrubbed by the model on write: a mail driver's
            // exception routinely carries the SMTP password.
            $delivery->forceFill([
                'status' => NotificationDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        NotificationDelivery::whereKey($this->deliveryId)->first()?->forceFill([
            'status' => NotificationDelivery::STATUS_FAILED,
            'failed_at' => now(),
            'error' => $e->getMessage(),
        ])->save();
    }
}
