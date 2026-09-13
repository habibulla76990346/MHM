# 19 — Backup and restore

*Status: implemented and tested. The restore below is performed by
`tests/Feature/Deployment/BackupRestoreTest.php` on every build, not merely described here.*

---

## The one-paragraph version

A complete backup of Aziv AI is **three things**: the database, the private storage directory, and
`APP_KEY`. Two of them are obvious. The third is the one that ruins restores: every AI provider
key, payment gateway credential and webhook secret in the database is encrypted under `APP_KEY`,
so a database restored alongside a freshly generated key contains rows that **can never be
decrypted again** — not by support, not by Anthropic, not by anyone. There is no recovery from it
beyond re-entering every credential by hand. Back up the key, keep it somewhere that is not this
server, and test the restore before you need it.

---

## 1. What to back up

| # | What | Where it is | How often |
|---|------|-------------|-----------|
| 1 | **The database** | `DB_DATABASE` on `DB_HOST` | Daily, kept 30 days |
| 2 | **Private storage** | `storage/app/private` — uploads, knowledge-base source files, brand masters | Daily |
| 3 | **`APP_KEY`** | The one line in `.env` | Once, then again after any key rotation |
| 4 | *(useful, not required)* the rest of `.env` | Reconstructible from `.env.example` plus your provider details | On change |

**What is deliberately NOT on this list:**

- `public/brand/` — derived raster images, regenerated from the masters in private storage.
- `storage/framework/`, `bootstrap/cache/` — caches. Restoring them is worse than not.
- `vendor/`, `node_modules/`, `public/build/` — produced by `composer install` and `npm run build`.
- Anything under `brand/` in the repository — it ships with the product and is never modified.

`php artisan aziv:backup:manifest` prints this list resolved against **your** configuration —
the actual database name, the actual disk roots — because a document drifts and a configuration
does not. The build fails if a filesystem disk exists that the manifest does not name.

---

## 2. Taking a backup

### On a server with shell access

```sh
# 1. The database. --single-transaction so the site keeps working while it runs.
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  -u "$DB_USERNAME" -p "$DB_DATABASE" > aziv-$(date +%F).sql

# 2. Private storage.
tar -czf aziv-storage-$(date +%F).tar.gz -C storage/app private

# 3. The key. Print it, then put it somewhere that is not this server.
grep '^APP_KEY=' .env
```

Never pass the password on the command line (`-pSECRET`): it is visible to every other user on the
machine in `ps`. `-p` prompts, and a `~/.my.cnf` with `[client]` credentials is better still.

### On cPanel without shell access

1. **phpMyAdmin → Export → Custom → Quick**, format SQL, save the file.
2. **File Manager → `storage/app`**, right-click `private` → Compress → download the archive.
3. **File Manager → `.env`** → View → copy the `APP_KEY` line into your password manager.

cPanel's own "Backup Wizard → Full Backup" covers 1 and 2 in one file. It also includes `.env`, and
therefore the key — which is convenient and is also why that archive must be treated as a
credential itself.

### Automating it

A daily cron entry is enough:

```
0 3 * * * cd /home/USER/aziv && mysqldump --single-transaction --quick -u USER DBNAME > backups/db-$(date +\%F).sql 2>> backups/backup.log
```

Rotate it (`find backups -name 'db-*.sql' -mtime +30 -delete`), and keep at least one copy on
different hardware. A backup on the same disk as the database survives a mistake and not a failure.

---

## 3. Restoring

**Order matters.** The key goes in before the data is read, not after.

```sh
# 1. Put the application into maintenance so nothing writes during the restore.
php artisan down

# 2. Deploy the code at the same commit, then install and build.
composer install --no-dev --optimize-autoloader
npm ci && npm run build

# 3. THE KEY FIRST. Paste the saved APP_KEY line into .env.
#    Do NOT run key:generate. It would overwrite the key the backup needs
#    and silently make every stored credential unreadable.
nano .env

# 4. The database.
mysql -u "$DB_USERNAME" -p "$DB_DATABASE" < aziv-2026-09-13.sql

# 5. Private storage.
tar -xzf aziv-storage-2026-09-13.tar.gz -C storage/app

# 6. Bring the schema up to date, in case the code is newer than the dump.
php artisan migrate --force

# 7. Clear everything derived.
php artisan config:clear && php artisan cache:clear && php artisan view:clear

php artisan up
```

### Verifying the restore — the part people skip

An untested backup is a hope. Five checks, in this order, because each one fails differently:

1. **`php artisan aziv:diagnose`** — the database, the filesystem, the queue and mail, graded.
2. **Sign in as an administrator.** Proves the session, the database and the app key together.
3. **Admin → AI Providers → a provider → Credentials.** If the key shows as `••••••••` with its
   last four characters, `APP_KEY` is right. **If it errors or shows nothing, stop** — the key
   does not match the data, and continuing will overwrite good ciphertext with new.
4. **Send one chat message.** Proves the credential decrypts to something a provider accepts.
5. **Admin → Invoices → open the most recent one.** Proves the money records survived intact.

### Restoring onto a different domain

Change `APP_URL`, then `php artisan config:clear`. Signed links in emails already sent were signed
against the old address and will stop working; nothing else is affected. Re-issue a renewal link
from Admin → Subscriptions rather than editing the old email.

---

## 4. Rotating `APP_KEY`

Only ever with `APP_PREVIOUS_KEYS` set to the old key, and only after a backup:

```
APP_KEY=base64:the-new-one
APP_PREVIOUS_KEYS=base64:the-old-one
```

Laravel decrypts with the previous key and re-encrypts with the new one as each value is written.
Leave both in place for at least one full billing cycle, so every credential and every session has
been touched, then remove the old one — and update the backed-up key at the same moment.

---

## 5. What a backup does not protect you from

- **A deleted invoice.** Invoices are immutable by design and are corrected with credit notes, not
  edits — but a restore rolls back everything that happened since the dump, including payments
  that were taken. Reconcile against the gateway's own dashboard after any restore, using
  Admin → Payments → Reconcile.
- **A leaked `APP_KEY`.** Rotate it, then re-enter every provider and gateway credential, because
  anyone holding the key and a copy of the database holds all of them.
- **A silent backup failure.** The cron line above appends failures to `backup.log` and nothing
  reads it. Look at the file, or at the size of yesterday's dump, once a month.
