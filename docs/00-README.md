# Aziv AI — Planning Documentation

Complete analysis and implementation plan derived from
*Aziv AI FINAL Master Blueprint — Admin Control / Laravel* (8 pages, rev. 11 September 2026).

**Status: PLANNING — awaiting approval. No application code has been written.**

## Read in this order

| # | Document | What it answers |
|---|---|---|
| 01 | [Requirements Register](01-requirements-register.md) | Every blueprint section, mapped to a module and phase. Proof nothing was dropped |
| 02 | [Environment Report](02-environment-report.md) | What the build machine has, what it lacks, verified package versions |
| 03 | [Architecture](03-architecture.md) | How the application is shaped and how a request flows through it |
| 04 | [Database Architecture](04-database-architecture.md) | All 62 tables in 10 groups |
| 05 | [Module Structure](05-module-structure.md) | The 15 modules and how they depend on each other |
| 06 | [Admin Panel Architecture](06-admin-panel-architecture.md) | ~90 admin screens, the permission matrix, emergency controls |
| 07 | [Universal AI Provider Architecture](07-ai-provider-architecture.md) | How one app talks to many AI companies, and adds more without code |
| 08 | [Theme & Branding System](08-theme-branding-system.md) | The design-token engine behind full colour control |
| 09 | [Development Phases](09-development-phases.md) | The 9 blueprint phases, detailed, with test gates and timeline |
| 10 | [Decisions & Risks](10-decisions-and-risks.md) | **11 decisions I need from you, and 12 risks stated plainly** |
| 11 | [Responsive Design System](11-responsive-design-system.md) | **Owner Addendum A** — adaptive layouts for mobile/tablet/desktop, and PWA readiness |
| 12 | [Decision Log](12-decision-log.md) | **Authoritative status of every decision.** Read this first to see what is settled and what is blocking |
| 13 | [Deployment & Portability](13-deployment-portability.md) | **Owner Addendum B** — cPanel staging → cloud production, and exactly which features work where |
| 14 | [Payment Gateway Architecture](14-payment-gateway-architecture.md) | **Owner Addendum D** — multi-gateway, adapter-based payments; Razorpay is the default, not the only one |

## The short version

**What is being built.** A multi-provider AI SaaS platform in PHP/Laravel where customers reach
many AI providers through one interface, and where the owner controls branding, themes, content,
providers, models, routing, pricing, credits and limits from an Admin Panel — without a developer.

**Confirmed stack** — every version verified live against Packagist, all mutually compatible:

| | |
|---|---|
| PHP | 8.4.19 *(blueprint asks 8.3+)* |
| Laravel | 13.31 |
| Frontend | Blade + Livewire 4 + Alpine.js + Tailwind |
| Admin | Filament 5.8 *(same stack — pending decision D-02)* |
| Permissions | Spatie Laravel Permission 8.3 |
| Database | MySQL 8+ |
| Cache/queue | Redis, with database fallback for basic hosting |

**Scale:** 68 tables · 15 modules · ~90 admin screens · 5 adapter types · 8 routing modes ·
8 built-in themes · ~95 design tokens per mode · 6 breakpoints across 3 device classes.

**Timeline:** 32–45 working sessions. A usable branded AI chat platform exists at Phase 4
(~15–21 sessions); it earns revenue at Phase 6 (~22–31).

**Every interface is device-adaptive** — mobile, tablet and desktop get genuinely different
layouts, not one layout resized. See [document 11](11-responsive-design-system.md).

## The blueprint's ten rules, and where each is enforced

| Rule | Enforced by |
|---|---|
| 1 · Laravel/PHP, no silent switch to Node | Stack fixed in Phase 0; server runs PHP only — see `02` |
| 2 · No feature removal without approval | The register in `01` — every section has a row |
| 3 · Module-by-module, no giant untested dump | 10 phases, each with a test gate — `09` |
| 4 · Admin-configurable wherever practical | `system_settings` + typed registry — `03` §7 |
| 5 · No hard-coded "latest model" list | DB-driven catalog + sync — `07` §6 |
| 6 · Keys server-side and encrypted | Encrypted credentials, never serialised — `07` §5 |
| 7 · Free tiers respected, limits never bypassed | Per-credential tracking, no key-cycling — `07` §7 |
| 8 · Sensitive admin actions gated + logged | Mandatory 3-step pattern — `06` §2 |
| 9 · Report done / failed / remaining each module | Every phase ends in that exact report — `09` |
| 10 · Backwards compatibility + migrations | Additive migrations only — `04` |

## What has *not* been decided

Status is tracked in [document 12](12-decision-log.md). As of now:

- ✅ **D-01 approved** (amended by Addendum D) — India. **Multi-gateway payments** with Razorpay as
  the initial default; INR primary, with dated USD→INR rates for margin reporting
- ✅ **D-04 approved** — cPanel shared hosting for dev/staging, cloud/VPS for production, under
  nine portability requirements. Environment is configuration, not architecture
- ✅ **D-11 approved** — mobile bottom navigation, kept admin-configurable
- 🔴 **E-1 … E-8** — cPanel account facts needed before Phase 0. **E-1 (PHP ≥ 8.2)** and
  **E-2 (outbound HTTPS allowed)** are hard blockers
- 🔴 **D-12 · GST handling** — raised by D-01; three questions to answer
- 🟡 Eight further decisions carry a recommendation and await confirmation

**No application code is written until E-1/E-2 are confirmed.**
