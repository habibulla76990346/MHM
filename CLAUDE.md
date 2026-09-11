# Aziv AI — working rules

A multi-provider AI SaaS platform in PHP/Laravel, where the Admin Panel is the control layer for
branding, themes, content, AI providers, models, routing, billing, credits, tax and operations.

**The plan is the contract.** `docs/00-README.md` is the index; `docs/12-decision-log.md` is the
authoritative record of what is settled. Read the decision log before changing anything
architectural.

## Stack

PHP 8.4 (runs on 8.3–8.5) · Laravel 13 · Livewire 4 · Alpine · Tailwind 4 · Filament 5 ·
Spatie Permission 8 · MySQL/MariaDB · Redis where available, database drivers otherwise.

## Rules that are checked, not just stated

These have tests behind them. Breaking one fails the build.

1. **No hard-coded colours.** Every colour, radius, shadow, spacing value and font references a
   design token. A template containing `bg-blue-600` or `#3b82f6` silently stops responding to the
   theme system. → `SmokeTest::test_no_blade_template_hard_codes_a_colour`
2. **No horizontal overflow, 44px touch targets, 16px inputs.** Checked at six viewports on every
   screen. A screen failing at any width fails the phase. → `npm run test:responsive`
3. **Nothing sensitive inside `public/`.** → `DeploymentSecurityTest`
4. **No credential value reaches the diagnostics layer.** Checks ask "is this valid?" and get a
   boolean. `CheckResult` scrubs at construction, so no output path can leak.
   → `CheckResultTest`, `RedactorTest`

## Rules enforced by review

- **No hard-coded model names** (`gpt-4o`, `gemini-…`) in business logic. The router asks the
  database which models support the required capability.
- **No gateway names** (`razorpay`, …) in subscriptions, plans, invoices, credits or checkout.
  Those hold a gateway id and call the interface.
- **No tax rate, label or code anywhere in code.** Tax is data; issued invoices are frozen
  snapshots.
- **Environment is configuration, not architecture.** No absolute paths, no provider-specific
  branches, no `exec()` in a request path, nothing assuming Redis or a persistent worker. Moving
  host must be an `.env` change plus a data copy — never a code change.
- **Every long operation is a queued job**, so shared hosting differs in *speed*, never in
  *capability*.
- **Every admin write does three things or it does not ship:** authorise, validate, audit with
  before/after.

## Layout

```
app/Domains/<Module>/    Models, Services, Actions, DTO, Contracts, Events, Jobs, Policies
app/Filament/            Admin panel resources
resources/css/app.css    Design tokens — the source of every colour in the app
tests/Responsive/        The six-viewport gate
docs/                    The plan. 19 documents
brand/                   Master artwork (never modified) + derived variants
```

## Commands

```sh
php artisan test                 # PHPUnit
npm run build                    # compile assets (no network needed)
npm run test:responsive          # six-viewport gate (needs the app served)
php artisan aziv:diagnose        # what is wrong with this server, and whose problem it is
php artisan aziv:diagnose --json # same, machine-readable
```

## Local development

MariaDB runs locally; `aziv` and `aziv_test` databases, user `aziv`. If MariaDB is not running:

```sh
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
nohup /usr/sbin/mariadbd --user=mysql --datadir=/var/lib/mysql \
  --socket=/run/mysqld/mysqld.sock > /tmp/mariadb.log 2>&1 &
```

Playwright uses the preinstalled browser — set `CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome`.
Do not run `playwright install`.

## Where the build is

Phases 0 and 1 complete. Phase 2 next: Admin Panel foundation, branding, the full theme engine
(including the admin panel itself, per D-07), and content management.
Phase detail in `docs/09-development-phases.md`.

### Useful commands added in Phase 1

```sh
php artisan aziv:admin:create   # create an administrator (never seeded)
php artisan aziv:test-user      # local account the responsive gate signs in as
```

### Two traps worth remembering

- **Settings validation must use a flat field name.** Laravel reads dots in a validation key as
  nested-array access, so validating under `auth.password_min_length` passes every rule vacuously.
- **`Gate::after` cannot downgrade an allow** (`$result ??= $afterResult`), and Spatie registers
  its own `Gate::before`. Permission denials belong in `User::hasPermissionTo()`.
