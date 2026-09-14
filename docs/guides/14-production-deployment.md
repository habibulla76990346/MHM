# 14 — Production deployment

The order below matters. Caching configuration before writing `.env` caches the wrong thing, and
the symptom is a setting that will not change no matter what you edit.

## First deployment

```sh
# 1. Extract the release package into place
# 2. Write .env  (start from .env.example — every variable is documented in it)
php artisan key:generate          # only if .env has no APP_KEY
php artisan migrate --force
php artisan db:seed --force       # roles, permissions, settings, themes, countries, currencies
php artisan storage:link
php artisan optimize              # config, route, view and event caches
php artisan aziv:admin:create
php artisan aziv:diagnose
```

`--force` is what tells Laravel you mean it in production. Without it, both commands stop and ask.

Then work through anything `aziv:diagnose` reports. It grades by environment: things that are a
warning locally are failures in production.

## Every deployment after that

```sh
php artisan down --render="errors::503"   # optional; see below
git pull            # or extract the new package over the top, keeping .env and storage/
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
php artisan up
```

**`queue:restart` is the one people forget.** A running worker holds the old code in memory and
will keep running it until it is told otherwise — so the new version is live on the web and the
old version is still processing jobs.

**Without SSH,** all of this is on **Admin → Maintenance**: *Finish an update* runs the migrations,
*Rebuild the caches* is `optimize:clear` and `optimize`, and *Rebuild the storage link* is
`storage:link`. Each is permission-gated and each is recorded in the audit log with its output.

## Near-zero downtime

Deploy into a new directory and move a symlink:

```
/var/www/aziv/releases/2026-09-13-1400/
/var/www/aziv/shared/.env
/var/www/aziv/shared/storage/
/var/www/aziv/current -> releases/2026-09-13-1400
```

`.env` and `storage/` are symlinked from `shared/` into each release, migrations run against the
new code before the switch, and the switch is one atomic `ln -sfn`. Reload PHP-FPM afterwards so
opcache picks up the new paths.

Rolling back is then moving the symlink back — **unless a migration changed the schema**, which is
the case rollback cannot cover. See [19 — Upgrading](19-upgrading.md).

## The production checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] `APP_URL` is the real address, with `https://`
- [ ] `TRUSTED_PROXIES` set if anything terminates TLS in front of you
- [ ] HTTPS works and HTTP redirects to it
- [ ] `https://yourdomain.com/.env` is not reachable — check it from outside
- [ ] The cron job exists and System Health says the scheduler is alive
- [ ] A queue worker is running, or the schedule is processing the queue
- [ ] `php artisan aziv:mail:test you@yourdomain.com` arrived in a real inbox
- [ ] `APP_KEY` is backed up somewhere other than this server
- [ ] A backup has been taken **and restored** at least once
- [ ] Admin → System Health is entirely green, or every non-green item is understood
- [ ] One real payment has been taken with a real card and refunded

The full pre-launch list is [18 — Security checklist](18-security-checklist.md).

## Caching, and when it bites

`php artisan optimize` caches configuration, routes, views and events. It makes everything faster
and it means **`.env` is no longer read at runtime**. Change `.env`, and nothing happens until you
run `optimize:clear` (or Admin → Maintenance → Rebuild the caches).

This is the single most common "I changed it and it did not work" on a production Laravel site.
