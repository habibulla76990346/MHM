# Aziv AI — Admin Panel Architecture

Blueprint §3: *"the operational control center… administrators should be able to configure the
platform without routine code changes."*

## 1. Structure — 12 sections, ~90 screens

The panel lives at `/admin`, built with Filament 5 (Blade + Livewire + Alpine + Tailwind — the
exact stack blueprint §2 specifies).

```
DASHBOARD          Live KPIs, cost vs revenue, provider health, recent activity

BRANDING           Logos (primary, dark, light, compact, login, email)
                   Favicon & app icons · Company name, short name, title, tagline
                   Support email/phone · Website & social links · Footer & legal text
                   Default avatar & placeholders · Per-page branding toggles
                   Media library (upload, replace, preview, safe delete)

APPEARANCE         Theme list (Light, Dark, Midnight, Professional, Minimal,
                     Glass, Ocean, Custom)
                   Create · Duplicate · Rename · Activate · Deactivate · Delete
                   Colour editor — all token groups, light + dark side by side
                   Component styling — sidebar, navbar, buttons, inputs, cards,
                     tables, badges, modals, chat bubbles, code blocks
                   Typography · Border radius · Shadow intensity · Spacing scale
                   Custom CSS (permission-gated + sanitised)
                   Live preview · Publish · Restore previous theme

CONTENT            Homepage sections (hero, features, benefits, providers,
                     pricing, FAQ) · Pages · Banners & announcements with
                     scheduling and priority · FAQ manager · Navigation menus
                   SEO defaults & social preview · Contact/support content

USERS              List, search, filter by status/plan/date
                   Activate · Suspend · Verify · Restrict
                   View subscription, credits, usage, sessions, metadata
                   Change plan · Adjust credits (reason required)
                   Impersonate (permission-gated, always audit logged)

ROLES              Roles list · Create custom roles
                   Permission matrix across 11 domains · Deny-by-default
                   Admin users · MFA enforcement

AI PROVIDERS       Provider list with live health indicators
                   Add/edit/disable · Credentials (masked) · Test connection
                   Budgets & threshold actions · Rate limits · Fallback order
                   Maintenance mode · Circuit breaker state + manual reset
                   Custom provider mapping builder

AI MODELS          Catalog with filters (provider, status, capability, modality)
                   Refresh Models · Sync schedule · Sync logs
                   Enable/disable globally or per plan
                   Capability flags · Context/output limits
                   Pricing: provider cost + credit price, with effective dates

ROUTING            Default routing mode · Provider priority order
                   Routing rules · Fallback depth · Retry & backoff settings
                   Circuit breaker thresholds · Routing decision log viewer

BILLING            Plans (price, currency, cycle, all limits)
                   Plan → model/provider access matrix
                   Coupons & promotions · Invoices · Payments · Refunds
                   Credit ledger viewer · Manual credit grants
                   Payment gateway configuration · Tax display settings

ANALYTICS          Cost by day/month/provider/model/plan
                   Revenue, subscription sales, credit consumption
                   Cost vs revenue and margin indicators
                   Budget alerts · Usage trends · Provider performance

SYSTEM             Site status & maintenance mode + message + page
                   Locale, timezone, currency, pagination, table density
                   Upload limits & retention · SMTP & email templates
                   Storage driver · Queue configuration
                   Feature flags · Emergency kill switches
                   Activity log viewer · API test console
```

## 2. Three controls on every sensitive action

Blueprint Rule 8 requires permission checks and audit logging on sensitive admin actions. This is
implemented as a mandatory pattern, not a per-screen decision:

```php
// 1. PERMISSION — deny by default
$this->authorize('ai.credentials.update', $credential);

// 2. VALIDATION — typed, bounded, with a safe default
$data = $request->validated();

// 3. AUDIT — before/after, actor, IP, always
activity()->on($credential)
          ->withProperties(['before' => $before, 'after' => $after])
          ->log('ai.credential.updated');
```

Any admin write that skips step 1 or step 3 is treated as a **bug**, and Phase 9's security review
checks for exactly that.

## 3. Permission model (§9)

Permissions are `domain.resource.action`, grouped into the 11 domains §9 names:

```
users.*        providers.*     credentials.*   models.*      routing.*
billing.*      credits.*       content.*       themes.*      analytics.*
security.*     logs.*          settings.*
```

### Default role matrix

| Domain | Super Admin | Admin | Support Manager | Finance Manager | Content Manager |
|---|:--:|:--:|:--:|:--:|:--:|
| Users — view | ✅ | ✅ | ✅ | ✅ | — |
| Users — suspend/restrict | ✅ | ✅ | ✅ | — | — |
| Users — adjust credits | ✅ | ✅ | limited | ✅ | — |
| Providers — view | ✅ | ✅ | — | — | — |
| **Credentials — any access** | ✅ | ✅ | **❌ denied** | **❌ denied** | **❌ denied** |
| Models — manage | ✅ | ✅ | — | — | — |
| Routing — configure | ✅ | ✅ | — | — | — |
| **Billing — configure** | ✅ | ✅ | **❌ denied** | ✅ | **❌ denied** |
| Invoices/payments — view | ✅ | ✅ | view only | ✅ | — |
| Content & themes | ✅ | ✅ | — | — | ✅ |
| Analytics | ✅ | ✅ | limited | ✅ | — |
| Security & logs | ✅ | limited | — | — | — |
| System settings | ✅ | limited | — | — | — |

The two bold rows are explicit blueprint requirements: *"A support role must not automatically
gain access to API credentials or financial configuration"* (§9). They are enforced by policy, so
even a mis-configured custom role cannot grant credential access without a Super Admin
deliberately doing so.

**Deny-by-default** means a new permission added in a later phase is unavailable to every role
until granted. The system never silently widens access as it grows.

## 4. Admin-control matrix (§27) — where each control lives

| Domain | Controls | Panel section |
|---|---|---|
| Branding | logo, favicon, name, title, tagline, contact, social | BRANDING |
| Design | themes, colours, typography, buttons, cards, sidebar, dark/light, radius, shadows | APPEARANCE |
| Content | homepage, banners, announcements, FAQs, footer, legal | CONTENT |
| AI | providers, credentials, models, capabilities, routing, fallbacks, budgets, pricing, health | AI PROVIDERS / AI MODELS / ROUTING |
| Users | status, plans, credits, limits, roles, permissions | USERS / ROLES |
| Billing | plans, prices, coupons, payment config, invoices | BILLING |
| Files & media | upload rules, storage, retention, knowledge bases | SYSTEM / CONTENT |
| Analytics | user, AI, provider, cost, revenue, performance | ANALYTICS |
| Security | sessions, rate limits, MFA, logs, maintenance, kill switches | ROLES / SYSTEM |
| System | localisation, timezone, currency, email, storage, queue, cache | SYSTEM |

Every row of §27 has a home. None was dropped.

## 5. Honest limits, shown in the panel (§28)

The blueprint asks that the panel *"clearly indicate that limitation rather than pretending it
can control it."* Implementation: infrastructure-level settings appear in the panel as
**read-only cards** showing the current value, where it is set, and what changing it requires.

Example, on the System → Infrastructure screen:

> **Queue driver:** `redis` — set in server configuration (`.env`)
> Changing this requires hosting access. [What this means]

This way you can always see how your platform is configured, and you are never misled into
thinking a field will do something it cannot.

Settings in this category: `APP_KEY`, database connection, Redis host, S3 credentials, mail
transport host/port, PHP memory and execution limits, cron/scheduler registration, queue worker
supervision, SSL certificates.

## 6. API test console (§25)

A single admin screen to answer *"is this provider actually working?"*:

- Pick a provider and model
- Send a controlled test request (bounded token count, so a test cannot become expensive)
- See: HTTP status, latency in ms, normalised error class, token usage, estimated cost
- Buttons: **Test credential** · **Refresh models** · **Reset circuit breaker**
- **The full secret is never shown** — masked to the last 4 characters, per §25

This is deliberately a first-class feature rather than a debugging afterthought: for a
non-developer owner, it is the difference between "the AI is broken" and "the Gemini key expired
on Tuesday."

## 7. Emergency controls (§24)

When something goes wrong at 2am, these are reachable in two clicks:

| Switch | Effect |
|---|---|
| **Maintenance mode** | Site shows your configured maintenance page; admins retain access |
| **Kill provider** | One provider disabled instantly; router routes around it |
| **Kill feature** | Chat / image / audio / file upload disabled independently |
| **Kill registration** | Stop new signups without affecting existing users |
| **Global rate limit** | Tighten platform-wide limits under load or abuse |
| **Force circuit open** | Manually take a misbehaving provider out of rotation |

Each is a feature flag or setting — instant, audit logged, and reversible from the same screen.
