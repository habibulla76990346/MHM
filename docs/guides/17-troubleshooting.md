# 17 — Troubleshooting

**Open Admin → System Health first.** Every check there names what is wrong, whether it is your
problem or your host's, what to do about it, and — where a hosting provider has to act — the exact
wording to send them. This guide is written around that screen.

Without a browser: `php artisan aziv:diagnose`, or `--json` for a machine-readable version, or
`--export=report.txt` for a support-safe file. **The export contains no credential of any kind**;
it is designed to be forwarded to a hosting provider unread.

## The site does not load at all

| What you see | Usually |
|---|---|
| Blank white page | PHP version too low, or a missing extension. Check the host's error log |
| **500** | `storage/` or `bootstrap/cache/` not writable — see [05](05-storage.md) |
| **500** right after an update | Migrations have not run. Admin → Maintenance → *Finish an update* |
| Laravel's welcome page | Document root is right but the app never installed. Go to `/install` |
| A directory listing, or your `.env` downloads | **Document root is wrong.** Fix it now — see [04](04-web-root.md) |
| **419** on every form | Sessions cannot be written, or the clock is wrong. Check `storage/framework/sessions/` |
| **404** on every page but the home page | `mod_rewrite`, or the `.htaccess` did not upload |

## Nothing happens on time

Renewals do not go out, reminders never arrive, payments stay pending, images queue for ever.

**The cron job is missing, or its PHP path is wrong.** System Health has a *Scheduler* check that
says when it last ran; more than thirty minutes is stale, three hours is dead. See
[06](06-queue-and-cron.md).

Test the exact line from a shell if you have one. The most common failure is `php` not being on the
path cron uses — write the full path, `/usr/local/bin/php`.

## Email

| Symptom | Cause |
|---|---|
| "Sent" everywhere, nothing arrives | `MAIL_MAILER=log`. It writes to a file. System Health calls this a failure |
| `aziv:mail:test` reports a connection failure | Wrong host, port or scheme. 587 wants `tls`, 465 wants `smtps` |
| Authentication failed | Many providers need an app-specific password, not your account password |
| Arrives in spam | SPF, DKIM and DMARC on your domain. Your mail provider documents its records |
| Nothing at all, no error | The caches. `php artisan config:clear` after editing `.env` |

## AI calls

| Symptom | Cause |
|---|---|
| Every provider fails, keys are correct | **Outbound HTTPS is blocked.** Common on shared hosting — send your host [17a](17a-hosting-requirements.md) |
| One provider fails, others work | The key, the account's billing, or a regional restriction. Use Test connection |
| Worked, now fails after a move | `APP_KEY` changed. Credentials are encrypted with it and cannot be recovered — re-enter them |
| "No model can do that" | Nothing enabled declares the capability. Vision, embeddings, images and speech each need a model that has them |
| Replies arrive all at once | The host buffers output. Aziv AI detects it and falls back; slower, not broken |
| Everything is refused after a while | A provider budget in **block** mode. Admin → AI Providers → Budgets |

## Payments

| Symptom | Cause |
|---|---|
| Pay button does nothing | Check the browser console. Usually the gateway's script is blocked, or the credentials are for the wrong mode |
| Paid, but nothing was granted | The webhook is not configured. The scheduled sweep will catch it within the hour — then fix the webhook |
| Webhook events rejected | The signing secret does not match the one in the gateway dashboard |
| Sandbox works, live does not | Live credentials **and** a live webhook URL are separate. Both must be set |

## Files, images and uploads

| Symptom | Cause |
|---|---|
| Uploads 404 | The storage link. Admin → Maintenance → *Rebuild the storage link* |
| Upload rejected | Type or size. The panel cannot widen the allowed types — that is deliberate |
| A PDF indexes to nothing | It is a scan, with no text layer. The library says so rather than failing silently |
| Indexing never completes | No embedding model is enabled, or the queue is not running |
| Images download instead of displaying | Correct, for anything the platform did not write itself |

## Signed links return 403

Password resets, email verification and renewal payment links all 403 behind Cloudflare or nginx
unless `TRUSTED_PROXIES` is set. A signature covers the address including `https://`, and without
that setting your server believes the request arrived over plain HTTP. See [13](13-cloud-vps.md).

## Slow

- `php artisan optimize` — uncached config and routes cost real time on every request.
- `opcache` on.
- Redis for cache and sessions if you have it.
- Check the queue is not backed up: System Health reports the backlog and the oldest waiting job.
- AI calls are slow because a provider is slow. Admin → Routing and Health shows the latency
  Aziv AI actually measured, from real traffic rather than synthetic pings.

## Locked out

[11 — Admin accounts and recovery](11-admin-accounts.md).

## When you have to ask for help

`php artisan aziv:diagnose --export=report.txt`, or the **Export** button on System Health.
Attach that file. It contains the versions, the settings, what failed and why, and no credential.
