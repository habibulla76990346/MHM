<?php

namespace App\Domains\Notifications\Support;

/**
 * Every notification Aziv AI can send, declared before it can be sent (§22).
 *
 * DENY BY DEFAULT, the same rule the settings registry follows and for the
 * same reason: an undeclared key is rejected rather than quietly delivered.
 * A typo would otherwise become an event that nothing renders, nobody can
 * find in the Admin Panel, and no owner can switch off.
 *
 * Each event declares:
 *
 *  - the CHANNELS it may use. An event that has no business being emailed
 *    cannot be, whatever a template row says.
 *  - its VARIABLES, as a closed list with a plain-English meaning. This is
 *    what the template editor shows, what a template is validated against,
 *    and what the renderer will substitute — nothing else reaches an email,
 *    so a template cannot be edited into leaking something it was never
 *    given.
 *  - the SHIPPED WORDING. A template row in the database is an override; with
 *    no rows at all the platform still sends correct, complete email. That is
 *    deliberate: a notification system that needs seeding before it works is a
 *    notification system that silently does nothing on a fresh install.
 */
final class NotificationEvent
{
    public const CHANNEL_MAIL = 'mail';

    public const CHANNEL_DATABASE = 'database';

    public const CHANNELS = [
        self::CHANNEL_MAIL => 'Email',
        self::CHANNEL_DATABASE => 'In the app',
    ];

    // --- billing lifecycle ---------------------------------------------------
    public const RENEWAL_DUE = 'renewal.due';

    public const RENEWAL_OVERDUE = 'renewal.overdue';

    public const SUBSCRIPTION_EXPIRED = 'subscription.expired';

    public const INVOICE_ISSUED = 'invoice.issued';

    public const PAYMENT_RECEIVED = 'payment.received';

    public const PAYMENT_FAILED = 'payment.failed';

    // --- broadcast -----------------------------------------------------------
    public const ANNOUNCEMENT = 'announcement.published';

    /**
     * The catalogue.
     *
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     group: string,
     *     channels: array<int, string>,
     *     variables: array<string, string>,
     *     subject: string,
     *     body: string,
     * }>
     */
    public static function all(): array
    {
        // Shared by every billing event, so a template author can rely on
        // them being present whichever one they are editing.
        $who = [
            'name' => 'The customer\'s name',
            'app_name' => 'Your platform\'s name',
        ];

        $money = [
            'plan' => 'The plan name',
            'amount' => 'The amount due, formatted with its currency',
            'currency' => 'The three-letter currency code',
        ];

        return [
            self::RENEWAL_DUE => [
                'label' => 'Renewal due',
                'description' => 'Sent before a manually renewed subscription reaches the end of its period, carrying the invoice and the link to pay it.',
                'group' => 'Subscriptions',
                'channels' => [self::CHANNEL_MAIL, self::CHANNEL_DATABASE],
                'variables' => $who + $money + [
                    'invoice_number' => 'The renewal invoice number',
                    'due_date' => 'The date the current period ends',
                    'pay_url' => 'The secure link that opens the payment page',
                ],
                'subject' => 'Your {{app_name}} subscription renews on {{due_date}}',
                'body' => <<<'TXT'
                    Hello {{name}},

                    Your {{plan}} subscription runs until {{due_date}}. Invoice {{invoice_number}} for {{amount}} is ready.

                    You can pay it here: {{pay_url}}

                    The link is yours alone and stops working once the invoice is paid.
                    TXT,
            ],

            self::RENEWAL_OVERDUE => [
                'label' => 'Renewal overdue',
                'description' => 'Sent when the period has ended and the renewal invoice is still unpaid. The subscription keeps working during the grace period.',
                'group' => 'Subscriptions',
                'channels' => [self::CHANNEL_MAIL, self::CHANNEL_DATABASE],
                'variables' => $who + $money + [
                    'invoice_number' => 'The renewal invoice number',
                    'grace_ends' => 'The date access stops if it is not paid',
                    'pay_url' => 'The secure link that opens the payment page',
                ],
                'subject' => 'Action needed: your {{plan}} subscription is unpaid',
                'body' => <<<'TXT'
                    Hello {{name}},

                    Invoice {{invoice_number}} for {{amount}} has not been paid yet. Your {{plan}} subscription keeps working until {{grace_ends}}.

                    You can pay it here: {{pay_url}}
                    TXT,
            ],

            self::SUBSCRIPTION_EXPIRED => [
                'label' => 'Subscription ended',
                'description' => 'Sent once the grace period has passed without payment.',
                'group' => 'Subscriptions',
                'channels' => [self::CHANNEL_MAIL, self::CHANNEL_DATABASE],
                'variables' => $who + ['plan' => 'The plan that ended', 'billing_url' => 'Where to start again'],
                'subject' => 'Your {{plan}} subscription has ended',
                'body' => <<<'TXT'
                    Hello {{name}},

                    Your {{plan}} subscription has ended and has not been renewed. Nothing has been deleted — you can start again whenever you like: {{billing_url}}
                    TXT,
            ],

            self::INVOICE_ISSUED => [
                'label' => 'Invoice issued',
                'description' => 'Sent whenever an invoice is issued, including the ones raised for a renewal.',
                'group' => 'Billing',
                'channels' => [self::CHANNEL_MAIL, self::CHANNEL_DATABASE],
                'variables' => $who + [
                    'invoice_number' => 'The invoice number',
                    'amount' => 'The invoice total, formatted with its currency',
                    'issued_on' => 'The invoice date',
                    'invoice_url' => 'Where to view it after signing in',
                ],
                'subject' => 'Invoice {{invoice_number}} from {{app_name}}',
                'body' => <<<'TXT'
                    Hello {{name}},

                    Invoice {{invoice_number}} for {{amount}} was issued on {{issued_on}}.

                    You can view it in your account: {{invoice_url}}
                    TXT,
            ],

            self::PAYMENT_RECEIVED => [
                'label' => 'Payment received',
                'description' => 'Sent when a payment settles. Confirms what was paid — never how it was paid.',
                'group' => 'Billing',
                'channels' => [self::CHANNEL_MAIL, self::CHANNEL_DATABASE],
                'variables' => $who + [
                    'amount' => 'The amount received, formatted with its currency',
                    'plan' => 'What it was for',
                    'invoice_number' => 'The invoice it settles',
                    'period_end' => 'When the paid period runs to',
                ],
                'subject' => 'Payment received — thank you',
                'body' => <<<'TXT'
                    Hello {{name}},

                    We have received {{amount}} for {{plan}}. Invoice {{invoice_number}} is settled and your subscription runs to {{period_end}}.
                    TXT,
            ],

            self::PAYMENT_FAILED => [
                'label' => 'Payment did not go through',
                'description' => 'Sent when an attempt fails. Says what to do next, never why the bank refused — we are not told, and guessing would be wrong.',
                'group' => 'Billing',
                'channels' => [self::CHANNEL_MAIL, self::CHANNEL_DATABASE],
                'variables' => $who + [
                    'amount' => 'The amount attempted, formatted with its currency',
                    'plan' => 'What it was for',
                    'pay_url' => 'The secure link to try again',
                ],
                'subject' => 'Your payment did not go through',
                'body' => <<<'TXT'
                    Hello {{name}},

                    The payment of {{amount}} for {{plan}} was not completed. Nothing has been charged.

                    You can try again here: {{pay_url}}
                    TXT,
            ],

            self::ANNOUNCEMENT => [
                'label' => 'Announcement',
                'description' => 'The wording used when an announcement is also emailed to its audience.',
                'group' => 'Announcements',
                'channels' => [self::CHANNEL_MAIL, self::CHANNEL_DATABASE],
                'variables' => $who + [
                    'title' => 'The announcement title',
                    'message' => 'The announcement body',
                    'url' => 'Where to read it in the app',
                ],
                'subject' => '{{title}}',
                'body' => <<<'TXT'
                    Hello {{name}},

                    {{message}}

                    {{url}}
                    TXT,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array{label: string, description: string, group: string, channels: array<int, string>, variables: array<string, string>, subject: string, body: string}|null */
    public static function definition(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function label(string $key): string
    {
        return self::definition($key)['label'] ?? $key;
    }

    /** @return array<string, string> variable name => what it means */
    public static function variables(string $key): array
    {
        return self::definition($key)['variables'] ?? [];
    }

    public static function supportsChannel(string $key, string $channel): bool
    {
        return in_array($channel, self::definition($key)['channels'] ?? [], true);
    }

    /** @return array<string, string> the picker an administrator sees */
    public static function options(): array
    {
        return array_map(fn (array $event) => $event['label'], self::all());
    }
}
