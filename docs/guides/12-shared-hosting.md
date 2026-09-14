# 12 — Shared hosting and cPanel

This is the path that assumes **no SSH, no Composer, no Node.js and no root**. Everything Aziv AI
needs those for has already been done: the release package ships `vendor/` and the compiled
front-end assets, so the server never has to build anything.

Allow an hour the first time.

## What your plan must offer

Check these before you start — [17a](17a-hosting-requirements.md) is a page you can send your
host and ask.

- [ ] PHP **8.3, 8.4 or 8.5**, with a way to select the version
- [ ] The extensions in [01 — Server requirements](01-server-requirements.md)
- [ ] MySQL 8.0+ or MariaDB 10.6+
- [ ] The ability to **set the document root**, or to use `.htaccess` (see below)
- [ ] **Cron jobs**
- [ ] **Outbound HTTPS** on port 443 — many shared plans block this, and every AI call and payment
      needs it
- [ ] At least 512 MB PHP memory limit and 2 GB of disk to start

## Step by step

### 1. Upload

cPanel → **File Manager** → your account's home directory, *above* `public_html`. Upload the
release ZIP there and use **Extract**. You want, for example, `/home/youruser/aziv/`.

Uploading a large ZIP and extracting on the server is far faster and far more reliable than FTPing
thousands of files.

### 2. Create the database

cPanel → **MySQL Databases**. Create a database, create a user, and add the user to the database
with **All Privileges**. Write down all three values — cPanel usually prefixes them with your
account name.

### 3. Point the domain at `public/`

cPanel → **Domains** → your domain → **Document Root**, and set it to `/home/youruser/aziv/public`.

**This matters more than anything else in this guide.** With the document root one level too high,
your `.env` file — containing every credential you own — is downloadable by anybody who guesses
the address.

If your host will not let you change the document root, use the alternative layout in
[04 — Web root](04-web-root.md). Do not skip it and hope.

### 4. Run the installer

Open `https://yourdomain.com/install`. It checks your server, takes your database details, tests
them before writing anything, writes `.env`, generates `APP_KEY`, creates the tables, and creates
your administrator account. Then it locks itself: the address returns 404 from then on.

If the requirements page shows anything red, fix it and reload. It tells you what each item means.

### 5. Add the cron job

cPanel → **Cron Jobs** → every minute:

```
* * * * * /usr/local/bin/php /home/youruser/aziv/artisan schedule:run >/dev/null 2>&1
```

Your PHP path may differ — cPanel usually shows it, and `/usr/local/bin/ea-php84` is common. Get
this wrong and nothing time-based ever happens.

### 6. Check

Sign in, open **Admin → System Health**, and work through anything red. Then
`php artisan aziv:mail:test` — or, without a shell, send yourself a test from
**Admin → Notifications**.

## Things that go wrong on shared hosting specifically

| Symptom | Cause |
|---|---|
| Blank white page | PHP version too low, or a missing extension. Check the error log in cPanel |
| "500" immediately after install | `storage/` not writable. See [05](05-storage.md) |
| Every AI call fails, keys are correct | Outbound HTTPS is blocked. Ask your host to allow it |
| Replies appear all at once, not word by word | The host buffers output. Aziv AI detects this and falls back automatically — it is slower, not broken |
| Uploaded images 404 | The storage link. **Admin → Maintenance → Rebuild the storage link** |
| Nothing renews, no emails | The cron job is missing or the PHP path in it is wrong |

## Delete the installer afterwards

It locks itself, but deleting `routes/install.php`, `app/Http/Controllers/Install/` and
`resources/views/install/` removes the code entirely. The application runs fine without them.
