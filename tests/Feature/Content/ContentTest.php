<?php

namespace Tests\Feature\Content;

use App\Domains\Content\Models\Banner;
use App\Domains\Content\Models\Faq;
use App\Domains\Content\Models\NavigationItem;
use App\Domains\Content\Models\NavigationMenu;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\PageSection;
use App\Domains\Content\Services\ContentService;
use App\Domains\Content\Services\FeatureFlagService;
use App\Domains\Content\Services\NavigationService;
use App\Domains\Content\Support\SectionType;
use App\Domains\Content\Support\Seo;
use App\Domains\Security\Services\PermissionRegistry;
use App\Models\User;
use Database\Seeders\ContentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ThemesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ThemesSeeder::class);
        $this->seed(ContentSeeder::class);
    }

    private function content(): ContentService
    {
        return app(ContentService::class);
    }

    private function makePage(array $attributes = []): Page
    {
        return Page::create(array_merge([
            'slug' => 'example', 'title' => 'Example',
            'status' => Page::STATUS_PUBLISHED, 'published_at' => now()->subDay(),
        ], $attributes));
    }

    // -- seeded starting point ----------------------------------------------

    public function test_a_new_installation_has_a_working_homepage(): void
    {
        // Not demo data: an owner who changes nothing still gets a real site.
        $this->get('/')->assertOk()->assertSee('Every AI, one platform');
    }

    /**
     * Legal pages ship as DRAFTS. Placeholder legal text served as a live page
     * would be worse than none, because it reads as if someone wrote it.
     */
    public function test_legal_pages_ship_unpublished(): void
    {
        foreach (['terms', 'privacy', 'refund-policy'] as $slug) {
            $page = Page::where('slug', $slug)->first();

            $this->assertNotNull($page, "{$slug} was not seeded.");
            $this->assertFalse($page->isLive());
            $this->get('/p/'.$slug)->assertNotFound();
        }
    }

    public function test_reseeding_does_not_overwrite_edits(): void
    {
        $page = Page::where('slug', 'home')->first();
        $page->update(['title' => 'My Homepage']);

        $this->seed(ContentSeeder::class);

        $this->assertSame('My Homepage', $page->fresh()->title);
        $this->assertSame(1, Page::where('slug', 'home')->count());
    }

    // -- publishing and scheduling ------------------------------------------

    public function test_a_draft_page_is_not_found_rather_than_forbidden(): void
    {
        $this->makePage(['slug' => 'secret', 'status' => Page::STATUS_DRAFT, 'published_at' => null]);

        // 404, not 403: whether a page exists at all is not something an
        // anonymous visitor should be able to probe.
        $this->get('/p/secret')->assertNotFound();
    }

    public function test_a_scheduled_page_stays_hidden_until_its_time(): void
    {
        $page = $this->makePage(['slug' => 'launch', 'published_at' => now()->addDay()]);

        $this->assertTrue($page->isScheduled());
        $this->assertFalse($page->isLive());
        $this->get('/p/launch')->assertNotFound();

        $this->travelTo(now()->addDays(2));
        $this->content()->flush();

        $this->assertTrue($page->fresh()->isLive());
        $this->get('/p/launch')->assertOk();
    }

    /**
     * The cache can outlive a schedule expiring, so a page loaded from a
     * cached id is re-checked before it is served.
     */
    public function test_a_cached_page_is_rechecked_before_it_is_served(): void
    {
        $page = $this->makePage(['slug' => 'timely']);

        $this->assertNotNull($this->content()->page('timely'));

        // Unpublished WITHOUT flushing the cache — the id is still cached.
        $page->update(['status' => Page::STATUS_DRAFT]);

        $this->assertNull($this->content()->page('timely'),
            'A page served from cache stayed visible after it was unpublished.');
    }

    public function test_page_slugs_cannot_shadow_application_routes(): void
    {
        // Pages live under /p/, so a page called "login" cannot break signing in.
        $this->makePage(['slug' => 'login', 'title' => 'Login Page']);

        $this->get('/login')->assertOk()->assertSee('Sign in', false);
        $this->get('/p/login')->assertOk()->assertSee('Login Page');
    }

    // -- sections ------------------------------------------------------------

    public function test_every_section_type_renders(): void
    {
        $page = $this->makePage(['slug' => 'all-sections']);

        foreach (SectionType::keys() as $index => $type) {
            PageSection::create([
                'page_id' => $page->getKey(),
                'type' => $type,
                'sort_order' => $index,
                'is_visible' => true,
                'payload' => [
                    'heading' => 'Heading for '.$type,
                    'subheading' => 'Sub',
                    'body' => "First paragraph.\n\nSecond paragraph.",
                    'primary_label' => 'Go', 'primary_url' => '/register',
                    'items' => [['title' => 'One', 'body' => 'Body', 'icon' => 'chat']],
                ],
            ]);
        }

        $response = $this->get('/p/all-sections');
        $response->assertOk();

        foreach (SectionType::keys() as $type) {
            if ($type === SectionType::FAQ) {
                continue;   // renders questions, not its own heading text
            }

            $response->assertSee('Heading for '.$type);
        }
    }

    public function test_a_hidden_section_is_not_rendered(): void
    {
        $page = $this->makePage(['slug' => 'partly-hidden']);

        PageSection::create(['page_id' => $page->getKey(), 'type' => SectionType::RICHTEXT, 'is_visible' => true, 'payload' => ['heading' => 'Visible part']]);
        PageSection::create(['page_id' => $page->getKey(), 'type' => SectionType::RICHTEXT, 'is_visible' => false, 'payload' => ['heading' => 'Hidden part']]);

        $this->get('/p/partly-hidden')->assertOk()->assertSee('Visible part')->assertDontSee('Hidden part');
    }

    public function test_a_malformed_payload_does_not_break_the_page(): void
    {
        $page = $this->makePage(['slug' => 'broken']);

        // Rows of the wrong shape, and more than the declared maximum.
        PageSection::create([
            'page_id' => $page->getKey(),
            'type' => SectionType::FEATURES,
            'is_visible' => true,
            'payload' => ['heading' => 'Still fine', 'items' => ['not-an-array', ['title' => 'Real'], [], null]],
        ]);

        $this->get('/p/broken')->assertOk()->assertSee('Still fine')->assertSee('Real');
    }

    /** A link an administrator typed reaches every visitor, so it is checked. */
    public function test_a_dangerous_link_in_page_content_is_neutralised(): void
    {
        $page = $this->makePage(['slug' => 'risky']);

        PageSection::create([
            'page_id' => $page->getKey(),
            'type' => SectionType::CTA,
            'is_visible' => true,
            'payload' => ['heading' => 'Click', 'primary_label' => 'Go', 'primary_url' => 'javascript:alert(1)'],
        ]);

        $response = $this->get('/p/risky');

        $response->assertOk();
        $response->assertDontSee('javascript:alert', false);
    }

    // -- banners -------------------------------------------------------------

    public function test_a_banner_shows_only_inside_its_window(): void
    {
        $banner = Banner::create(['title' => 'Maintenance Sunday', 'audience' => 'everyone', 'starts_at' => now()->addDay()]);

        $this->assertFalse($banner->isLive());
        $this->get('/')->assertOk()->assertDontSee('Maintenance Sunday');

        $banner->update(['starts_at' => now()->subHour()]);
        $this->get('/')->assertOk()->assertSee('Maintenance Sunday');

        $banner->update(['ends_at' => now()->subMinute()]);
        $this->get('/')->assertOk()->assertDontSee('Maintenance Sunday');
    }

    public function test_the_highest_priority_banner_wins(): void
    {
        Banner::create(['title' => 'Lower priority', 'priority' => 1]);
        Banner::create(['title' => 'Higher priority', 'priority' => 90]);

        // One at a time: two stacked banners push content below the fold on a
        // phone, which Addendum A rules out.
        $this->get('/')->assertOk()->assertSee('Higher priority')->assertDontSee('Lower priority');
    }

    public function test_banners_respect_their_audience(): void
    {
        Banner::create(['title' => 'For guests only', 'audience' => 'guests']);
        Banner::create(['title' => 'For customers only', 'audience' => 'customers', 'priority' => 5]);

        $this->get('/')->assertSee('For guests only')->assertDontSee('For customers only');

        $customer = User::factory()->create();
        $customer->assignRole(PermissionRegistry::CUSTOMER);

        $this->actingAs($customer->fresh())->get('/dashboard')
            ->assertSee('For customers only')
            ->assertDontSee('For guests only');
    }

    public function test_dismissing_a_banner_hides_it_for_that_visitor(): void
    {
        $banner = Banner::create(['title' => 'Dismiss me']);

        $this->get('/')->assertSee('Dismiss me');

        $this->from('/')->post('/banners/'.$banner->uuid.'/dismiss')->assertRedirect('/');

        $this->get('/')->assertDontSee('Dismiss me');
    }

    // -- FAQ -----------------------------------------------------------------

    public function test_unpublished_questions_are_hidden_everywhere(): void
    {
        $faq = Faq::create(['question' => 'Secret question', 'answer' => 'Secret answer', 'is_published' => false]);

        $this->assertFalse($this->content()->faqs()->contains('id', $faq->id));
        $this->get('/')->assertDontSee('Secret question');
    }

    public function test_a_faq_section_pulls_published_questions(): void
    {
        // Written once on the FAQ screen, shown wherever a section asks for them.
        $this->get('/')->assertOk()->assertSee('What is Aziv AI?');
    }

    // -- navigation ----------------------------------------------------------

    public function test_navigation_comes_from_the_database(): void
    {
        $nav = app(NavigationService::class);

        $this->assertNotEmpty($nav->primary());
        $this->assertSame(['chat', 'library', 'images', 'account'], array_column($nav->primary(), 'key'));

        $item = NavigationItem::whereHas('menu', fn ($q) => $q->where('key', 'customer_primary'))
            ->where('key', 'chat')->first();

        $item->update(['label' => 'Conversations']);
        $nav->flush();

        $this->assertSame('Conversations', app(NavigationService::class)->primary()[0]['label']);
    }

    public function test_an_invisible_item_is_dropped(): void
    {
        NavigationItem::whereHas('menu', fn ($q) => $q->where('key', 'customer_primary'))
            ->where('key', 'images')->update(['is_visible' => false]);

        app(NavigationService::class)->flush();

        $this->assertNotContains('images', array_column(app(NavigationService::class)->primary(), 'key'));
    }

    /** A dead link is worse than a missing one. */
    public function test_an_item_pointing_at_a_missing_destination_is_dropped(): void
    {
        NavigationItem::whereHas('menu', fn ($q) => $q->where('key', 'customer_primary'))
            ->where('key', 'library')->update(['route_name' => 'route.that.does.not.exist']);

        app(NavigationService::class)->flush();

        $this->assertNotContains('library', array_column(app(NavigationService::class)->primary(), 'key'));
    }

    public function test_an_item_pointing_at_a_draft_page_is_dropped(): void
    {
        $draft = $this->makePage(['slug' => 'not-yet', 'status' => Page::STATUS_DRAFT, 'published_at' => null]);
        $menu = NavigationMenu::where('key', 'customer_drawer')->first();

        NavigationItem::create([
            'menu_id' => $menu->getKey(), 'key' => 'draft-link', 'label' => 'Draft link',
            'destination_type' => 'page', 'page_id' => $draft->getKey(), 'is_visible' => true,
        ]);

        app(NavigationService::class)->flush();

        $this->assertNotContains('draft-link', array_column(app(NavigationService::class)->secondary(), 'key'));
    }

    public function test_an_unsafe_external_url_is_dropped(): void
    {
        $menu = NavigationMenu::where('key', 'customer_drawer')->first();

        NavigationItem::create([
            'menu_id' => $menu->getKey(), 'key' => 'nasty', 'label' => 'Nasty',
            'destination_type' => 'external', 'url' => 'javascript:alert(1)', 'is_visible' => true,
        ]);

        app(NavigationService::class)->flush();

        $this->assertNotContains('nasty', array_column(app(NavigationService::class)->secondary(), 'key'));
    }

    /**
     * The 4-item cap is a CONSEQUENCE of the 44px touch minimum at 320px, so
     * it is enforced rather than documented.
     */
    public function test_the_bottom_bar_never_exceeds_four_items(): void
    {
        $menu = NavigationMenu::where('key', 'customer_bottom_nav')->first();

        $this->assertSame(4, $menu->max_items);
        $this->assertTrue($menu->isFull());

        // Even if rows are forced past the cap, the renderer still slices.
        NavigationItem::create([
            'menu_id' => $menu->getKey(), 'key' => 'extra', 'label' => 'Extra',
            'destination_type' => 'route', 'route_name' => 'account', 'sort_order' => 99, 'is_visible' => true,
        ]);

        app(NavigationService::class)->flush();

        $this->assertCount(NavigationService::BOTTOM_BAR_LIMIT, app(NavigationService::class)->bottomBar());
    }

    /** Navigation is how a customer moves around; it cannot simply vanish. */
    public function test_navigation_falls_back_when_the_tables_are_empty(): void
    {
        NavigationItem::query()->delete();
        NavigationMenu::query()->delete();
        app(NavigationService::class)->flush();

        $this->assertNotEmpty(app(NavigationService::class)->primary());
        $this->assertNotEmpty(app(NavigationService::class)->bottomBar());
    }

    public function test_a_permission_on_an_item_hides_it_without_granting_entry(): void
    {
        $nav = app(NavigationService::class);

        // The drawer's admin link is gated on settings.view.
        $this->assertEmpty($nav->secondary());

        $admin = User::factory()->create();
        $admin->assignRole(PermissionRegistry::SUPER_ADMIN);
        $this->actingAs($admin->fresh());

        $nav->flush();
        $this->assertNotEmpty(app(NavigationService::class)->secondary());
    }

    // -- feature flags -------------------------------------------------------

    public function test_an_undeclared_flag_reads_as_off(): void
    {
        // Failing closed means a typo hides a feature rather than exposing an
        // unfinished one.
        $this->assertFalse(app(FeatureFlagService::class)->enabled('never.declared'));
    }

    public function test_a_flag_hides_a_navigation_item_while_it_is_off(): void
    {
        $flags = app(FeatureFlagService::class);
        $flags->set('images.enabled', false);

        NavigationItem::whereHas('menu', fn ($q) => $q->where('key', 'customer_primary'))
            ->where('key', 'images')->update(['feature_flag_key' => 'images.enabled']);

        app(NavigationService::class)->flush();
        $this->assertNotContains('images', array_column(app(NavigationService::class)->primary(), 'key'));

        $flags->set('images.enabled', true);
        app(NavigationService::class)->flush();

        $this->assertContains('images', array_column(app(NavigationService::class)->primary(), 'key'));
    }

    public function test_flags_are_cached_as_plain_data(): void
    {
        app(FeatureFlagService::class)->set('a.flag', true);
        app(FeatureFlagService::class)->all();

        $cached = Cache::get('aziv:feature_flags');

        $this->assertIsArray($cached);
        $this->assertEquals($cached, unserialize(serialize($cached)));
    }

    // -- SEO -----------------------------------------------------------------

    public function test_seo_falls_back_through_page_content_then_branding(): void
    {
        $page = $this->makePage(['slug' => 'seo-test', 'title' => 'About Us']);

        PageSection::create([
            'page_id' => $page->getKey(), 'type' => SectionType::HERO, 'is_visible' => true,
            'payload' => ['heading' => 'Hello', 'subheading' => 'We build AI tools for everyone.'],
        ]);

        $seo = Seo::forPage($page->fresh()->load('sections'));

        $this->assertStringContainsString('About Us', $seo['title']);
        $this->assertStringContainsString(settings('branding.app_name'), $seo['title']);
        // Nobody wrote a description, so the first readable words are used.
        $this->assertSame('We build AI tools for everyone.', $seo['description']);
        $this->assertSame(url('/p/seo-test'), $seo['canonical']);
        $this->assertSame('index, follow', $seo['robots']);
    }

    public function test_an_explicit_seo_override_wins(): void
    {
        $page = $this->makePage([
            'slug' => 'override',
            'seo' => ['title' => 'Chosen title', 'description' => 'Chosen description'],
        ]);

        $seo = Seo::forPage($page);

        $this->assertStringStartsWith('Chosen title', $seo['title']);
        $this->assertSame('Chosen description', $seo['description']);
    }

    /** A preview link must never put a draft into a search index. */
    public function test_a_page_that_is_not_live_is_marked_noindex(): void
    {
        $draft = $this->makePage(['slug' => 'draft-seo', 'status' => Page::STATUS_DRAFT, 'published_at' => null]);

        $this->assertSame('noindex, nofollow', Seo::forPage($draft)['robots']);
    }

    public function test_a_long_description_is_clamped(): void
    {
        $page = $this->makePage(['slug' => 'long', 'seo' => ['description' => str_repeat('word ', 200)]]);

        $this->assertLessThanOrEqual(Seo::DESCRIPTION_MAX, mb_strlen(Seo::forPage($page)['description']));
    }

    public function test_the_social_preview_tags_reach_the_page(): void
    {
        $this->makePage(['slug' => 'shared', 'title' => 'Shared Page']);

        $this->get('/p/shared')
            ->assertOk()
            ->assertSee('og:title', false)
            ->assertSee('og:image', false)
            ->assertSee('twitter:card', false)
            ->assertSee('rel="canonical"', false);
    }
}
