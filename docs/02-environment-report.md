# Aziv AI — Environment Inspection Report

Inspected on the build machine before any planning decisions were fixed.

## What is present

| Component | Version found | Verdict |
|---|---|---|
| PHP | **8.4.19** (CLI, NTS) | Exceeds the blueprint's "PHP 8.3+" requirement |
| Composer | **2.8.12** | Current |
| Packagist reachability | HTTP 200 | Packages install cleanly |
| Node.js / npm | 22.22.2 / 10.9.7 | Available for **asset compilation only** |
| Redis server | 7.0.15 | Binary present (daemon not currently running) |
| Git | 2.43.0 | Fine |
| CPU / RAM / Disk | 4 cores / 15 GB / 30 GB free | Comfortable for development |

### PHP extensions confirmed present

`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `mysqli`, `redis`, `gd`, `intl`, `mbstring`,
`openssl`, `sodium`, `curl`, `zip`, `xml`, `dom`, `fileinfo`, `exif`, `bcmath` via `gmp`-free
paths, `tokenizer`, `opcache`, `pcntl`, `posix`.

Every extension Laravel requires is present, plus the ones this project specifically needs:
`gd` (image handling), `intl` (locale/currency formatting), `sodium` + `openssl` (credential
encryption), `redis` (cache/queue), `zip` (file handling), `exif` (upload validation).

PDO drivers available: `mysql`, `pgsql`, `sqlite`.

## Dependency versions verified against Packagist

These were resolved live, not from memory, and they are mutually compatible:

| Package | Latest stable | Compatibility check |
|---|---|---|
| `laravel/framework` | **13.31.0** | Requires PHP ^8.2 — satisfied by 8.4.19 |
| `livewire/livewire` | **4.4.4** | — |
| `filament/filament` | **5.8.1** | `filament/support` declares `illuminate/contracts: ^11.28\|^12.0\|^13.0` and `livewire/livewire: ^4.1` — **confirmed Laravel 13 + Livewire 4 compatible** |
| `spatie/laravel-permission` | **8.3.0** | Declares `illuminate/*: ^12.0\|^13.0` — **confirmed Laravel 13 compatible** |
| `laravel/sanctum` | 4.3.3 | API tokens |
| `laravel/horizon` | 5.49.0 | Redis queue dashboard |
| `stripe/stripe-php` | 21.3.2 | Reference gateway (pending decision D-01) |
| `smalot/pdfparser` | 2.12.5 | PDF text extraction for Phase 8 |
| `phpoffice/phpword` | 1.4.0 | DOCX extraction for Phase 8 |
| `league/commonmark` | 2.10.1 | Safe Markdown rendering of AI output |

**Conclusion: the entire blueprint stack is buildable here as specified.** No requirement forced
a stack substitution.

## What is missing, and what it means

### 1. No MySQL server on this machine

`mysql`, `mysqld` and `sqlite3` CLI binaries are absent. The PHP *drivers* for all three
databases are present, so this only affects local development, not the design.

MariaDB 10.11 is installable from the system package repository if we want full parity.

**Recommendation:** develop against **MySQL/MariaDB from Phase 1 onward**, not SQLite. SQLite is
faster to start with but silently tolerates things MySQL rejects (column length limits, strict
mode, index sizes, JSON behaviour, transactional DDL). Discovering those differences at
deployment is exactly the kind of late failure a non-developer cannot debug. The cost of
installing MariaDB now is a few minutes; the cost of not doing it is a broken launch.

SQLite will still be used for the automated test suite, where speed matters and the schema is
recreated from the same migrations each run.

### 2. Redis is installed but not running

Not a problem. It will be started for development. Laravel falls back to database-backed cache
and queues where Redis is unavailable, which is what the blueprint asks for on basic hosting.

### 3. The repository is empty

`habibulla76990346/MHM` has no commits and no issues. This planning documentation will be its
first commit. No application code exists yet.

## The Node.js question — direct answer

The blueprint's Rule 1 says the project must not be silently switched to Node.js, and §2 says the
architecture must avoid unnecessary dependence on Node.js/Next.js for the core application.

**This plan complies, and here is the precise reason.**

Tailwind CSS is a build tool. It reads your templates and produces one `.css` file. That
compilation happens **on your computer or in the build step**, and the resulting plain CSS and
JavaScript files are what get deployed. Your web server runs **PHP only**. There is no Node
process serving requests, no Node runtime requirement on the host, and nothing breaks if Node is
absent from the production server.

To put it plainly: Node is used the way a word processor is used to produce a PDF. The reader of
the PDF does not need the word processor.

If you would prefer to eliminate Node from your workflow entirely, there is a supported route:
Tailwind ships a **standalone executable** that requires no Node installation at all. This is
noted as decision **D-06**. Either way, the deployed application is pure PHP/Laravel, which is
what Rule 1 requires.
