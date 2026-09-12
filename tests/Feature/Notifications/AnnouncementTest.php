<?php

namespace Tests\Feature\Notifications;

use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Notifications\Models\Announcement;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Services\AnnouncementService;
use App\Domains\Notifications\Support\NotificationEvent;
use App\Domains\Security\Services\PermissionRegistry;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Announcements, offers, maintenance notices and system updates (§22).
 *
 * The two things that must hold: an announcement reaches EXACTLY its audience,
 * and it is sent EXACTLY once. Mailing the wrong list and mailing the right
 * list twice are both apologies a queue cannot retract.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Plan $pro;

    private Plan $lite;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->pro = $this->plan('Pro');
        $this->lite = $this->plan('Lite');
    }

    private function plan(string $name): Plan
    {
        return Plan::create([
            'name' => $name,
            'slug' => strtolower($name),
            'status' => Plan::STATUS_ACTIVE,
            'billing_cycle' => 'monthly',
            'credits_per_period' => 100,
        ]);
    }

    private function customer(string $email, ?Plan $plan = null, string $status = Subscription::STATUS_ACTIVE): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole(PermissionRegistry::CUSTOMER);

        if ($plan) {
            Subscription::create([
                'user_id' => $user->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => $status,
                'current_period_start' => now()->subDay(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        return $user->fresh();
    }

    private function announcement(array $attributes = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => 'Scheduled maintenance',
            'body' => 'We are upgrading on Sunday at 02:00.',
            'level' => 'warning',
            'audience' => Announcement::AUDIENCE_EVERYONE,
            'status' => Announcement::STATUS_DRAFT,
            'send_email' => true,
        ], $attributes));
    }

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    // -- the audience is exactly the audience --------------------------------

    public function test_each_audience_reaches_the_people_it_names(): void
    {
        $everyone = $this->customer('nobody@example.test');
        $onPro = $this->customer('pro@example.test', $this->pro);
        $onLite = $this->customer('lite@example.test', $this->lite);
        $trialing = $this->customer('trial@example.test', $this->pro, Subscription::STATUS_TRIALING);
        $overdue = $this->customer('late@example.test', $this->pro, Subscription::STATUS_PAST_DUE);

        $cases = [
            Announcement::AUDIENCE_EVERYONE => [$everyone, $onPro, $onLite, $trialing, $overdue],
            Announcement::AUDIENCE_SUBSCRIBERS => [$onPro, $onLite, $trialing],
            Announcement::AUDIENCE_TRIALING => [$trialing],
            Announcement::AUDIENCE_PAST_DUE => [$overdue],
        ];

        foreach ($cases as $audience => $expected) {
            $reached = $this->service()
                ->recipients($this->announcement(['audience' => $audience]))
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $this->assertSame(
                collect($expected)->pluck('id')->sort()->values()->all(),
                $reached,
                $audience.' reached the wrong people.',
            );
        }

        // One plan, and only that plan.
        $planOnly = $this->service()->recipients($this->announcement([
            'audience' => Announcement::AUDIENCE_PLAN,
            'audience_plan_id' => $this->pro->getKey(),
        ]))->pluck('id')->all();

        $this->assertContains($onPro->getKey(), $planOnly);
        $this->assertNotContains($onLite->getKey(), $planOnly);
    }

    public function test_an_audience_nobody_declared_reaches_nobody(): void
    {
        $this->customer('someone@example.test', $this->pro);

        // Failing closed is the only safe direction for something that sends
        // email. A typo in the column must not mean "everybody".
        $this->assertSame(0, $this->service()->audienceSize(
            $this->announcement(['audience' => 'whoever'])
        ));
    }

    // -- sent exactly once ---------------------------------------------------

    public function test_it_is_sent_once_however_many_times_it_is_pressed(): void
    {
        $this->customer('a@example.test');
        $this->customer('b@example.test');

        $announcement = $this->announcement();

        $this->assertSame(2, $this->service()->send($announcement));

        // A second press, a scheduler run overlapping the first, a request
        // that timed out halfway — none of them may mail anybody again.
        $this->assertSame(0, $this->service()->send($announcement->fresh()));
        $this->assertSame(0, $this->service()->sendDue());

        Mail::assertSentCount(2);
        $this->assertSame(2, NotificationDelivery::where('channel', NotificationEvent::CHANNEL_MAIL)->count());
        $this->assertSame(2, $announcement->fresh()->recipient_count);
        $this->assertSame(Announcement::STATUS_SENT, $announcement->fresh()->status);
    }

    public function test_email_is_off_unless_the_owner_asks_for_it(): void
    {
        $user = $this->customer('quiet@example.test');

        $this->service()->send($this->announcement(['send_email' => false]));

        Mail::assertNothingSent();

        // It is still in the app, which is the half that costs nothing and
        // cannot be taken back either way.
        $this->assertSame(1, $user->fresh()->notifications()->count());
        $this->assertSame(0, NotificationDelivery::where('channel', NotificationEvent::CHANNEL_MAIL)->count());
    }

    public function test_a_draft_goes_nowhere_and_a_scheduled_one_waits_for_its_time(): void
    {
        $this->customer('c@example.test');

        $this->announcement(['status' => Announcement::STATUS_DRAFT]);
        $later = $this->announcement([
            'status' => Announcement::STATUS_SCHEDULED,
            'send_at' => now()->addDay(),
        ]);

        $this->assertSame(0, $this->service()->sendDue());
        Mail::assertNothingSent();

        $this->travelTo(now()->addDays(2));

        $this->assertSame(1, $this->service()->sendDue());
        $this->assertTrue($later->fresh()->isSent());
    }

    // -- what a sent announcement is -----------------------------------------

    public function test_a_sent_announcement_can_no_longer_be_edited_or_deleted(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($admin->fresh());

        $announcement = $this->announcement();

        $this->assertTrue(AnnouncementResource::canEdit($announcement));

        $this->service()->send($announcement);

        // It is in inboxes. Changing the copy here would only make the record
        // disagree with what was sent.
        $this->assertFalse(AnnouncementResource::canEdit($announcement->fresh()));
        $this->assertFalse(AnnouncementResource::canDelete($announcement->fresh()));
    }

    public function test_the_body_reaches_the_customer_as_text_not_markup(): void
    {
        $user = $this->customer('reader@example.test');

        $this->service()->send($this->announcement([
            'body' => 'Read more at <a href="https://evil.test">our site</a>',
        ]));

        $this->actingAs($user);

        $response = $this->get(route('notifications'));

        $response->assertOk();
        // Shown as the characters it is. Rendered as markup, an administrator
        // could make a link say one thing and go somewhere else.
        $response->assertSee('&lt;a href=', false);
        $response->assertDontSee('<a href="https://evil.test"', false);
    }

    public function test_the_notification_centre_shows_only_this_customers_notices(): void
    {
        $mine = $this->customer('mine@example.test');
        $theirs = $this->customer('theirs@example.test');

        $this->service()->send($this->announcement(['title' => 'Everybody knows this']));

        $this->assertSame(1, $mine->fresh()->notifications()->count());
        $this->assertSame(1, $theirs->fresh()->notifications()->count());

        $this->actingAs($mine)->get(route('notifications'))->assertOk()->assertSee('Everybody knows this');

        // Marking read is scoped to whoever asked.
        $this->actingAs($mine)->post(route('notifications.read'))->assertRedirect();

        $this->assertSame(0, $mine->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $theirs->fresh()->unreadNotifications()->count());
    }

    public function test_an_announcement_is_not_a_second_banner(): void
    {
        // Aziv AI already has a banner system (Phase 2) that owns the strip at
        // the top of a page, its priority and its per-browser dismissal.
        // Announcements are messages. If this ever grows a display surface of
        // its own, there are two audiences and two dismissals to keep in step.
        $columns = array_keys(Announcement::first() ?: $this->announcement()->getAttributes());

        foreach (['priority', 'cta_label', 'cta_url', 'is_dismissible'] as $bannerish) {
            $this->assertNotContains($bannerish, $columns,
                'An announcement is growing into a banner. Use the banner.');
        }
    }
}
