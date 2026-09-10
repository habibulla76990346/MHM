# Aziv AI — Delivery, Handover & Ownership

**Status: OWNER ADDENDUM E** — the delivery contract. Requirements tracked as `DL-1 … DL-30` in
the requirements register.

> The owner's governing principle: *"I need a complete, installable, transferable software product
> that I own. Another developer should be able to take the ZIP + SQL/database package +
> documentation and install Aziv AI on a compatible Laravel/PHP server without needing the original
> development environment."*

This changes what "done" means. Done is not "it runs on the server we built it on." **Done is a
package a stranger can install.**

---

## 1. The handover test

Every delivery requirement below reduces to one verifiable test, performed in Phase 9:

> **Take the release ZIP and the SQL package to a clean, never-before-used server that meets the
> documented requirements. Install Aziv AI using only the written documentation. No access to the
> development environment, no undocumented steps, no questions asked of the original developer.**

If that fails, delivery is not complete. It is run as a real rehearsal, not a thought experiment.

---

## 2. What ships

### 2.1 The release package

| Item | Detail | Why |
|---|---|---|
| **Complete Laravel source** | All application code | DL-2 |
| **`vendor/` included** | All Composer dependencies, pre-installed | **Critical.** The owner may have no SSH and therefore no Composer on the server |
| **Compiled front-end assets** | `public/build/` with hashed filenames | **The server never needs Node.js** |
| **Database migrations** | Canonical schema definition | DL-3 |
| **Seeders** | Roles, permissions, default settings, built-in themes, countries, currencies, tax templates | DL-4 |
| **`clean-install.sql`** | Full schema + reference data, ready to import via phpMyAdmin | DL-5 — for hosts where migrations cannot be run from a terminal |
| **`schema-only.sql`** | Structure without data | For developers who prefer to seed themselves |
| **`.env.example`** | **Every** variable, documented inline with purpose, valid values and default | DL-7 |
| **Documentation set** | The 20 guides in §3 | DL-8 … DL-27 |
| **`LICENSE` / ownership statement** | The owner's ownership of the codebase | DL-30 |

**Deliberately excluded:** `.env` (secrets never travel in a package), `.git/`, `node_modules/`,
development-only files, test fixtures containing real data, and any credential of any kind.

### 2.2 Keeping the SQL export honest

An SQL export and a set of migrations can drift apart, and a stale export is worse than none —
it installs a schema the code no longer expects.

**So the SQL files are generated, never hand-maintained.** A build command runs migrations and
seeders against a scratch database, exports the result, and stamps it with the migration version.
Installation verifies that stamp against the code's expected version and **refuses to run on a
mismatch** rather than half-installing.

```
php artisan aziv:build-release
  → fresh migrate + seed on a scratch database
  → export clean-install.sql and schema-only.sql
  → compile assets
  → stamp version + migration checksum
  → assemble the ZIP
```

---

## 3. The documentation set

Every guide the owner listed, written for someone who has never seen the project.

| # | Guide | Covers |
|---|---|---|
| 1 | **Server requirements** | PHP version range, **every required extension**, database versions, disk, memory, optional components |
| 2 | **Installation guide** | Both routes: web installer and command line |
| 3 | **Database setup** | Creating the database and user, importing SQL, or running migrations |
| 4 | **Web-root / public directory setup** | Document-root configuration, and the alternative layout when it cannot be changed |
| 5 | **Storage setup** | Directory permissions, the storage symlink, and the alternative where symlinks are unavailable |
| 6 | **Queue & cron setup** | Exact cron lines for shared hosting; worker configuration for VPS |
| 7 | **Mail / SMTP setup** | Configuring sending, testing it, common failures |
| 8 | **AI provider configuration** | Obtaining keys, adding providers, syncing models, testing |
| 9 | **Payment gateway configuration** | Per gateway: credentials, webhook URL, sandbox→live |
| 10 | **Tax / GST configuration** | Configuring jurisdictions, rates, invoice settings |
| 11 | **Admin account creation & reset** | First admin; password reset **without email access**; lockout recovery |
| 12 | **Shared hosting / cPanel deployment** | Step by step, with screenshots-level detail, assuming no SSH |
| 13 | **Cloud / VPS deployment** | Full server setup, workers, Redis, S3 |
| 14 | **Production deployment guide** | Release process, caching, zero-downtime approach |
| 15 | **Migration: shared hosting → Cloud/VPS** | The checklist, with `APP_KEY` called out |
| 16 | **Backup & restore** | What to back up, how, and a **tested** restore procedure |
| 17 | **Troubleshooting** | Symptom → cause → fix, **written around the System Health screen** — each diagnostic finding maps to a section here |
| 17a | **cPanel / shared requirements** | Explicit minimum plan capabilities |
| 17b | **Cloud / VPS requirements** | Explicit production requirements |
| 17c | **Outbound API requirements** | Which hosts Aziv AI must reach, on which ports — the page to send a hosting provider |
| 17d | **SSL/HTTPS requirements** | Certificate, enforcement, mixed content |
| 17e | **Production recommendations** | The configuration to aim for once past shared hosting |
| 18 | **Security checklist** | Pre-launch verification |
| 19 | **Upgrade / update guide** | Applying a new release without losing configuration or data |
| 20 | **Build & deployment commands** | Every command, what it does, when to run it |

Written in plain language, because the primary reader is the owner — not a developer. Where a step
genuinely requires developer knowledge, it says so rather than assuming.

---

## 4. The web installer — because SSH is not assumed

The owner's constraint: *"Do not assume that I will have SSH, Supervisor, Redis, Node.js or root
access on every server."*

Without SSH there is no `php artisan migrate`, no `composer install`, no `npm run build`. Three of
those are solved by shipping `vendor/` and compiled assets. The fourth needs a **browser-based
installer**.

### What it does

```
1. REQUIREMENTS CHECK   PHP version · every required extension · directory
                        writability · database connectivity
                        → shows pass/fail per item with how to fix each

2. DATABASE             host, name, user, password → tested before continuing

3. APPLICATION          site URL, name, timezone, locale, currency

4. INSTALL              writes .env · generates APP_KEY · runs migrations
                        · runs seeders

5. ADMIN ACCOUNT        creates the first Super Admin

6. FINISH               locks the installer · shows next steps
```

### Security — a web installer is a serious attack surface

An unlocked installer on a live site is a full compromise: it can rewrite `.env` and create an
admin account. So:

| Control | Behaviour |
|---|---|
| **Refuses to run once installed** | Checks an install marker **and** whether the database has tables. Both must indicate a fresh install |
| **Self-locks on completion** | Writes a lock file and sets an environment flag; the route then returns 404 |
| **Deletable** | Documentation instructs removing the installer directory after use; the application runs fine without it |
| **Rate limited** | Against brute-forcing the database step |
| **No credential echo** | Entered credentials are never displayed back or logged |
| **HTTPS strongly urged** | The installer warns prominently if accessed over plain HTTP |

**The command-line route remains available and is documented as preferred where SSH exists.** The
installer is the fallback for constrained hosting, not the default path.

### Maintenance utilities without SSH

The same reasoning applies after installation. Where SSH is unavailable, these are reachable from
the Admin Panel, each permission-gated and audit-logged:

| Task | Command-line equivalent |
|---|---|
| Run pending migrations after an update | `php artisan migrate` |
| Clear and rebuild caches | `php artisan optimize:clear` / `optimize` |
| Rebuild the storage link | `php artisan storage:link` |
| Process queued jobs once | `php artisan queue:work --stop-when-empty` |
| Reset an admin password | `php artisan aziv:admin:reset` |
| View recent logs | Tailing the log file |
| Run the system health check | §5 below |

---

## 5. System health check

One Admin Panel screen answering *"is this server configured correctly?"* — because on shared
hosting the owner cannot run diagnostics from a terminal.

| Checks | |
|---|---|
| PHP version within the supported range | Required extensions, each listed pass/fail |
| Directory writability (`storage/`, `bootstrap/cache/`) | Database connectivity and version |
| **Outbound HTTPS reachability** | Cron last-run time (is the scheduler alive?) |
| AI provider auth and model freshness | Payment gateway and webhook status |
| Queue backlog and oldest pending job | Storage disk usage against quota |
| Mail configuration test | `APP_KEY` present and credentials decryptable |
| **`.env` not reachable over HTTP** | `APP_DEBUG` off in production |
| Streaming mode detected | Cache and session drivers in use |

This screen is also the first thing to check when something breaks, and the troubleshooting guide
is written around it. **Full specification in
[`17-system-health-diagnostics.md`](17-system-health-diagnostics.md) (Owner Addendum G)** — the
health check summarised here is the entry point to that system, not a separate feature.

---

## 6. Required PHP extensions

Documented precisely, because "install Laravel's requirements" is not an instruction a
non-developer can act on. Verified as present on this build machine.

| Extension | Required for |
|---|---|
| `pdo_mysql` | Database |
| `mbstring` | Text handling |
| `openssl` | Encryption, HTTPS |
| `tokenizer`, `xml`, `dom`, `ctype`, `json`, `fileinfo`, `filter`, `hash`, `session`, `pcre` | Laravel core |
| `curl` | **Every AI provider and payment gateway call** |
| `bcmath` | Money arithmetic without floating-point error |
| `gd` *(or `imagick`)* | Image handling, favicon and icon generation |
| `zip` | Release packaging, document extraction |
| `intl` | Locale, currency and date formatting — **needed for multi-currency** |
| `iconv` | Character-set conversion during file extraction |
| `sodium` | Modern encryption primitives |
| **Optional** | `redis` (production cache/queue), `exif` (image validation), `opcache` (performance — strongly recommended) |

**PHP version policy:** development and staging target **PHP 8.4**. The Composer constraint is set
to `^8.3`, so the application runs on **8.3, 8.4 and 8.5** — the owner's cPanel offers all three,
and a future host may offer a different subset. **No PHP 8.5-only feature is used**, per the
owner's instruction, and a CI matrix runs the test suite on 8.3 and 8.4 to keep that honest rather
than merely intended.

---

## 7. Ownership

Per blueprint §29 and the owner's final principle:

| Asset | Held by |
|---|---|
| Source code repository | **The owner** |
| Domain, hosting, database | **The owner** |
| AI provider accounts and keys | **The owner** |
| Payment gateway accounts | **The owner** |
| Object storage, email sending | **The owner** |
| Release package and documentation | **The owner** |

**Nothing about Aziv AI depends on the environment it was built in.** No licence check phones home,
no hosted service is required, no component is unavailable to a future developer. The application
has no dependency on the original development environment, and the handover test in §1 proves it
rather than asserting it.

---

## 8. Effort impact

| Item | Cost |
|---|---|
| Web installer + maintenance utilities + health check | **+1 to +2 sessions** (Phase 9, partly Phase 1) |
| Release build pipeline and SQL export generation | **+0.5 to +1 session** |
| The 20 documentation guides | **+1 to +2 sessions** (Phase 9) |
| Handover rehearsal on a clean server | **+0.5 session** |

**Total: +3 to +5 sessions**, concentrated in Phase 9.

This is the difference between software that works and software you **own**. It is also what
protects the owner from being locked to any one developer — including me.
