# 15 — Moving from shared hosting to a cloud server

Aziv AI has no dependency on the machine it runs on. Moving is a data copy and an `.env` change —
never a code change. That is a rule with a test behind it, not an aspiration.

## Copy `APP_KEY`. Everything else is recoverable.

Every AI provider key and every payment gateway credential in your database is **encrypted with
`APP_KEY`**. Restore the database beside a different key and those values are gone permanently —
not scrambled, gone. Nobody can recover them, including us.

So: before anything else, open the old server's `.env`, copy the `APP_KEY=` line, and put it
somewhere safe that is not either server.

## The move

**1. Freeze the old site.** `php artisan down`, or put up a maintenance page. Five minutes of "back
shortly" is better than payments landing on a server you are about to abandon.

**2. Export the database.**

```sh
mysqldump -u user -p --single-transaction --routines --triggers dbname > aziv.sql
```

Or cPanel → phpMyAdmin → Export → Quick, SQL.

**3. Copy the files that are not in the release package.** Specifically `storage/app/` — that is
every file your customers uploaded, every generated image and every voice recording. `rsync` if
you have it, or a ZIP through File Manager if you do not.

**4. Set the new server up** per [13 — Cloud and VPS](13-cloud-vps.md), but **do not run the
installer** and do not run the seeders. You are restoring, not installing.

**5. Restore.**

```sh
mysql -u user -p newdb < aziv.sql
```

**6. Write `.env` on the new server** — the same `APP_KEY`, the new database credentials, the same
`APP_URL` if the domain is not changing.

**7. Finish.**

```sh
php artisan migrate --force     # in case the new package is a later version
php artisan storage:link
php artisan optimize
php artisan aziv:diagnose
```

**8. Point DNS at the new server**, and leave the old one running until the change has propagated
everywhere. Both can serve; only one should be taking payments, so keep the old one in maintenance
mode.

## Then check, in this order

- [ ] Sign in as an administrator.
- [ ] **Admin → AI Providers → Test connection.** If this fails, `APP_KEY` did not come across —
      stop and fix that before doing anything else.
- [ ] Send a chat message and get a reply.
- [ ] Open a customer's uploaded file, and a generated image.
- [ ] `php artisan aziv:mail:test you@yourdomain.com`.
- [ ] **Update the webhook URL** in every payment gateway's dashboard if the domain changed.
- [ ] Confirm the cron job exists on the new server. It does not travel with the files.
- [ ] Admin → System Health, entirely.

## If the domain is changing too

Also: `APP_URL`, the gateway webhook URLs, the OAuth redirect for anything you have connected, your
DNS mail records, and the addresses in any announcement already scheduled.

## What you can throw away

Nothing, for a fortnight. Keep the old server, its database dump and its `storage/app/` until the
new one has run a full billing cycle including a renewal.
