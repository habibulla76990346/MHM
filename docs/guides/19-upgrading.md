# 19 — Upgrading to a new release

An upgrade replaces the code. It never replaces your configuration, your database or your
customers' files.

## What is yours and what is ours

| Never overwritten | Replaced by the new release |
|---|---|
| `.env` | Everything under `app/`, `config/`, `resources/`, `routes/` |
| `storage/app/` — customers' files | `vendor/` |
| `storage/logs/` | `public/build/` — the compiled assets |
| Your database | `database/migrations/` — new files added |
| `storage/installed.json` | `public/index.html`, `artisan`, the rest of the source |

**Your settings are in the database, not in files.** Themes, branding, providers, plans, tax rules,
notification wording — none of it is in the code, so none of it is at risk from replacing the code.

## Before you start

1. **Back up.** Database *and* `storage/app/` *and* `.env`. `php artisan aziv:backup:manifest`
   prints exactly what a complete backup of your server contains.
2. Read the release notes for anything marked as breaking.
3. Pick a quiet hour.
4. If you have a staging copy, do it there first. If you do not, and the site earns money,
   consider making one.

## The upgrade

```sh
php artisan down
```

Extract the new release **over the top** of the installation, keeping `.env`, `storage/` and
`bootstrap/cache/`. Then:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan optimize
php artisan queue:restart
php artisan up
php artisan aziv:diagnose
```

**Without SSH:** upload and extract through File Manager, then Admin → Maintenance → *Finish an
update*, then *Rebuild the caches*. Both are permission-gated and both are recorded in the audit
log with their output.

`queue:restart` matters: a running worker holds the old code in memory until it is told to stop.

## Afterwards

- [ ] Sign in.
- [ ] Send a chat message and get a reply.
- [ ] Open a customer file and a generated image.
- [ ] Admin → System Health, entirely.
- [ ] Check the queue is moving.

## Rolling back

**If no migration ran,** roll back by putting the old code back. That is all it is.

**If a migration ran, the code cannot be rolled back on its own** — the schema has moved and the
old code does not know about it. Restore the database backup you took, then the old code, then
`optimize:clear`. This is the reason step 1 is not optional, and the reason a staging copy is worth
the money once the platform is earning.

## Migrations that take a long time

A migration on a large table can exceed a web request's time limit, which is why *Finish an update*
in the panel is the only place in Aziv AI that can run long. If it times out on shared hosting,
run it as a **one-off cron job** instead:

```
* * * * * /usr/local/bin/php /home/youruser/aziv/artisan migrate --force >/home/youruser/migrate.log 2>&1
```

Let it fire once, read the log, then delete the cron line.

## Version

The current version is shown at the bottom of Admin → System Health and in
`php artisan aziv:diagnose`. Quote it whenever you ask for help.
