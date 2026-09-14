# Aziv AI — the guides

Twenty guides, written for the person who owns this platform rather than for a developer.
Where a step genuinely needs developer knowledge, it says so instead of assuming.

**Start here:** [01 — Server requirements](01-server-requirements.md), then
[02 — Installation](02-installation.md).

**When something is wrong:** open Admin → System Health first. Every finding on that screen
names what is wrong, what it means and what to do, and
[17 — Troubleshooting](17-troubleshooting.md) is written around it.

| # | Guide |
|---|---|
| 01 | [Server requirements](01-server-requirements.md) |
| 02 | [Installation](02-installation.md) |
| 03 | [Database setup](03-database-setup.md) |
| 04 | [Web root and public directory](04-web-root.md) |
| 05 | [Storage setup](05-storage.md) |
| 06 | [Queue and cron](06-queue-and-cron.md) |
| 07 | [Mail and SMTP](07-mail-and-smtp.md) |
| 08 | [AI provider configuration](08-ai-providers.md) |
| 09 | [Payment gateway configuration](09-payment-gateways.md) |
| 10 | [Tax configuration](10-tax.md) |
| 11 | [Admin accounts and recovery](11-admin-accounts.md) |
| 12 | [Shared hosting / cPanel deployment](12-shared-hosting.md) |
| 13 | [Cloud / VPS deployment](13-cloud-vps.md) |
| 14 | [Production deployment](14-production-deployment.md) |
| 15 | [Migration: shared hosting → cloud](15-migration.md) |
| 16 | [Backup and restore](../19-backup-and-restore.md) |
| 17 | [Troubleshooting](17-troubleshooting.md) |
| 17a–e | [Hosting requirements to send your provider](17a-hosting-requirements.md) |
| 18 | [Security checklist](18-security-checklist.md) |
| 19 | [Upgrading](19-upgrading.md) |
| 20 | [Every command, and when to run it](20-commands.md) |

## The five things that matter most

1. **Back up `APP_KEY`.** Every provider key and payment credential is encrypted with it. A
   database restored beside a different key can never be read again, by anybody.
2. **Add the cron job.** Nothing time-based happens without it — renewals, reminders, payment
   reconciliation, and on shared hosting the queue itself.
3. **Configure email and prove it.** `php artisan aziv:mail:test you@yourdomain.com`. The default
   writes to a log file and delivers nothing while reporting success.
4. **Point the document root at `public/`.** Getting this wrong serves your `.env` — every
   credential you own — to anybody who asks for it.
5. **Open Admin → System Health** after any change, and work through anything red.
