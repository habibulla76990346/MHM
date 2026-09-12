<?php

namespace Tests\Feature\Notifications;

use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Mail\TemplatedMail;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\NotificationTemplate;
use App\Domains\Notifications\Services\Notifier;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Notifications\Support\TemplateRenderer;
use App\Domains\Theming\Services\ThemeService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The notification layer (§22).
 *
 * The assertions that matter here are not "an email was sent". They are the
 * ones about what CANNOT happen: an event nobody declared, a value nobody
 * declared reaching a customer, a secret surviving into an inbox or an audit
 * row, and a call site quietly turning an in-app notice into an email.
 */
class NotificationLayerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->user = User::factory()->create(['name' => 'Asha', 'email' => 'asha@example.test']);
    }

    private function notifier(): Notifier
    {
        return app(Notifier::class);
    }

    /** @return array<string, mixed> a complete set of values for the renewal notice */
    private function renewalValues(array $overrides = []): array
    {
        return array_merge([
            'plan' => 'Pro',
            'amount' => 'INR 999.00',
            'currency' => 'INR',
            'invoice_number' => 'INV-0001',
            'due_date' => '1 October 2026',
            'pay_url' => config('app.url').'/renew/abc',
        ], $overrides);
    }

    // -- the catalogue is the boundary ---------------------------------------

    public function test_an_undeclared_event_is_refused_rather_than_sent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // A typo must be a failure at the call site. Delivered, it would be an
        // email nobody can find, edit or switch off.
        $this->notifier()->send($this->user, 'renewal.dew', []);
    }

    public function test_a_caller_can_narrow_the_channels_but_never_widen_them(): void
    {
        // The catalogue says announcements may go by email. A caller asking
        // for in-app only gets in-app only.
        $deliveries = $this->notifier()->send(
            $this->user,
            NotificationEvent::ANNOUNCEMENT,
            ['title' => 'Maintenance', 'message' => 'Sunday 02:00', 'url' => config('app.url')],
            null,
            [NotificationEvent::CHANNEL_DATABASE],
        );

        $this->assertCount(1, $deliveries);
        $this->assertSame(NotificationEvent::CHANNEL_DATABASE, $deliveries[0]->channel);
        Mail::assertNothingQueued();

        // And a caller asking for a channel the event does not declare gets
        // nothing, rather than that channel.
        $none = $this->notifier()->send(
            $this->user,
            NotificationEvent::ANNOUNCEMENT,
            ['title' => 'x', 'message' => 'y'],
            null,
            ['carrier_pigeon'],
        );

        $this->assertSame([], $none);
    }

    public function test_every_declared_event_ships_with_wording_of_its_own(): void
    {
        // An empty database must still send complete email. A notification
        // system that needs seeding before it works is one that silently does
        // nothing on a fresh install.
        foreach (NotificationEvent::all() as $key => $definition) {
            $this->assertNotSame('', trim($definition['subject']), $key.' has no subject.');
            $this->assertNotSame('', trim($definition['body']), $key.' has no body.');

            // And its own wording may only use its own variables.
            $this->assertSame(
                [],
                TemplateRenderer::unknownPlaceholders(
                    $definition['subject'].' '.$definition['body'],
                    $definition['variables'],
                ),
                $key.' ships with a placeholder it does not provide.',
            );
        }
    }

    // -- what reaches a customer ---------------------------------------------

    public function test_only_declared_values_are_substituted(): void
    {
        $rendered = TemplateRenderer::render(
            'Hello {{name}} — {{plan}} — {{card_number}}',
            ['name' => 'Their name', 'plan' => 'The plan'],
            ['name' => 'Asha', 'plan' => 'Pro', 'card_number' => '4111111111111111'],
        );

        $this->assertStringContainsString('Asha', $rendered);
        $this->assertStringContainsString('Pro', $rendered);
        // Passed in, but never declared — so it is not a value we happen not
        // to have, it is a value this message was never given.
        $this->assertStringNotContainsString('4111', $rendered);
        $this->assertStringNotContainsString('{{', $rendered);
    }

    public function test_a_declared_value_that_looks_like_a_secret_is_scrubbed(): void
    {
        $rendered = TemplateRenderer::render(
            'Reference: {{plan}}',
            ['plan' => 'The plan'],
            ['plan' => 'sk-live-ABCDEFGHIJKLMNOP'],
        );

        $this->assertStringNotContainsString('sk-live-ABCDEFGHIJKLMNOP', $rendered);
        $this->assertStringContainsString('[redacted]', $rendered);
    }

    public function test_a_link_back_to_this_platform_survives_the_scrubber(): void
    {
        // A signed payment link ends in a long opaque signature, which is also
        // what a bearer token looks like. Scrubbing it would mail a broken
        // link, and nobody would notice until a customer could not pay.
        $ours = config('app.url').'/renew/abc?expires=1&signature='.str_repeat('a1b2c3d4', 8);

        $this->assertSame(
            $ours,
            TemplateRenderer::render('{{pay_url}}', ['pay_url' => 'link'], ['pay_url' => $ours]),
        );

        // Somewhere else, carrying the same shape, is scrubbed.
        $theirs = 'https://not-us.example.com/x?token='.str_repeat('a1b2c3d4', 8);

        $this->assertStringNotContainsString(
            str_repeat('a1b2c3d4', 8),
            TemplateRenderer::render('{{pay_url}}', ['pay_url' => 'link'], ['pay_url' => $theirs]),
        );
    }

    // -- the owner's control -------------------------------------------------

    public function test_an_override_replaces_the_shipped_wording_and_deleting_it_restores_it(): void
    {
        $shipped = NotificationTemplate::inForce(NotificationEvent::RENEWAL_DUE, NotificationEvent::CHANNEL_MAIL);

        $template = NotificationTemplate::create([
            'event_key' => NotificationEvent::RENEWAL_DUE,
            'channel' => NotificationEvent::CHANNEL_MAIL,
            'subject' => 'Time to renew {{plan}}',
            'body' => 'Pay here: {{pay_url}}',
            'is_active' => true,
        ]);

        $this->assertSame(
            'Time to renew {{plan}}',
            NotificationTemplate::inForce(NotificationEvent::RENEWAL_DUE, NotificationEvent::CHANNEL_MAIL)['subject'],
        );

        $template->delete();

        // Deleting restores the shipped words. It does NOT silence the event —
        // that is a separate, deliberate act.
        $this->assertSame(
            $shipped,
            NotificationTemplate::inForce(NotificationEvent::RENEWAL_DUE, NotificationEvent::CHANNEL_MAIL),
        );
    }

    public function test_switching_a_template_off_stops_it_and_says_so(): void
    {
        NotificationTemplate::create([
            'event_key' => NotificationEvent::RENEWAL_DUE,
            'channel' => NotificationEvent::CHANNEL_MAIL,
            'is_active' => false,
        ]);

        $deliveries = $this->notifier()->send($this->user, NotificationEvent::RENEWAL_DUE, $this->renewalValues());

        Mail::assertNothingQueued();

        $mail = collect($deliveries)->firstWhere('channel', NotificationEvent::CHANNEL_MAIL);

        // A record exists even though nothing was sent: "we never sent it" and
        // "we sent it and it bounced" need different answers from support.
        $this->assertSame(NotificationDelivery::STATUS_SUPPRESSED, $mail->status);

        // The in-app copy still goes: switching off one channel is not
        // switching off the event.
        $inApp = collect($deliveries)->firstWhere('channel', NotificationEvent::CHANNEL_DATABASE);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $inApp->status);
    }

    public function test_the_global_email_switch_stops_email_and_keeps_the_app(): void
    {
        settings()->set('notifications.email_enabled', false);

        $this->notifier()->send($this->user, NotificationEvent::RENEWAL_DUE, $this->renewalValues());

        Mail::assertNothingQueued();
        $this->assertSame(1, $this->user->fresh()->notifications()->count());

        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => NotificationEvent::CHANNEL_MAIL,
            'status' => NotificationDelivery::STATUS_SUPPRESSED,
        ]);
    }

    // -- what is written down ------------------------------------------------

    public function test_email_carries_the_rendered_words(): void
    {
        $this->notifier()->send($this->user, NotificationEvent::RENEWAL_DUE, $this->renewalValues());

        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) {
            return $mail->hasTo('asha@example.test')
                && str_contains($mail->bodyText, 'INV-0001')
                && str_contains($mail->bodyText, 'Asha')
                && str_contains($mail->subjectLine, '1 October 2026');
        });
    }

    public function test_sending_hands_the_work_to_the_queue_rather_than_the_request(): void
    {
        Queue::fake();

        $this->notifier()->send($this->user, NotificationEvent::RENEWAL_DUE, $this->renewalValues());

        // A slow SMTP server must never be why a customer's checkout hangs.
        // On shared hosting the queue is drained by cron, so this is slower
        // there and never absent — the rule the whole platform follows.
        Queue::assertPushed(SendNotificationJob::class);

        $this->assertInstanceOf(ShouldQueue::class, new SendNotificationJob(1, 's', 'b'));

        // The in-app copy is written straight away: it is one row, and a
        // customer refreshing the page should already see it.
        $this->assertSame(1, $this->user->fresh()->notifications()->count());
    }

    public function test_a_delivery_record_holds_no_message(): void
    {
        $this->notifier()->send($this->user, NotificationEvent::RENEWAL_DUE, $this->renewalValues());

        $columns = array_keys(NotificationDelivery::first()->getAttributes());

        // The audit trail says a renewal notice went to this person about this
        // invoice. It cannot say what the email contained, because an audit
        // table is read by more people than an inbox is.
        foreach (['subject', 'body', 'message', 'content', 'html'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }

        $row = NotificationDelivery::where('channel', NotificationEvent::CHANNEL_MAIL)->first();

        $this->assertSame(NotificationEvent::RENEWAL_DUE, $row->event_key);
        $this->assertSame($this->user->getKey(), $row->user_id);
    }

    public function test_a_failure_message_is_scrubbed_before_it_is_stored(): void
    {
        $delivery = NotificationDelivery::create([
            'event_key' => NotificationEvent::RENEWAL_DUE,
            'channel' => NotificationEvent::CHANNEL_MAIL,
            'user_id' => $this->user->getKey(),
            'status' => NotificationDelivery::STATUS_FAILED,
            // Exactly the shape a mail driver's exception takes.
            'error' => 'Connection could not be established with host smtp://user:hunter2@mail.example.com:587',
        ]);

        $this->assertStringNotContainsString('hunter2', (string) $delivery->fresh()->error);
        $this->assertStringContainsString('[redacted]', (string) $delivery->fresh()->error);
    }

    public function test_the_in_app_copy_is_stored_as_data_not_markup(): void
    {
        $this->notifier()->send($this->user, NotificationEvent::RENEWAL_DUE, $this->renewalValues());

        $data = $this->user->fresh()->notifications()->first()->data;

        $this->assertSame(NotificationEvent::RENEWAL_DUE, $data['event']);
        $this->assertStringContainsString('INV-0001', $data['body']);
        // Only the link travels, not the whole variable bag a caller happened
        // to pass.
        $this->assertSame(config('app.url').'/renew/abc', $data['url']);
        $this->assertArrayNotHasKey('currency', $data);
    }

    public function test_the_email_shell_is_painted_from_the_owners_theme(): void
    {
        $palette = app(ThemeService::class)->emailPalette();

        // Email clients strip stylesheets, so the colours have to be resolved
        // to values and inlined. Every one of them must be a real colour, or
        // the email renders as whatever the client feels like.
        foreach (['primary', 'surface', 'text', 'muted', 'border', 'background'] as $key) {
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $palette[$key], $key.' is not a colour.');
        }

        // Plain data, so it survives a real cache store. Caching anything
        // richer returns `__PHP_Incomplete_Class` on a file or Redis driver,
        // and the array driver used in tests would never catch it.
        $this->assertSame($palette, json_decode((string) json_encode($palette), true));
    }
}
