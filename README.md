# Aziv AI

A multi-provider AI SaaS platform built with PHP/Laravel, in which the Admin Panel is the central
control layer for branding, appearance, content, AI providers, model availability, routing, user
access, subscriptions, credits, analytics and operational feature flags.

> **Status: PLANNING — awaiting owner approval.**
> No application code has been written yet. This repository currently contains the analysis and
> implementation plan only.

## Start here

**[`docs/00-README.md`](docs/00-README.md)** — the documentation index.

If you only read two documents, read:

- **[`docs/09-development-phases.md`](docs/09-development-phases.md)** — what gets built, in what order
- **[`docs/12-decision-log.md`](docs/12-decision-log.md)** — authoritative status of all 11 decisions
- **[`docs/10-decisions-and-risks.md`](docs/10-decisions-and-risks.md)** — the reasoning behind each, and the honest risks

The requirements baseline is [`docs/blueprint/`](docs/blueprint/).

**Deployment:** cPanel shared hosting for development and staging, migrating to cloud/VPS for
production — see [`docs/13-deployment-portability.md`](docs/13-deployment-portability.md).

**Diagnostics:** the application detects and reports its own hosting, PHP, database, network,
provider and security problems — with severity, cause, responsible party and a plain-language
action. See [`docs/17-system-health-diagnostics.md`](docs/17-system-health-diagnostics.md).

**Delivery:** a complete, installable, transferable product — release ZIP, generated SQL package
and 20 guides, verified by a handover test on a clean server. See
[`docs/15-delivery-and-handover.md`](docs/15-delivery-and-handover.md).

**Tax & currency:** fully configurable, nothing assumed; issued invoices are frozen so historical
records never change. See
[`docs/16-tax-and-international-billing.md`](docs/16-tax-and-international-billing.md).

**Payments:** multi-gateway and adapter-based — Razorpay as the initial default, with PhonePe,
PayU, Cashfree and CCAvenue supported by the architecture — see
[`docs/14-payment-gateway-architecture.md`](docs/14-payment-gateway-architecture.md).

## Every screen is device-adaptive

Mobile, tablet and desktop get genuinely different layouts — not one layout resized. Bottom
navigation, drawers and bottom sheets on mobile; sidebars, multi-column workspaces and full
tables on desktop. The Admin Panel included. Structured for PWA installability.

Spec: [`docs/11-responsive-design-system.md`](docs/11-responsive-design-system.md).

## Planned stack

PHP 8.4 (runs on 8.3–8.5) · Laravel 13 · Livewire 4 · Alpine.js · Tailwind CSS · Filament 5 ·
Spatie Laravel Permission 8 · MySQL 8+ · Redis

Per blueprint Rule 1, this is a **PHP/Laravel** application. Node.js is used only to compile CSS
and JavaScript at build time — the deployed application runs PHP alone.
