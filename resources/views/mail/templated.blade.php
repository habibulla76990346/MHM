{{--
    The shell every Aziv AI email is sent in.

    EVERY COLOUR COMES FROM $palette, resolved from the owner's theme at send
    time (ThemeService::emailPalette). There is no colour written here, which
    is both Rule 1 and the reason a rebranded platform sends rebranded email.

    Tables and inline styles are not old-fashioned by accident: Outlook still
    ignores float, flex and grid, and Gmail strips <style>. This is the layout
    that survives.

    The body is ESCAPED and printed as text. A template that could inject
    markup would be a template that could hide a link's real destination.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:{{ $palette['background'] }};margin:0;padding:24px 0;">
    <tr>
        <td align="center" style="padding:0 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px;background:{{ $palette['surface'] }};border:1px solid {{ $palette['border'] }};border-radius:12px;">
                <tr>
                    <td style="padding:24px 24px 8px 24px;font:600 18px/1.4 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $palette['text'] }};">
                        {{ $heading }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 24px 24px;font:400 16px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $palette['text'] }};white-space:pre-line;">{{ $bodyText }}</td>
                </tr>
                <tr>
                    <td style="padding:16px 24px;border-top:1px solid {{ $palette['border'] }};font:400 13px/1.5 -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:{{ $palette['muted'] }};">
                        {{ __('This message was sent by :app.', ['app' => $heading]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
