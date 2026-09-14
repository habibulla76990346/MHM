# 02 — Installation

Two routes. Use the command line where you have it; the browser installer exists for hosting
where you do not.

---

## Before either route

1. Create an **empty database** and a user with full rights on it
   ([03 — Database setup](03-database-setup.md)).
2. Upload and extract the release package.
3. Point your domain's document root at the **`public/`** folder inside it
   ([04 — Web root](04-web-root.md)). This is the step that matters most.

---

## Route A — the browser installer (no SSH needed)

Open `https://your-domain/install`.

| Step | What happens |
|---|---|
| 1. Server | Checks PHP, extensions and folder permissions. Anything red must be fixed first |
| 2. Database | You enter the details. **The connection is tested before anything is written** |
| 3. Site | Your platform name, address, timezone and language |
| 4. Install | Generates `APP_KEY` and creates the tables |
| 5. Your account | Creates the first administrator. There is no default password |
| 6. Finished | **The installer locks itself.** Every one of its pages then returns "not found" |

The installer refuses to run if the database already has accounts, so it cannot be used against a
running site.

### If you will never use it

Set `AZIV_INSTALLER=off` in `.env` before deploying. The application runs perfectly without it.

---

## Route B — the command line (preferred where available)

```sh
cp .env.example .env
nano .env                       # database details, APP_URL, mail
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan aziv:admin:create
```

If you installed from source rather than the release package, run these first:

```sh
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

---

## Immediately afterwards — all four

1. **Back up `APP_KEY`** from `.env`, somewhere that is not this server.
   [Why](../19-backup-and-restore.md).
2. **Add the cron job** ([06 — Queue and cron](06-queue-and-cron.md)).
3. **Configure email and prove it** ([07 — Mail](07-mail-and-smtp.md)).
4. **Open Admin → System Health** and work through anything red.

## Nothing else is required to run

The platform works with no plan published, no tax configured and no payment gateway: it is
unmetered until you publish a plan, and charges no tax until you switch it on. Add an AI provider
([08](08-ai-providers.md)) and you have a working product.
