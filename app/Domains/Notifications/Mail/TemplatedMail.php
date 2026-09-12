<?php

namespace App\Domains\Notifications\Mail;

use App\Domains\Theming\Services\ThemeService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one email shape Aziv AI sends.
 *
 * IT FOLLOWS THE OWNER'S THEME. Email clients ignore stylesheets and custom
 * properties, so the palette is resolved to plain values HERE and inlined —
 * which means a white-label owner's email looks like their platform rather
 * than like ours, and no blade template contains a colour (Rule 1).
 *
 * The body is plain text placed into that shell and ESCAPED. A template
 * carrying markup would be markup an administrator could use to embed a
 * tracking pixel or a link that says one thing and goes somewhere else.
 */
class TemplatedMail extends Mailable
{
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.templated',
            with: [
                'heading' => (string) settings('branding.app_name'),
                'bodyText' => $this->bodyText,
                'palette' => app(ThemeService::class)->emailPalette(),
            ],
        );
    }
}
