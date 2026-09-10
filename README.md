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
- **[`docs/10-decisions-and-risks.md`](docs/10-decisions-and-risks.md)** — the 9 decisions needed, and the honest risks

The requirements baseline is [`docs/blueprint/`](docs/blueprint/).

## Every screen is device-adaptive

Mobile, tablet and desktop get genuinely different layouts — not one layout resized. Bottom
navigation, drawers and bottom sheets on mobile; sidebars, multi-column workspaces and full
tables on desktop. The Admin Panel included. Structured for PWA installability.

Spec: [`docs/11-responsive-design-system.md`](docs/11-responsive-design-system.md).

## Planned stack

PHP 8.4 · Laravel 13 · Livewire 4 · Alpine.js · Tailwind CSS · Filament 5 ·
Spatie Laravel Permission 8 · MySQL 8+ · Redis

Per blueprint Rule 1, this is a **PHP/Laravel** application. Node.js is used only to compile CSS
and JavaScript at build time — the deployed application runs PHP alone.
