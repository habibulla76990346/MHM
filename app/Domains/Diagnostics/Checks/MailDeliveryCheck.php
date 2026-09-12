<?php

namespace App\Domains\Diagnostics\Checks;

use App\Domains\Diagnostics\Support\Category;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Responsibility;
use App\Domains\Diagnostics\Support\Severity;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Notifications\Models\NotificationDelivery;

/**
 * Whether email actually leaves this server (§22).
 *
 * THE FAILURE THIS EXISTS TO CATCH IS SILENT. Laravel's default mailer is
 * `log`: everything "sends" perfectly, every delivery is recorded as sent, and
 * not one message reaches anybody. A renewal notice that goes to a log file is
 * a subscription that quietly lapses, and the first news of it is a customer
 * asking why they lost access.
 *
 * IT NEVER TOUCHES A CREDENTIAL. It reads the driver name and whether a host
 * and a from-address are configured — never the password, and never a value
 * that could be echoed back into the report. Rule 4 holds by construction:
 * this asks "is it configured?" and answers with a boolean.
 */
class MailDeliveryCheck extends BaseCheck
{
    /** Drivers that deliver nothing, however well they appear to work. */
    private const NON_DELIVERING = ['log', 'array', 'null'];

    public function key(): string
    {
        return 'mail.delivery';
    }

    public function title(): string
    {
        return 'Email delivery';
    }

    public function category(): Category
    {
        return Category::Mail;
    }

    public function run(): CheckResult
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (! settings('notifications.email_enabled')) {
            return $this->result(
                Status::Grey,
                Severity::Informational,
                'Email notifications are switched off, so nothing is sent by email.',
                'Customers still see notices in the app. Renewal reminders will not reach anybody who does not sign in.',
                'Open Admin → Settings → Notifications to switch email back on.',
            );
        }

        if (in_array($mailer, self::NON_DELIVERING, true)) {
            // GRADED BY ENVIRONMENT, not by the driver alone. On a developer's
            // machine `log` is the correct setting and a red would be noise
            // that teaches people to ignore this screen. In production it is
            // the most expensive silent failure in the platform.
            $live = app()->environment('production');

            return $this->result(
                $live ? Status::Red : Status::Yellow,
                $live ? Severity::Critical : Severity::Informational,
                'Mail is set to "'.$mailer.'", which delivers nothing.',
                $live
                    ? 'Every email appears to send and reaches nobody — including renewal notices, so subscriptions lapse silently.'
                    : 'Expected outside production: messages are written to the log instead of being sent.',
                'Set MAIL_MAILER in your .env to smtp (or your provider) and fill in the host, port and credentials.',
            );
        }

        $missing = [];

        if ($mailer === 'smtp' && blank(config('mail.mailers.smtp.host'))) {
            $missing[] = 'MAIL_HOST';
        }

        if (blank($from)) {
            $missing[] = 'MAIL_FROM_ADDRESS';
        }

        if ($missing !== []) {
            return $this->result(
                Status::Red,
                Severity::High,
                'Mail is set to "'.$mailer.'" but these are not configured: '.implode(', ', $missing).'.',
                'Sending will fail, and the delivery log will fill with failures.',
                'Fill in '.implode(' and ', $missing).' in your .env file.',
            );
        }

        // Real evidence beats configuration: if messages have been failing,
        // say so. The reason is read from the scrubbed column, never from a
        // live exception.
        $recentFailures = NotificationDelivery::where('status', NotificationDelivery::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recentFailures > 0) {
            return $this->result(
                Status::Yellow,
                Severity::High,
                $recentFailures.' email(s) failed to send in the last 24 hours.',
                'Customers are not receiving notices that were queued for them.',
                'Open Admin → Notifications → Delivery log to see what the mail server said.',
            );
        }

        return $this->result(
            Status::Green,
            Severity::Informational,
            'Sending through "'.$mailer.'", from '.$from.'.',
            'Notifications are reaching customers.',
            'Nothing to do.',
        );
    }

    private function result(
        Status $status,
        Severity $severity,
        string $technicalReason,
        string $recommendedAction,
        string $adminAction,
    ): CheckResult {
        return new CheckResult(
            key: $this->key(),
            title: $this->title(),
            category: $this->category(),
            status: $status,
            severity: $severity,
            responsibility: Responsibility::Configuration,
            technicalReason: $technicalReason,
            recommendedAction: $recommendedAction,
            adminAction: $adminAction,
        );
    }
}
