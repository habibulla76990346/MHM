<?php

namespace App\Domains\Theming\Support;

/**
 * Every themeable value in Aziv AI (blueprint §5, owner decision D-07).
 *
 * This is the contract. A token here can be changed from the Admin Panel; a
 * value NOT here is hard-coded somewhere, which the hard-coded-colour test
 * treats as a defect.
 *
 * Owner constraint on D-07: "Keep the interface organized into categories so
 * the Admin Panel remains easy to use." ~95 tokens per mode as a flat list
 * would be technically complete and practically unusable, so every token
 * carries a group, a label, and a note saying what it actually affects.
 */
class TokenCatalogue
{
    public const SCOPE_CUSTOMER = 'customer';

    public const SCOPE_ADMIN = 'admin';

    public const MODE_LIGHT = 'light';

    public const MODE_DARK = 'dark';

    /**
     * Groups, in the order the editor shows them. The first three are the
     * "progressive disclosure" set: fill these in and the rest derive.
     *
     * @return array<string, array{label: string, description: string, primary: bool}>
     */
    public static function groups(): array
    {
        return [
            'brand' => [
                'label' => 'Brand colours',
                'description' => 'The few colours that define your identity. Everything else can derive from these.',
                'primary' => true,
            ],
            'surface' => [
                'label' => 'Backgrounds & surfaces',
                'description' => 'The page behind everything, and the panels that sit on it.',
                'primary' => true,
            ],
            'text' => [
                'label' => 'Text',
                'description' => 'Reading colours. Checked for contrast against the surfaces above.',
                'primary' => true,
            ],
            'line' => [
                'label' => 'Borders & dividers',
                'description' => 'The lines separating one thing from another.',
                'primary' => false,
            ],
            'state' => [
                'label' => 'Status colours',
                'description' => 'Success, warning, danger and information. Kept separate from your brand colour so meaning stays readable.',
                'primary' => false,
            ],
            'interactive' => [
                'label' => 'Links & interaction',
                'description' => 'Links, hover, focus and disabled states.',
                'primary' => false,
            ],
            'component' => [
                'label' => 'Components',
                'description' => 'Sidebar, navigation, buttons, inputs, cards, tables, badges, modals, chat bubbles and code blocks.',
                'primary' => false,
            ],
            'typography' => [
                'label' => 'Typography',
                'description' => 'Fonts, sizes and weights.',
                'primary' => false,
            ],
            'shape' => [
                'label' => 'Corners & shadows',
                'description' => 'Border radius and shadow depth.',
                'primary' => false,
            ],
            'spacing' => [
                'label' => 'Spacing',
                'description' => 'How much room the interface gives itself.',
                'primary' => false,
            ],
        ];
    }

    /**
     * The full token list.
     *
     * @return array<string, array{group: string, label: string, type: string, affects: string, choices?: array<string,string>}>
     */
    public static function tokens(): array
    {
        $c = fn (string $group, string $label, string $affects) => [
            'group' => $group, 'label' => $label, 'type' => 'color', 'affects' => $affects,
        ];
        $v = fn (string $group, string $label, string $affects, string $type = 'length') => [
            'group' => $group, 'label' => $label, 'type' => $type, 'affects' => $affects,
        ];

        return [
            // --- Brand -------------------------------------------------------
            'color.primary' => $c('brand', 'Primary', 'Main buttons, active navigation, focus rings, progress'),
            'color.primary-hover' => $c('brand', 'Primary (hover)', 'Primary buttons when hovered'),
            'color.primary-active' => $c('brand', 'Primary (pressed)', 'Primary buttons while being pressed'),
            'color.secondary' => $c('brand', 'Secondary', 'Secondary buttons and supporting accents'),
            'color.accent' => $c('brand', 'Accent', 'Highlights, badges, selected states'),

            // --- Surfaces ----------------------------------------------------
            'color.background' => $c('surface', 'Page background', 'The colour behind everything'),
            'color.surface' => $c('surface', 'Surface', 'Cards, panels, the top bar and sidebar'),
            'color.surface-raised' => $c('surface', 'Raised surface', 'Dropdowns, popovers, sheets'),
            'color.card' => $c('surface', 'Card', 'Card interiors'),
            'color.overlay' => $c('surface', 'Overlay', 'The dimming behind a modal or drawer'),

            // --- Text --------------------------------------------------------
            'color.text' => $c('text', 'Body text', 'Ordinary reading text'),
            'color.text-muted' => $c('text', 'Muted text', 'Labels, hints, timestamps'),
            'color.text-inverse' => $c('text', 'Inverse text', 'Text on a primary-coloured background'),
            'color.heading' => $c('text', 'Headings', 'Page and section titles'),

            // --- Lines -------------------------------------------------------
            'color.border' => $c('line', 'Border', 'Card, input and table borders'),
            'color.border-strong' => $c('line', 'Strong border', 'Emphasised edges'),
            'color.divider' => $c('line', 'Divider', 'Lines between list rows'),

            // --- State -------------------------------------------------------
            'color.success' => $c('state', 'Success', 'Confirmations'),
            'color.success-bg' => $c('state', 'Success background', 'Behind success messages'),
            'color.warning' => $c('state', 'Warning', 'Cautions and limited states'),
            'color.warning-bg' => $c('state', 'Warning background', 'Behind warnings'),
            'color.danger' => $c('state', 'Danger', 'Errors and destructive actions'),
            'color.danger-bg' => $c('state', 'Danger background', 'Behind errors'),
            'color.info' => $c('state', 'Information', 'Neutral notices'),
            'color.info-bg' => $c('state', 'Information background', 'Behind notices'),

            // --- Interactive -------------------------------------------------
            'color.link' => $c('interactive', 'Link', 'Text links'),
            'color.link-hover' => $c('interactive', 'Link (hover)', 'Links when hovered'),
            'color.hover' => $c('interactive', 'Hover', 'Row and menu-item hover'),
            'color.active' => $c('interactive', 'Active', 'The selected navigation item'),
            'color.focus-ring' => $c('interactive', 'Focus ring', 'The outline around a keyboard-focused control'),
            'color.disabled' => $c('interactive', 'Disabled', 'Controls that cannot be used'),

            // --- Components (blueprint §5 names these explicitly) -------------
            'color.sidebar-bg' => $c('component', 'Sidebar background', 'The navigation column'),
            'color.sidebar-text' => $c('component', 'Sidebar text', 'Navigation labels'),
            'color.sidebar-active' => $c('component', 'Sidebar active', 'The current page in navigation'),
            'color.navbar-bg' => $c('component', 'Top bar background', 'The bar across the top'),
            'color.navbar-text' => $c('component', 'Top bar text', 'Page title and top bar controls'),
            'color.button-secondary-bg' => $c('component', 'Secondary button', 'Secondary button background'),
            'color.button-secondary-text' => $c('component', 'Secondary button text', 'Secondary button label'),
            'color.input-bg' => $c('component', 'Input background', 'Text fields and selects'),
            'color.input-border' => $c('component', 'Input border', 'Field outlines'),
            'color.input-text' => $c('component', 'Input text', 'What the user types'),
            'color.input-placeholder' => $c('component', 'Placeholder', 'Hint text inside empty fields'),
            'color.table-header-bg' => $c('component', 'Table header', 'Column heading row'),
            'color.table-row-hover' => $c('component', 'Table row hover', 'A row under the cursor'),
            'color.table-stripe' => $c('component', 'Table stripe', 'Alternating row shading'),
            'color.badge-bg' => $c('component', 'Badge background', 'Small status pills'),
            'color.badge-text' => $c('component', 'Badge text', 'Text inside pills'),
            'color.modal-bg' => $c('component', 'Modal background', 'Dialog interiors'),
            'color.chat-bubble-user' => $c('component', 'Your message bubble', 'Messages the customer sent'),
            'color.chat-bubble-user-text' => $c('component', 'Your message text', 'Text in the customer\'s bubble'),
            'color.chat-bubble-assistant' => $c('component', 'AI message bubble', 'Messages Aziv AI sent'),
            'color.chat-bubble-assistant-text' => $c('component', 'AI message text', 'Text in the AI bubble'),
            'color.code-bg' => $c('component', 'Code block background', 'Code samples in AI answers'),
            'color.code-text' => $c('component', 'Code text', 'Code sample text'),
            'color.code-accent' => $c('component', 'Code highlight', 'Syntax emphasis'),

            // --- Typography --------------------------------------------------
            'font.sans' => $v('typography', 'Interface font', 'Everything except code', 'font'),
            'font.mono' => $v('typography', 'Code font', 'Code blocks and identifiers', 'font'),
            'font.heading-weight' => $v('typography', 'Heading weight', 'How bold titles are', 'number'),
            'font.body-weight' => $v('typography', 'Body weight', 'How bold ordinary text is', 'number'),
            'font.scale' => $v('typography', 'Type scale', 'Overall text size multiplier', 'number'),

            // --- Shape -------------------------------------------------------
            'radius.sm' => $v('shape', 'Small radius', 'Badges, small inputs'),
            'radius.md' => $v('shape', 'Medium radius', 'Buttons, inputs, cards'),
            'radius.lg' => $v('shape', 'Large radius', 'Panels and modals'),
            'shadow.sm' => $v('shape', 'Small shadow', 'Subtle lift on cards', 'shadow'),
            'shadow.md' => $v('shape', 'Medium shadow', 'Dropdowns', 'shadow'),
            'shadow.lg' => $v('shape', 'Large shadow', 'Modals and sheets', 'shadow'),

            // --- Spacing -----------------------------------------------------
            // These feed the FLUID geometry in app.css rather than replacing
            // it. A theme that emitted a fixed gutter would flatten the
            // continuous scaling Owner Addendum A requires, so a theme sets the
            // narrow-screen starting point and an overall density multiplier,
            // and the stylesheet grows them with the viewport from there.
            'space.scale' => $v('spacing', 'Density', 'Scales all spacing at once', 'choice') + [
                // Stored as the multiplier, shown as words. The editor renders
                // a three-way choice; the stylesheet only ever sees a number.
                'choices' => ['0.88' => 'Compact', '1' => 'Normal', '1.12' => 'Relaxed'],
            ],
            'space.gutter-min' => $v('spacing', 'Page gutter', 'Space at the page edges on a phone; grows on wider screens'),
            'space.section-min' => $v('spacing', 'Section spacing', 'Space between major blocks on a phone; grows on wider screens'),
        ];
    }

    /** @return array<int, string> */
    public static function colorTokens(): array
    {
        return array_keys(array_filter(self::tokens(), fn ($t) => $t['type'] === 'color'));
    }

    /** @return array<int, string> keys in a group */
    public static function tokensInGroup(string $group): array
    {
        return array_keys(array_filter(self::tokens(), fn ($t) => $t['group'] === $group));
    }

    /**
     * Text/background pairs the contrast checker validates.
     * Shipping an editor that lets an owner build an unreadable site would be
     * a defect, so these are checked as values are edited.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function contrastPairs(): array
    {
        return [
            ['color.text', 'color.background', 'Body text on the page'],
            ['color.text', 'color.surface', 'Body text on cards'],
            ['color.text-muted', 'color.surface', 'Muted text on cards'],
            ['color.heading', 'color.background', 'Headings on the page'],
            ['color.text-inverse', 'color.primary', 'Text on primary buttons'],
            ['color.link', 'color.background', 'Links on the page'],
            ['color.sidebar-text', 'color.sidebar-bg', 'Navigation labels'],
            ['color.navbar-text', 'color.navbar-bg', 'Top bar text'],
            ['color.input-text', 'color.input-bg', 'Text being typed'],
            ['color.chat-bubble-user-text', 'color.chat-bubble-user', 'Your chat messages'],
            ['color.chat-bubble-assistant-text', 'color.chat-bubble-assistant', 'AI chat messages'],
            ['color.code-text', 'color.code-bg', 'Code samples'],
        ];
    }

    public static function count(): int
    {
        return count(self::tokens());
    }
}
