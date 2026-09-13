<?php

namespace Tests\Feature\Deployment;

use App\Domains\Diagnostics\Checks\MailDeliveryCheck;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Notifications\Mail\TemplatedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * BLK-3: email that appears to send and reaches nobody.
 *
 * THIS IS THE MOST EXPENSIVE SILENT FAILURE IN THE PLATFORM. Laravel's default
 * mailer is `log`. Every message "sends", every delivery records as sent, the
 * delivery log is green, and not one message leaves the server. A renewal
 * notice that goes to a log file is a subscription that lapses without the
 * customer ever being told, and the first news of it is somebody asking why
 * they lost access to what they paid for.
 *
 * CONFIGURATION CANNOT PROVE THE OPPOSITE. A host, a port and a password being
 * present says nothing about whether a message arrives, so the fix is in three
 * parts and each part is checked here:
 *
 *   1. The shipped `.env.example` fails LOUDLY rather than silently — it names
 *      a real transport with blank credentials, so a fresh install cannot
 *      accidentally deliver to a log file.
 *   2. `aziv:mail:test` performs an actual send, through the real mailable and
 *      the real mailer, and never prints a credential when it fails.
 *   3. `aziv:diagnose` grades a non-delivering mailer as CRITICAL in
 *      production, so nobody has to remember to look.
 */
class MailConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /** Drivers that accept everything and deliver nothing. */
    private const NON_DELIVERING = ['log', 'array', 'null'];

    private function example(): string
    {
        return file_get_contents(base_path('.env.example'));
    }

    /** The value `.env.example` assigns to a key, or null if it does not. */
    private function exampleValue(string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $this->example(), $m) !== 1) {
            return null;
        }

        return trim($m[1], " \t\"'");
    }

    // -- 1. what a fresh install starts from ----------------------------------

    public function test_the_shipped_environment_file_does_not_deliver_to_a_log_file(): void
    {
        $mailer = $this->exampleValue('MAIL_MAILER');

        $this->assertNotNull($mailer, '.env.example does not mention MAIL_MAILER at all.');

        $this->assertNotContains($mailer, self::NON_DELIVERING,
            'A fresh install would send every renewal notice to a log file and record it as sent. '.
            'Ship a real transport with blank credentials: failing loudly is the point.');
    }

    public function test_the_shipped_environment_file_carries_no_credential(): void
    {
        foreach (['MAIL_PASSWORD', 'MAIL_USERNAME', 'DB_PASSWORD', 'APP_KEY', 'AWS_SECRET_ACCESS_KEY'] as $key) {
            $this->assertSame('', (string) $this->exampleValue($key),
                $key.' has a value in .env.example. A template that carries a secret is a secret in the repository.');
        }
    }

    public function test_the_shipped_environment_file_covers_what_a_server_cannot_start_without(): void
    {
        // Not every key the framework understands — the ones whose ABSENCE is
        // a production incident. Each has cost this project or the audit a
        // finding: the scheme in APP_URL, the proxy behind Cloudflare, the
        // cookie flag on an HTTPS site, the queue that every long operation
        // depends on.
        $required = [
            'APP_KEY', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
            'TRUSTED_PROXIES',
            'SESSION_SECURE_COOKIE', 'SESSION_SAME_SITE',
            'DB_CONNECTION', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'QUEUE_CONNECTION',
            'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS',
        ];

        $missing = array_values(array_filter(
            $required,
            fn (string $key) => $this->exampleValue($key) === null,
        ));

        $this->assertSame([], $missing,
            'These are not in .env.example, so nobody setting up a server is told they exist: '
            .implode(', ', $missing));
    }

    public function test_the_shipped_environment_file_is_production_shaped(): void
    {
        $this->assertSame('false', $this->exampleValue('APP_DEBUG'),
            'A debug page prints the environment — including this file — to whoever triggered the error.');

        $this->assertSame('true', $this->exampleValue('SESSION_SECURE_COOKIE'),
            'The session cookie would travel over plain HTTP, where it can be read and replayed.');

        $this->assertStringStartsWith('https://', (string) $this->exampleValue('APP_URL'),
            'Signed links are generated from APP_URL by the scheduler, so the scheme here is the scheme in the email.');

        $this->assertStringContainsString('aziv:mail:test', $this->example(),
            'Nothing tells the owner how to prove email actually arrives.');
    }

    // -- 2. proving delivery, safely ------------------------------------------

    public function test_the_verification_command_sends_the_real_email_a_customer_would_get(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->artisan('aziv:mail:test', ['email' => 'owner@example.test'])
            ->assertExitCode(0);

        // The SAME mailable every notification uses. A bespoke "test message"
        // would prove that a bespoke test message works.
        Mail::assertSent(TemplatedMail::class,
            fn (TemplatedMail $mail) => $mail->hasTo('owner@example.test'));
    }

    public function test_the_verification_command_fails_when_the_mailer_delivers_nothing(): void
    {
        // The array mailer accepts everything and sends none of it — the same
        // shape of lie as `log`, which is what production would be set to.
        config(['mail.default' => 'array']);

        $this->artisan('aziv:mail:test', ['email' => 'owner@example.test'])
            ->expectsOutputToContain('Nothing was delivered.')
            ->assertExitCode(1);
    }

    public function test_the_verification_command_never_prints_a_credential(): void
    {
        // SMTP failures routinely quote the connection string that failed, and
        // that string carries the password. A transport that fails with one
        // embedded is the only honest way to check the scrubbing runs.
        $password = 'MAIL_PASSWORD=hunter2-the-real-one';

        Mail::extend('exploding', fn () => new class($password) implements TransportInterface
        {
            public function __construct(private string $secret) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('Connection could not be established: '.$this->secret);
            }

            public function __toString(): string
            {
                return 'exploding://';
            }
        });

        config([
            'mail.default' => 'exploding',
            'mail.mailers.exploding' => ['transport' => 'exploding'],
        ]);

        $this->artisan('aziv:mail:test', ['email' => 'owner@example.test'])
            ->expectsOutputToContain('The send failed.')
            ->doesntExpectOutputToContain('hunter2')
            ->assertExitCode(1);
    }

    public function test_the_verification_command_refuses_an_address_that_is_not_one(): void
    {
        $this->artisan('aziv:mail:test', ['email' => 'not-an-address'])->assertExitCode(1);
    }

    // -- 3. nobody has to remember to look ------------------------------------

    public function test_diagnostics_grades_a_non_delivering_mailer_as_critical_in_production(): void
    {
        settings()->set('notifications.email_enabled', true);

        config(['mail.default' => 'log']);
        app()->detectEnvironment(fn () => 'production');

        $result = app(MailDeliveryCheck::class)->run();

        $this->assertSame(Status::Red, $result->status,
            'A live server delivering every email to a log file is not a warning.');
        $this->assertStringContainsString('delivers nothing', $result->technicalReason);
    }
}
