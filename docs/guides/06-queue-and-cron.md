# 06 — Queue and cron

**Without this, a great deal silently does not happen.** Renewal invoices are never issued,
past-due reminders never go out, credits never expire, exchange rates never update, payments are
never reconciled — and on shared hosting, no background job runs at all. The site looks fine.

## The one line

```
* * * * * cd /path/to/aziv && php artisan schedule:run >> /dev/null 2>&1
```

Once a minute. Everything else is scheduled inside the application.

### cPanel

**Cron Jobs** → **Common Settings: Once Per Minute (* * * * *)** → paste the command with your own
path. Find the path in **File Manager**, or in cPanel's **Cron Jobs** help text.

If your host only offers five- or fifteen-minute cron, use it — Aziv AI tolerates it and the
health check knows the difference between "late" and "stopped".

### VPS

```sh
crontab -u www-data -e
```

## Proving it works

**Admin → System Health → Scheduled tasks (cron)** shows when it last ran. The scheduler writes a
heartbeat every time it fires; the check does arithmetic on it. Green means it ran in the last
half hour.

## Background jobs

Every long operation is a queued job: indexing a document, generating an image, transcribing a
recording, sending every email.

**On shared hosting** the cron line above also drains the queue. Jobs run within about a minute.
Nothing is unavailable — it is slower, never absent.

**On a VPS**, run a persistent worker instead:

```ini
# /etc/supervisor/conf.d/aziv-worker.conf
[program:aziv-worker]
command=php /path/to/aziv/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/aziv/storage/logs/worker.log
stopwaitsecs=3600
```

```sh
supervisorctl reread && supervisorctl update && supervisorctl start aziv-worker:*
```

**Restart workers after every deployment.** A running worker holds the old code in memory.

```sh
php artisan queue:restart
```

## With no shell at all

**Admin → Maintenance → Process waiting jobs now** works through the queue once. It is a
stop-gap, not a substitute for cron.

## What is scheduled

| When | What |
|---|---|
| Every minute | The scheduler heartbeat |
| Every 5 minutes | Payment reconciliation — catches a customer who closed the browser mid-payment |
| Every 10 minutes | Release expired credit holds |
| Hourly | Subscription changes; scheduled announcements |
| 00:05 | Exchange rates |
| 00:20 | Nightly cost rollup |
| 00:40 | Credit expiry |
| 01:10 | Delete media past its retention |
| 05:30 | Health check, with an email only if something changed |
| 06:00 | Renewal invoices, reminders and grace-period expiry |
| Weekly | Refresh AI model catalogues |
