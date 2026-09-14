# 20 — Every command, and when to run it

Run all of these from the directory containing `artisan`.

**Without SSH,** the four you actually need are on **Admin → Maintenance**, each permission-gated
and each recorded in the audit log with its output. They are marked ✔ below.

---

## Installing

| Command | What it does |
|---|---|
| `php artisan key:generate` | Creates `APP_KEY`. **Once, ever.** Running it again on a live site makes every stored provider key and gateway secret unreadable, permanently |
| `php artisan migrate --force` | ✔ Creates or updates the database tables. `--force` is what tells Laravel you mean it in production |
| `php artisan db:seed --force` | Roles, permissions, default settings, built-in themes, countries, currencies. **First install only** |
| `php artisan storage:link` | ✔ Links `public/storage` to `storage/app/public`. Without it, uploaded images 404 |
| `php artisan aziv:admin:create` | Creates an administrator. Never seeded, never defaulted — a shipped account with a known password is a back door |
| `php artisan aziv:admin:reset --email=you@yourdomain.com` | Recovers an administrator you cannot sign in as. `--clear-mfa` removes two-factor, `--unlock` reactivates a suspended account. The password is prompted, never an option, and never reaches the audit log. See [11](11-admin-accounts.md) |

## Every deployment

| Command | What it does |
|---|---|
| `php artisan optimize` | ✔ Caches configuration, routes, views and events. Faster — and `.env` stops being read at runtime |
| `php artisan optimize:clear` | ✔ Throws those caches away. **Run this after editing `.env`,** or nothing you changed takes effect |
| `php artisan queue:restart` | Tells running workers to pick up the new code. They hold the old code in memory otherwise |
| `php artisan down` / `php artisan up` | Maintenance mode on and off |

## Checking

| Command | What it does |
|---|---|
| `php artisan aziv:diagnose` | Everything that is wrong with this server, and whose problem each item is. Graded by environment |
| `php artisan aziv:diagnose --json` | The same, machine-readable |
| `php artisan aziv:diagnose --export=report.txt` | A support-safe file. **No credential of any kind** — scrubbed at construction, so no output path can leak one |
| `php artisan aziv:diagnose --record` | Stores the run so System Health can show what changed since last time |
| `php artisan aziv:mail:test you@yourdomain.com` | Sends **one real email** through your real mailer and reports what the mail server said. Never prints a credential. Treats `log`, `array` and `null` as failure |
| `php artisan aziv:backup:manifest` | What a complete backup of **this** server must contain — paths, database, and `APP_KEY` |
| `php artisan about` | Laravel's own summary: versions, drivers, cache state |

## Running

| Command | What it does |
|---|---|
| `php artisan schedule:run` | **The one cron job.** Every minute. Everything time-based depends on it |
| `php artisan queue:work` | A persistent worker. VPS, under Supervisor |
| `php artisan queue:work --stop-when-empty` | ✔ Works through the queue once and stops. What shared hosting uses |
| `php artisan queue:failed` | Jobs that gave up |
| `php artisan queue:retry all` | Try them again |
| `php artisan aziv:models:sync` | Refreshes every provider's model catalog. Also a button in Admin |

## Building a release

| Command | What it does |
|---|---|
| `php artisan aziv:release` | Builds the delivery package: source, `vendor/`, compiled assets, the SQL export, the documentation, and a manifest with a checksum for every file |
| `php artisan aziv:release --skip-sql` | The same without regenerating the SQL export |

## Development only

Never on a live site — they create test data.

| Command | What it does |
|---|---|
| `php artisan aziv:test-user` | The local account the responsive gate signs in as |
| `php artisan aziv:test-fixtures` | A local plan and a no-money gateway, so the checkout gate has something to check |
| `php artisan test` | The PHPUnit suite |
| `npm run build` | Compiles the front-end assets. **Not needed on a server** — the release package ships them compiled |
| `npm run test:responsive` | The six-viewport gate |
| `npm run test:chat` / `test:checkout` / `test:images` / `test:voice` | The browser behaviour gates |

---

## The three that are dangerous

| Command | Why |
|---|---|
| `php artisan key:generate` | On a live site: every encrypted credential in the database becomes permanently unreadable |
| `php artisan migrate:fresh` | Drops every table. Everything. There is no confirmation worth trusting here |
| `php artisan db:seed` on a live site | Can reintroduce defaults over things you changed |

If you are unsure, take a backup first. It costs a minute.
