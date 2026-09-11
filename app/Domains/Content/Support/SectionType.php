<?php

namespace App\Domains\Content\Support;

/**
 * The building blocks a page is assembled from (blueprint §7).
 *
 * A closed set, not free HTML. Two reasons, and the second is the important
 * one:
 *
 *  1. Every section renders from design tokens, so page content cannot escape
 *    the theme system. A rich-text field that accepted arbitrary HTML would be
 *    the one place in Aziv AI where an owner could hard-code a colour.
 *  2. Every section is responsive by construction. Owner Addendum A applies to
 *    "EVERY user-facing page"; a page built from free HTML would pass the
 *    six-viewport gate only by luck, and the owner who broke it would have no
 *    way to know.
 *
 * Each type declares its fields, so the editor renders itself and the
 * renderer knows what it is given.
 */
class SectionType
{
    public const HERO = 'hero';

    public const RICHTEXT = 'richtext';

    public const FEATURES = 'features';

    public const STEPS = 'steps';

    public const FAQ = 'faq';

    public const CTA = 'cta';

    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     fields: array<string, array{label: string, type: string, help?: string}>,
     *     repeats?: array{key: string, label: string, max: int, fields: array<string, array{label: string, type: string}>}
     * }>
     */
    public static function all(): array
    {
        return [
            self::HERO => [
                'label' => 'Hero',
                'description' => 'The large introduction at the top of a page.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'type' => 'text'],
                    'subheading' => ['label' => 'Sub-heading', 'type' => 'textarea'],
                    'primary_label' => ['label' => 'Main button text', 'type' => 'text'],
                    'primary_url' => ['label' => 'Main button link', 'type' => 'text', 'help' => 'A path such as /register, or a full https:// address.'],
                    'secondary_label' => ['label' => 'Second button text', 'type' => 'text'],
                    'secondary_url' => ['label' => 'Second button link', 'type' => 'text'],
                ],
            ],

            self::RICHTEXT => [
                'label' => 'Text',
                'description' => 'A heading and paragraphs. Blank lines start a new paragraph.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'type' => 'text'],
                    'body' => ['label' => 'Text', 'type' => 'textarea', 'help' => 'Plain text. Leave a blank line between paragraphs.'],
                ],
            ],

            self::FEATURES => [
                'label' => 'Features',
                'description' => 'A grid of short points. Stacks to one column on a phone.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'type' => 'text'],
                    'subheading' => ['label' => 'Sub-heading', 'type' => 'textarea'],
                ],
                'repeats' => [
                    'key' => 'items',
                    'label' => 'Features',
                    'max' => 9,
                    'fields' => [
                        'title' => ['label' => 'Title', 'type' => 'text'],
                        'body' => ['label' => 'Description', 'type' => 'textarea'],
                        'icon' => ['label' => 'Icon', 'type' => 'icon'],
                    ],
                ],
            ],

            self::STEPS => [
                'label' => 'Steps',
                'description' => 'A numbered sequence.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'type' => 'text'],
                ],
                'repeats' => [
                    'key' => 'items',
                    'label' => 'Steps',
                    'max' => 8,
                    'fields' => [
                        'title' => ['label' => 'Title', 'type' => 'text'],
                        'body' => ['label' => 'Description', 'type' => 'textarea'],
                    ],
                ],
            ],

            self::FAQ => [
                'label' => 'Questions',
                'description' => 'Pulls published questions from the FAQ screen, so they are written once.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'type' => 'text'],
                    'category' => ['label' => 'Category', 'type' => 'text', 'help' => 'Leave empty to show every published question.'],
                ],
            ],

            self::CTA => [
                'label' => 'Call to action',
                'description' => 'A closing panel with one button.',
                'fields' => [
                    'heading' => ['label' => 'Heading', 'type' => 'text'],
                    'body' => ['label' => 'Text', 'type' => 'textarea'],
                    'primary_label' => ['label' => 'Button text', 'type' => 'text'],
                    'primary_url' => ['label' => 'Button link', 'type' => 'text'],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? $type;
    }

    /** @return array<string, array<string, string>> */
    public static function fields(string $type): array
    {
        return self::all()[$type]['fields'] ?? [];
    }

    /** @return array{key: string, label: string, max: int, fields: array}|null */
    public static function repeats(string $type): ?array
    {
        return self::all()[$type]['repeats'] ?? null;
    }
}
