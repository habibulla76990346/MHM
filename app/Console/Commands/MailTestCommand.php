<?php

namespace App\Console\Commands;

use App\Domains\Diagnostics\Support\Redactor;
use App\Domains\Notifications\Mail\TemplatedMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Proves that email actually leaves this server — the one thing configuration
 * cannot prove (§22, BLK-3).
 *
 * WHY A COMMAND AND NOT A CHECK. `aziv:diagnose` reads the driver, the host
 * and the from-address and answers "is this configured?". That is a different
 * question from "does it arrive", and the gap between them is where the most
 * expensive silent failure in the platform lives: Laravel's default mailer is
 * `log`, so every message sends perfectly, every delivery records as sent, and
 * nothing reaches anybody. Only an actual send settles it, and only the owner
 * can say whether it landed in an inbox.
 *
 * IT SENDS THE REAL THING. The same `TemplatedMail` every notification uses,
 * through the same mailer, rendered through the same theme — so a failure in
 * the shell, the palette or the transport shows up here rather than in a
 * customer's renewal notice. A bespoke "test message" would prove that a
 * bespoke test message works.
 *
 * IT NEVER PRINTS A CREDENTIAL. The report names the driver and the address it
 * was sent to, and nothing else; the mail server's own error is scrubbed by
 * the same `Redactor` the diagnostics layer uses, because SMTP failures
 * routinely quote the connection string that failed — and that string carries
 * the password.
 */
class MailTestCommand extends Command
{
    /** Drivers that accept everything and deliver nothing. */
    private const NON_DELIVERING = ['log', 'array', 'null'];

    protected $signature = 'aziv:mail:test
                            {email : Where to send it}
                            {--subject= : Override the subject line}';

    protected $description = 'Send one real email through the configured mailer and report what happened';

    public function handle(): int
    {
        $address = (string) $this->argument('email');

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error('That is not an email address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if (blank($from)) {
            $this->components->error('MAIL_FROM_ADDRESS is empty, so there is no address to send from.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Driver', $mailer);
        $this->components->twoColumnDetail('From', $from);
        $this->components->twoColumnDetail('To', $address);

        $subject = (string) ($this->option('subject') ?: __('Test message from :app', [
            'app' => settings('branding.app_name'),
        ]));

        $started = microtime(true);

        try {
            Mail::to($address)->send(new TemplatedMail($subject, $this->body()));
        } catch (Throwable $e) {
            // The mail server's own words, with anything credential-shaped
            // removed. Support needs the reason; nobody needs the password.
            $this->newLine();
            $this->components->error('The send failed.');
            $this->line('  '.Redactor::scrub($e->getMessage()));

            return self::FAILURE;
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        if (in_array($mailer, self::NON_DELIVERING, true)) {
            // Not an error in the transport — an error in the deployment. The
            // send "succeeded" and reached nobody, which is exactly the
            // failure this command exists to make visible.
            $this->newLine();
            $this->components->error('Nothing was delivered.');
            $this->line('  The mailer is "'.$mailer.'", which accepts every message and sends none.');
            $this->line('  Set MAIL_MAILER and its host, port and credentials in .env, then run this again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('The mail server accepted the message in '.$elapsed.'ms.');
        $this->line('  That is as far as this server can see. Check '.$address.' to confirm it arrived,');
        $this->line('  and check the spam folder — a new sending domain usually lands there first.');

        return self::SUCCESS;
    }

    private function body(): string
    {
        return __("This is a test message.\n\nIf you are reading it, :app can send email: renewal notices, invoices, password resets and announcements will reach your customers.\n\nSent :when.", [
            'app' => settings('branding.app_name'),
            'when' => now()->toDayDateTimeString(),
        ]);
    }
}
