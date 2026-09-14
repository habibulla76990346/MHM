# 07 — Mail and SMTP

**Laravel's default mailer writes to a log file and delivers nothing** — while reporting every
message as sent. A renewal notice in a log file is a subscription that lapses in silence, and
nobody finds out until a customer asks why they lost access.

Aziv AI therefore ships `.env.example` with a real transport and **blank credentials**, so a
fresh install fails loudly instead of succeeding quietly.

## Configuring it

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="Your Platform"
```

`tls` on port 587, `smtps` on port 465. Ask your provider which they want.

Then rebuild the cache: `php artisan config:clear`, or **Admin → Maintenance → Rebuild the
caches**.

## Proving it

```sh
php artisan aziv:mail:test you@yourdomain.com
```

This sends the **same email a customer receives**, through the same mailer, rendered through your
theme. It never prints a credential, and it treats `log`, `array` and `null` as a **failure**
rather than a success.

Check the inbox, and check spam.

## Deliverability

Sending from an address whose domain you do not control gets filed as spam. Three DNS records
fix most of it:

| Record | Why |
|---|---|
| **SPF** | Says which servers may send as your domain |
| **DKIM** | Signs your mail so it cannot be forged |
| **DMARC** | Tells receivers what to do when the first two fail |

Your mail provider publishes the exact values. Use a from-address at a domain you control —
never a Gmail or Yahoo address.

## What Aziv AI sends

| Message | When |
|---|---|
| Renewal due | Before a manually renewed subscription's period ends, with the invoice and a payment link |
| Renewal overdue | The period ended and it is unpaid. Access continues during the grace period |
| Subscription expired | Grace period is over |
| Invoice issued · Payment received · Payment failed | As they happen |
| Announcement | When you send one |
| System health changed | To administrators, when a check starts failing or starts working again |

Every one of them: **Admin → Notifications → Templates** changes the wording,
**Delivery log** says whether it arrived, and each can be switched off.

## What goes wrong

| Symptom | Cause |
|---|---|
| Everything "sends", nothing arrives | `MAIL_MAILER=log`. Health check reports it |
| "Connection refused" | Wrong port, or your host blocks outbound SMTP. Many block 25; some block all of it |
| "Authentication failed" | Wrong credentials — or your provider needs an app-specific password |
| Arrives in spam | SPF and DKIM are not set up, or you are sending from a domain you do not control |
| Nothing sends and no error | The queue is not running. Email is queued — see [06](06-queue-and-cron.md) |
