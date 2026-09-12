<?php

namespace Tests\Feature\Diagnostics;

use App\Domains\Diagnostics\Checks\MailDeliveryCheck;
use App\Domains\Diagnostics\Support\CheckResult;
use App\Domains\Diagnostics\Support\Status;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The check that catches the most expensive silent failure in §22.
 *
 * Laravel's default mailer is `log`. Everything "sends" perfectly, every
 * delivery is recorded as sent, and not one message reaches anybody — so a
 * renewal notice goes into a file, the subscription lapses, and the first
 * anyone hears of it is a customer asking why they lost access.
 */
class MailDeliveryCheckTest extends TestCase
{
    use RefreshDatabase;

    private function check(): CheckResult
    {
        return app(MailDeliveryCheck::class)->run();
    }

    public function test_a_non_delivering_mailer_is_reported(): void
    {
        config(['mail.default' => 'log']);

        $result = $this->check();

        $this->assertNotSame(Status::Green, $result->status);
        $this->assertStringContainsString('delivers nothing', $result->technicalReason);
    }

    public function test_it_is_only_critical_where_it_actually_costs_money(): void
    {
        config(['mail.default' => 'log']);

        // On a developer's machine `log` is the correct setting, and a red
        // here would be noise that teaches people to ignore this screen.
        $this->assertSame(Status::Yellow, $this->check()->status);

        app()->detectEnvironment(fn () => 'production');

        $this->assertSame(Status::Red, $this->check()->status);
    }

    public function test_a_configured_mailer_reports_working(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.from.address' => 'hello@example.test',
        ]);

        $this->assertSame(Status::Green, $this->check()->status);
    }

    public function test_a_half_configured_mailer_names_what_is_missing(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => null,
            'mail.from.address' => '',
        ]);

        $result = $this->check();

        $this->assertSame(Status::Red, $result->status);
        $this->assertStringContainsString('MAIL_HOST', $result->technicalReason);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS', $result->technicalReason);
    }

    public function test_real_failures_outrank_a_healthy_looking_configuration(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.from.address' => 'hello@example.test',
        ]);

        NotificationDelivery::create([
            'event_key' => NotificationEvent::RENEWAL_DUE,
            'channel' => NotificationEvent::CHANNEL_MAIL,
            'user_id' => User::factory()->create()->getKey(),
            'status' => NotificationDelivery::STATUS_FAILED,
            'error' => 'Connection refused',
        ]);

        // Evidence beats configuration: a mailer that looks right and is
        // failing is worse than one that is obviously unset, because nobody
        // is looking for it.
        $this->assertSame(Status::Yellow, $this->check()->status);
        $this->assertStringContainsString('failed to send', $this->check()->technicalReason);
    }

    public function test_the_report_never_carries_a_mail_password(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.username' => 'postmaster@example.test',
            'mail.mailers.smtp.password' => 'hunter2-super-secret',
            'mail.from.address' => 'hello@example.test',
        ]);

        // Rule 4 by construction: the check asks "is it configured?" and
        // answers with a verdict. It never reads the password, so no output
        // path can carry it.
        $json = json_encode($this->check());

        $this->assertStringNotContainsString('hunter2', (string) $json);
        $this->assertStringNotContainsString('postmaster@example.test', (string) $json);
    }
}
