# Aziv AI — Deployment Portability & Environment Capability Matrix

**Status: OWNER ADDENDUM B** — records decision D-04 and the nine portability requirements
attached to it.

**The decision:** development, testing and staging run on the owner's existing **cPanel shared
hosting**. Production later moves to **cloud / VPS / managed Laravel** infrastructure. Shared
hosting must never become an architectural dependency, and **no feature is removed or downgraded
in the codebase** because of shared-hosting limits.

This document is requirement #7 of that decision: *"Clearly document which features are fully
functional on shared hosting and which require the later cloud/VPS environment."*

---

## 1. The principle that makes this work

> **Environment is configuration, not architecture.**

**No final hosting provider has been chosen, and none will be assumed — the owner will not be asked
to choose one at this stage.** Two deployment modes are officially supported from the same
codebase, selected by `AZIV_DEPLOYMENT_MODE` (`shared` / `cloud` / `auto`), and the application
**detects and reports its own environment's capabilities** rather than assuming them — see
[`17-system-health-diagnostics.md`](17-system-health-diagnostics.md).

 The application must be
deployable to *any* server meeting the documented requirements in
[`15-delivery-and-handover.md`](15-delivery-and-handover.md) §6. cPanel is where it is developed
and verified, not what it is built for.

Every piece of infrastructure — database, cache, queue, storage, mail, AI providers, streaming —
is reached through an interface whose implementation is chosen by an environment variable. The
application never knows, and never asks, what it is running on.

The practical test: **migrating from cPanel to a VPS must be a change of `.env` values, a database
import and a file copy — with zero changes to application code.** Any code that would break that
test is a defect, not a shortcut.

| Concern | Shared hosting value | Production value | Changed by |
|---|---|---|---|
| Database | `mysql` (cPanel MySQL) | `mysql` (managed) | `.env` |
| Cache | `database` | `redis` | `.env` |
| Session | `database` | `redis` | `.env` |
| Queue | `database` | `redis` | `.env` |
| Storage | `local` | `s3` | `.env` |
| Mail | `smtp` | `smtp` / API | `.env` |
| Streaming | `off` or `auto` | `on` | `.env` |
| Scheduler | cPanel cron | systemd / platform scheduler | Server config |
| Queue worker | cron, batch mode | persistent supervised worker | Server config |

### Rules that protect portability

These are enforced in review, every phase:

1. **No absolute filesystem paths.** Always `storage_path()`, `base_path()`, `Storage::disk()`.
2. **No provider-specific logic in application code.** No "if cPanel" branches, ever.
3. **No `exec()` / `proc_open()` in any core request path.** Frequently disabled on shared hosting.
4. **No hard-coded URLs.** Always `config('app.url')` and named routes.
5. **All secrets and endpoints from config**, which reads only from environment.
6. **Assets built in CI, deployed as artifacts** — the server needs neither Node nor Composer.
7. **Nothing assumes a persistent process exists.** Queue jobs must be safe to run in short bursts.
8. **Nothing assumes Redis exists.** Cache and locks must work on the database driver.
9. **Every long operation is a queued job**, never inline work in a web request — so raising
   `max_execution_time` is never the thing that makes a feature work.
10. **Assume no SSH, no Supervisor, no Redis, no Node.js and no root access.** Each has a
    documented fallback: a browser-based installer and Admin Panel maintenance utilities replace
    SSH; cron replaces Supervisor; database drivers replace Redis; pre-compiled assets and a
    bundled `vendor/` replace Node and Composer on the server. See
    [`15-delivery-and-handover.md`](15-delivery-and-handover.md) §4.

Rule 9 is the important one. It means the difference between shared hosting and a VPS is *how
fast* a job runs, not *whether the feature exists*.

---

## 2. Environment capability matrix

Requirement #7, answered directly. **Every feature is built in both environments.** This table
says how well each performs.

Legend: ✅ full · ⚠️ works with a real limitation · ❌ unavailable until migration

| Feature | cPanel shared | Cloud / VPS | Note on the limitation |
|---|---|---|---|
| **Accounts, auth, roles, permissions** | ✅ | ✅ | — |
| **Admin Panel — all ~90 screens** | ✅ | ✅ | — |
| **Branding, themes, media library** | ✅ | ✅ | — |
| **Content, banners, navigation, SEO** | ✅ | ✅ | — |
| **Responsive/adaptive UI, PWA manifest** | ✅ | ✅ | Entirely client-side |
| **Provider & credential management** | ✅ | ✅ | — |
| **Model catalog + scheduled sync** | ✅ | ✅ | Runs via cPanel cron |
| **API test console** | ✅ | ✅ | — |
| **AI chat — non-streaming** | ✅ | ✅ | Full answer arrives at once |
| **AI chat — streaming** | ⚠️ | ✅ | **The main limitation.** cPanel/LiteSpeed usually buffers output, so text arrives in one block after a pause. Aziv AI detects this and falls back cleanly |
| **Stop generation** | ⚠️ | ✅ | Meaningful only when streaming works |
| **Smart routing, fallback, circuit breaker** | ✅ | ✅ | — |
| **Cost & usage logging, analytics** | ✅ | ✅ | Nightly aggregation via cron |
| **Credits, ledger, holds** | ✅ | ✅ | — |
| **Subscriptions, payments, webhooks** | ✅ | ✅ | Webhooks are ordinary inbound requests |
| **Invoices, coupons, GST fields** | ✅ | ✅ | — |
| **Email (in-app + SMTP)** | ✅ | ✅ | Queued; sends within ~60s |
| **Background jobs generally** | ⚠️ | ✅ | Cron-driven: **up to ~60 seconds latency** before a job starts, vs near-instant with a persistent worker |
| **File upload (small/medium)** | ✅ | ✅ | Bounded by the host's `upload_max_filesize` |
| **File text extraction (large PDF/DOCX)** | ⚠️ | ✅ | Memory and execution limits may fail very large files. Admin file-size cap set accordingly |
| **Embeddings / RAG indexing** | ⚠️ | ✅ | Works, but slow — a large knowledge base may take many cron cycles |
| **Image generation** | ⚠️ | ✅ | The API call is fast; storing large images can hit limits |
| **Voice — speech-to-text / text-to-speech** | ⚠️ | ✅ | Audio file size and processing time are the constraints |
| **Concurrent users at volume** | ⚠️ | ✅ | Shared hosting throttles CPU and concurrent processes |
| **Redis cache / queue** | ❌ | ✅ | Rarely offered on shared hosting — database drivers used instead |
| **Horizon queue dashboard** | ❌ | ✅ | Requires Redis. A database-queue status screen is provided instead |
| **Real-time push / WebSockets** | ❌ | ✅ | Already deferred by blueprint §22 |
| **Object storage (S3)** | ❌ | ✅ | Local disk used until migration; one `.env` change switches it |

**Nothing in the codebase is removed.** Everything marked ⚠️ or ❌ is fully built, tested and
present — it simply performs better, or becomes available, after the move.

**And every row of this table is a live diagnostic check**, not a static document. The Admin
Panel's System Health screen reports the *actual* state of the server Aziv AI is running on,
graded relative to the active deployment mode — so an expected shared-hosting limitation shows as
GREY or YELLOW rather than alarming RED, while a genuinely fatal problem is RED in either mode.

---

## 3. Making shared hosting work properly

Concrete measures, so "shared hosting" does not silently mean "degraded everything".

### Queue without a persistent worker (requirement #4)

cPanel provides cron. That is enough:

```
# every minute — process queued jobs in a bounded burst, then exit cleanly
* * * * * cd /home/USER/aziv && php artisan queue:work --stop-when-empty --max-time=55 --tries=3

# every minute — Laravel's scheduler (model sync, aggregation, retention, expiry)
* * * * * cd /home/USER/aziv && php artisan schedule:run >> /dev/null 2>&1
```

`--stop-when-empty` and `--max-time=55` keep each run inside shared-hosting process limits and
guarantee it exits before the next tick. The cost is up to ~60 seconds of latency before a job
starts. On migration, the same jobs run under a persistent supervised worker with no code change —
only the cron line is removed.

### Streaming detection and graceful fallback

`AZIV_STREAMING_MODE` accepts `auto` (default), `on` or `off`.

In `auto`, Aziv AI performs a one-time probe on first use, caches the result, and picks the path.
Where buffering is detected the interface presents a clean "thinking…" state and delivers the
complete answer — rather than appearing frozen and then dumping text, which is what an
undetected buffering failure looks like. Setting `on` after migration restores true streaming
with a single environment change.

### Document root — the classic cPanel Laravel trap

cPanel serves from `public_html`, but Laravel must expose only its `public/` directory. Getting
this wrong exposes `.env`, source code and dependencies to the public internet.

Correct approaches, in order of preference:

1. **Point the domain's document root at `.../aziv/public`** via cPanel's domain settings. Cleanest.
2. If the root cannot be changed: place the application **outside** `public_html`, put Laravel's
   `public/` contents into `public_html`, and adjust the two paths in `index.php`.

Never place the full application inside `public_html` with a `.htaccess` band-aid. Phase 0 includes
a **deployment verification step** that requests `/.env`, `/composer.json`, `/storage/logs/laravel.log`
and `/vendor/autoload.php` over HTTP and **fails the phase if any of them is reachable.**

### Other shared-hosting measures

| Measure | Purpose |
|---|---|
| Assets compiled in CI; `vendor/` bundled in the release ZIP | **Server needs neither Node nor Composer** |
| Browser-based installer and Admin Panel maintenance utilities | **Server needs no SSH** |
| `config:cache`, `route:cache`, `view:cache` on deploy | Reduces per-request overhead materially |
| OPcache enabled where the host allows | Significant PHP performance gain |
| Admin file-size caps set to the host's real limits | Users get a clear message instead of a failed upload |
| Database-driven cache locks | Prevents overlapping cron runs colliding |
| Log rotation configured | Shared hosting disk quotas are small |

---

## 4. Migration path to production (requirement #9)

Designed to be a checklist, not a project.

| # | Step | Involves code changes? |
|---|---|---|
| 1 | Provision the VPS / managed Laravel platform | No |
| 2 | Deploy the same repository, same commit | No |
| 3 | Import the database (`mysqldump` → import) | No |
| 4 | Copy `storage/app` (uploaded files, generated images) | No |
| 5 | **Carry `APP_KEY` across unchanged** | No |
| 6 | Update `.env`: `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` → `redis`; `FILESYSTEM_DISK` → `s3`; `AZIV_STREAMING_MODE` → `on` | No |
| 7 | Start persistent queue workers; remove the cron queue line | No |
| 8 | Run `php artisan migrate` (no-op if already current) | No |
| 9 | Smoke test: login, chat with streaming, a payment webhook, a queued job | No |
| 10 | Switch DNS | No |

> ⚠️ **Step 5 is the one that can ruin a migration.** `APP_KEY` decrypts your stored AI provider
> credentials. Move to a new server with a freshly generated key and every provider key becomes
> unreadable and must be re-entered. It is called out here and in the Phase 9 backup procedure.

Optional after migration: move file storage to S3 (`FILESYSTEM_DISK=s3` plus a one-time file
sync), and enable Horizon for queue monitoring.

---

## 5. What must be verified on the cPanel account before Phase 0

These are facts about the owner's specific hosting plan that change what Phase 0 does. They are
listed in `12-decision-log.md` as the remaining pre-Phase-0 checks.

These are now **diagnostic checks the application performs itself**, not questions the owner must
answer in advance. They are listed here for reference; the System Health screen reports each one
on whatever server is used.

| Check | Why it matters | If unavailable |
|---|---|---|
| ~~PHP 8.2 or higher~~ ✅ **RESOLVED** | Owner confirmed cPanel offers **8.3, 8.4 and 8.5**. Target is **8.4**; Composer constraint `^8.3` so it runs on all three. No PHP 8.5-only feature is used | — |
| **MySQL 8.0+ or MariaDB 10.6+** | JSON columns and modern index behaviour | Schema adjusted for the available version |
| **SSH or cPanel Terminal access** | Running `artisan migrate`, caching config, deploying | A secured web-based migration runner is added |
| **Cron jobs available** | Queue processing and the scheduler both depend on cron | Queues become manual-trigger — a significant degradation |
| **Ability to set document root** | Security of `.env` and source | Use the `public_html` layout described in §3 |
| `upload_max_filesize` / `post_max_size` | File analysis and voice limits | Admin caps set to match |
| `max_execution_time`, `memory_limit` | Large-file processing | Chunk sizes tuned down |
| Outbound HTTPS permitted | **Every AI provider call depends on it** | Aziv AI cannot function — some shared hosts block outbound connections |

The last row is worth emphasising: a small number of shared hosts block outbound HTTP requests by
default. If that is the case here, it must be lifted before anything AI-related can work at all.

---

## 6. Honest summary

**What this decision buys:** near-zero infrastructure cost during development and staging, on
hosting already paid for.

**What it costs:** streaming chat will most likely not work properly until migration, and
background jobs run up to a minute behind. Both are performance characteristics of the
environment, not missing features.

**What it does not cost:** any feature, any architectural flexibility, or any rework. Because
every infrastructure concern is configuration, the migration is a checklist rather than a project.

**The one risk worth watching:** the temptation, once things are working, to keep production on
shared hosting because it is cheaper. That would leave the streaming chat experience — the feature
users most associate with a modern AI product — permanently degraded. The environment is a staging
decision, and the plan treats it that way.
