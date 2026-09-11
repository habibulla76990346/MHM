<?php

namespace Database\Seeders;

use App\Domains\Content\Models\Faq;
use App\Domains\Content\Models\NavigationItem;
use App\Domains\Content\Models\NavigationMenu;
use App\Domains\Content\Models\Page;
use App\Domains\Content\Models\PageSection;
use App\Domains\Content\Support\SectionType;
use Illuminate\Database\Seeder;

/**
 * The content a new installation starts with.
 *
 * Not demo data: a real homepage, the legal pages every SaaS needs, and the
 * navigation from decision D-11. An owner who installs Aziv AI and changes
 * nothing still gets a working, coherent site — they edit rather than build
 * from an empty screen.
 *
 * Safe to re-run: existing rows are left exactly as they are, so an upgrade
 * adds what is new without reverting anyone's edits.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedNavigation();
        $this->seedHomepage();
        $this->seedLegalPages();
        $this->seedFaqs();
    }

    private function seedNavigation(): void
    {
        $menus = [
            ['key' => 'customer_primary', 'name' => 'Main navigation', 'location' => 'customer_primary', 'max_items' => null],
            // 4 + More. At 320px a fifth tab drops every target under 44px,
            // which the responsive gate treats as a failure.
            ['key' => 'customer_bottom_nav', 'name' => 'Phone bottom bar', 'location' => 'customer_bottom_nav', 'max_items' => 4],
            ['key' => 'customer_drawer', 'name' => 'More menu', 'location' => 'customer_drawer', 'max_items' => null],
            ['key' => 'footer', 'name' => 'Footer links', 'location' => 'footer', 'max_items' => null],
        ];

        foreach ($menus as $definition) {
            NavigationMenu::firstOrCreate(
                ['key' => $definition['key']],
                $definition + ['is_system' => true],
            );
        }

        $primary = NavigationMenu::where('key', 'customer_primary')->first();
        $bottom = NavigationMenu::where('key', 'customer_bottom_nav')->first();
        $drawer = NavigationMenu::where('key', 'customer_drawer')->first();

        // D-11 as approved: Chat · Library · Images · Account.
        $core = [
            ['key' => 'chat', 'label' => 'Chat', 'icon' => 'chat', 'route_name' => 'dashboard'],
            ['key' => 'library', 'label' => 'Library', 'icon' => 'library', 'route_name' => 'library'],
            ['key' => 'images', 'label' => 'Images', 'icon' => 'image', 'route_name' => 'images'],
            ['key' => 'account', 'label' => 'Account', 'icon' => 'user', 'route_name' => 'account'],
        ];

        foreach ([$primary, $bottom] as $menu) {
            foreach ($core as $index => $item) {
                NavigationItem::firstOrCreate(
                    ['menu_id' => $menu->getKey(), 'key' => $item['key']],
                    $item + ['destination_type' => 'route', 'sort_order' => $index * 10, 'is_visible' => true],
                );
            }
        }

        NavigationItem::firstOrCreate(
            ['menu_id' => $drawer->getKey(), 'key' => 'admin'],
            [
                'label' => 'Admin panel', 'icon' => 'shield', 'destination_type' => 'external',
                'url' => '/admin', 'sort_order' => 0, 'is_visible' => true,
                // Hides the link for everyone else. The panel's own policy is
                // what actually keeps them out.
                'permission' => 'settings.view',
            ],
        );
    }

    private function seedHomepage(): void
    {
        if (Page::where('slug', 'home')->exists()) {
            return;
        }

        $page = Page::create([
            'slug' => 'home',
            'title' => 'Home',
            'status' => Page::STATUS_PUBLISHED,
            'is_system' => true,
            'published_at' => now(),
            'seo' => [
                'title' => null,          // falls back to the product name
                'description' => 'One platform for every AI model. Chat, images and more.',
            ],
        ]);

        $sections = [
            [SectionType::HERO, [
                'heading' => 'Every AI, one platform',
                'subheading' => 'Chat with the best models, generate images, and keep your work in one place — without a subscription to each provider.',
                'primary_label' => 'Get started',
                'primary_url' => '/register',
                'secondary_label' => 'Sign in',
                'secondary_url' => '/login',
            ]],
            [SectionType::FEATURES, [
                'heading' => 'What you get',
                'items' => [
                    ['title' => 'Many models, one place', 'body' => 'Switch between providers without switching apps or paying for each one separately.', 'icon' => 'chat'],
                    ['title' => 'Your work, kept', 'body' => 'Conversations and images stay in your library, searchable and organised.', 'icon' => 'library'],
                    ['title' => 'Images on demand', 'body' => 'Generate and refine images alongside your conversations.', 'icon' => 'image'],
                    ['title' => 'Pay for what you use', 'body' => 'Credits rather than a fixed subscription, so occasional use costs occasional money.', 'icon' => 'user'],
                ],
            ]],
            [SectionType::STEPS, [
                'heading' => 'Getting started',
                'items' => [
                    ['title' => 'Create an account', 'body' => 'An email address is all it takes.'],
                    ['title' => 'Choose a model', 'body' => 'Or let Aziv AI pick the right one for the job.'],
                    ['title' => 'Start working', 'body' => 'Your conversations and images are saved as you go.'],
                ],
            ]],
            [SectionType::FAQ, ['heading' => 'Common questions', 'category' => 'general']],
            [SectionType::CTA, [
                'heading' => 'Ready to start?',
                'body' => 'Create an account in under a minute.',
                'primary_label' => 'Create your account',
                'primary_url' => '/register',
            ]],
        ];

        foreach ($sections as $index => [$type, $payload]) {
            PageSection::create([
                'page_id' => $page->getKey(),
                'type' => $type,
                'sort_order' => $index * 10,
                'is_visible' => true,
                'payload' => $payload,
            ]);
        }
    }

    private function seedLegalPages(): void
    {
        $pages = [
            ['slug' => 'terms', 'title' => 'Terms of Service', 'sort' => 10],
            ['slug' => 'privacy', 'title' => 'Privacy Policy', 'sort' => 20],
            ['slug' => 'refund-policy', 'title' => 'Refund Policy', 'sort' => 30],
            ['slug' => 'contact', 'title' => 'Contact', 'sort' => 40],
        ];

        foreach ($pages as $definition) {
            if (Page::where('slug', $definition['slug'])->exists()) {
                continue;
            }

            // DRAFT, not published. These need real wording from the owner and
            // their jurisdiction; shipping placeholder legal text as a live
            // page would be worse than shipping none.
            $page = Page::create([
                'slug' => $definition['slug'],
                'title' => $definition['title'],
                'status' => Page::STATUS_DRAFT,
                'is_system' => true,
                'show_in_footer' => true,
                'sort_order' => $definition['sort'],
            ]);

            PageSection::create([
                'page_id' => $page->getKey(),
                'type' => SectionType::RICHTEXT,
                'sort_order' => 0,
                'is_visible' => true,
                'payload' => [
                    'heading' => $definition['title'],
                    'body' => "This page has not been written yet.\n\n"
                        .'Replace this text in Admin → Content, then publish the page. '
                        .'It is linked from the footer once published.',
                ],
            ]);
        }
    }

    private function seedFaqs(): void
    {
        $faqs = [
            ['question' => 'What is Aziv AI?', 'answer' => 'A single place to use many AI models — chat, images and more — without a separate account and subscription for each provider.'],
            ['question' => 'How does billing work?', 'answer' => 'You buy credits and spend them as you use the platform. There is no fixed monthly fee, so occasional use costs occasional money.'],
            ['question' => 'Which models can I use?', 'answer' => 'The list is managed by the site administrator and changes as providers release new models.'],
            ['question' => 'Is my data used to train models?', 'answer' => 'No. Your conversations are stored so that you can return to them, and are not used for training.'],
        ];

        foreach ($faqs as $index => $faq) {
            Faq::firstOrCreate(
                ['question' => $faq['question']],
                $faq + ['category' => 'general', 'sort_order' => $index * 10, 'is_published' => true],
            );
        }
    }
}
