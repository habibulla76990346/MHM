# 13 — Cloud and VPS deployment

Everything on shared hosting works; this is what you gain when you control the machine. Nothing
here is a code change — moving host is an `.env` change and a data copy, always.

## A sensible starting size

| | Small (a few hundred users) | Growing |
|---|---|---|
| CPU | 2 vCPU | 4+ vCPU |
| RAM | 4 GB | 8 GB+ |
| Disk | 40 GB SSD | 80 GB+, or object storage for files |
| Database | On the same box | Managed MySQL |

AI calls spend their time waiting on somebody else's API, so this is far less CPU-hungry than it
looks. Disk fills faster than you expect once customers generate images.

## The stack

- **nginx** (or Apache) with the document root at `public/`
- **PHP-FPM 8.4** with the extensions from [01](01-server-requirements.md), plus `opcache`
- **MySQL 8** or **MariaDB 10.6+**
- **Redis** — optional, and worth it: cache, sessions and queue all get faster
- **Supervisor** — to keep queue workers running
- **Certbot** or your provider's certificate

## What changes in `.env`

```
APP_ENV=production
APP_DEBUG=false

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
```

`APP_DEBUG=false` is the one that matters. With it on, an error page prints your entire
environment — every key and password — to whoever triggered it. Aziv AI refuses to consider itself
production-ready while it is on, and says so on System Health.

If you are behind Cloudflare or any load balancer, also set:

```
TRUSTED_PROXIES=*
```

Without it every emailed link — password resets, payment links, email verification — returns 403,
because the signature covers the scheme and your server thinks the request arrived over plain HTTP.
Use a named range instead of `*` where you know it.

## Persistent queue workers

This is the real difference from shared hosting: jobs start immediately instead of waiting for the
next minute.

`/etc/supervisor/conf.d/aziv-worker.conf`:

```
[program:aziv-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/aziv/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/aziv/storage/logs/worker.log
stopwaitsecs=3600
```

```sh
supervisorctl reread && supervisorctl update && supervisorctl start aziv-worker:*
```

**You still need the cron job.** Supervisor runs the queue; cron runs the schedule, and the
schedule is what decides when things are queued. See [06](06-queue-and-cron.md).

After every deployment, `php artisan queue:restart` — workers hold the old code in memory
otherwise.

## Object storage for uploads

Set the private disk to S3 (or any S3-compatible service) in `.env` and nothing else changes:
uploads, extraction, generated images and voice recordings all go through the same disk
abstraction.

**The bucket must not be public.** Aziv AI serves files through its own controller so that
ownership is checked on every request, and only bytes it wrote, of a type it named, ever render
inline.

## Serving

Point nginx at `public/`, pass `.php` to PHP-FPM, and deny access to dotfiles. The one nginx rule
worth stating explicitly:

```
location ~ /\. { deny all; }
```

Then confirm from outside the machine that `https://yourdomain.com/.env` returns 404 or 403.
System Health checks this too, but check it yourself the first time.

## Backups

A VPS has no automatic backup unless you arrange one. `php artisan aziv:backup:manifest` prints
exactly what a complete backup of this server must contain, and
[19 — Backup and restore](../19-backup-and-restore.md) has the procedure, including the restore —
which has actually been performed, not merely described.
