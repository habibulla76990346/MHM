# Aziv AI — Project & Application Architecture

## 1. Architectural style: modular monolith

Aziv AI is built as **one Laravel application divided into clearly separated internal modules**,
not as microservices.

Why this is the right choice here:

- It deploys as a single application to ordinary PHP hosting — which is the blueprint's stated
  deployment constraint (§2, §29).
- Module boundaries are enforced in code, so any single module (say, Image Generation) can later
  be extracted into its own service without a rewrite.
- One database, one deployment, one set of logs. For a business owner who is not a developer,
  operational simplicity is a feature, not a compromise.

Microservices would multiply hosting cost and operational burden with no benefit at this scale.

## 2. The request lifecycle

This is blueprint §1 and §32 turned into named components. Every arrow is real code.

```
   Browser
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  HTTP MIDDLEWARE STACK                                              │
│  MaintenanceMode → Authenticate → VerifyEmail → EnforceSessionLimit │
│  → ResolveTheme → ResolveLocale → RateLimit → FeatureFlag           │
└─────────────────────────────────────────────────────────────────────┘
      │
      ▼
┌──────────────────┐     the user asked for something AI-powered
│  Chat / Image /  │
│  Audio / File    │
│  Controller      │
└──────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. EntitlementService                                              │
│     Is this user's plan allowed to use this capability at all?      │
│     Are they inside their message / token / image / storage limit?  │
└─────────────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. CreditService — PRE-AUTHORISATION                               │
│     Estimate worst-case cost, place a HOLD on that many credits.    │
│     Insufficient balance → stop here, nothing is spent.             │
└─────────────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. CapabilityResolver                                              │
│     What does this request actually need? text / vision / image /   │
│     audio-in / audio-out / embeddings / tool-use / reasoning        │
└─────────────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. AiRouter                                                        │
│     Build candidate list → filter → score by routing mode → order   │
│     Records the decision and its reasoning before dispatching       │
└─────────────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  5. ProviderAdapter (one per provider family)                       │
│     Translates Aziv's normalised request into that provider's own   │
│     wire format, and translates the answer back.                    │
└─────────────────────────────────────────────────────────────────────┘
      │
      ▼
   AI Provider (OpenAI / Gemini / Anthropic / …)
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  6. Response / Stream  →  delivered to the user as it arrives       │
└─────────────────────────────────────────────────────────────────────┘
      │
      ▼
┌─────────────────────────────────────────────────────────────────────┐
│  7. UsageRecorder    real tokens, real latency, real provider cost  │
│  8. CreditService — SETTLEMENT                                      │
│     Convert actual usage to credits, deduct, release the hold.      │
│     Call failed → release the hold, charge nothing.                 │
│  9. Persistence      message, usage row, routing log, health sample │
└─────────────────────────────────────────────────────────────────────┘
```

### Why pre-authorisation exists (the credit hold)

This is the single most important detail in the billing design, and it is not obvious.

When an AI response is **streamed**, the text arrives a few words at a time and you do not know
the final size until it finishes. If credits were only deducted at the end, a user could open
many streams at once and run the balance far below zero before any of them completed. On a
platform selling credits, that is a direct revenue leak.

So Aziv AI **reserves** the maximum plausible cost before the call starts, then **reconciles** to
the true cost when it ends and returns the difference. The user's spendable balance is
`confirmed_balance − active_holds`. Overspend becomes structurally impossible rather than
merely unlikely.

## 3. Layer map

| Layer | Responsibility | What lives here |
|---|---|---|
| **HTTP** | Translate web requests into domain calls. No business logic. | Controllers, Form Requests, Middleware, Livewire components |
| **Domain services** | All business rules. Testable without a browser. | `AiRouter`, `CreditService`, `EntitlementService`, `ThemeService`, `SettingsService` |
| **Adapters** | Talk to the outside world behind an interface Aziv defines. | Provider adapters, payment gateways, storage, mail |
| **Persistence** | Data only. | Eloquent models, migrations, query builders |
| **Jobs** | Anything slow or retryable. | Model sync, file extraction, embeddings, image generation, emails |

The rule that keeps this honest: **a controller may not call an AI provider, and an adapter may
not know what a subscription is.** Each layer only talks to the one beneath it.

## 4. Directory structure

```
app/
├── Domains/
│   ├── Ai/
│   │   ├── Adapters/          OpenAiAdapter, GeminiAdapter, AnthropicAdapter,
│   │   │                      OpenAiCompatibleAdapter, CustomHttpAdapter
│   │   ├── Contracts/         ProviderAdapter, SupportsChat, SupportsVision,
│   │   │                      SupportsImageGeneration, SupportsEmbeddings,
│   │   │                      SupportsTranscription, SupportsSpeech,
│   │   │                      SupportsModelDiscovery
│   │   ├── DTO/               ChatRequest, ChatChunk, ChatResponse,
│   │   │                      UsageMetrics, ProviderError, TestResult
│   │   ├── Routing/           AiRouter, CandidateBuilder, ScoringStrategies,
│   │   │                      CapabilityResolver, FallbackChain
│   │   ├── Health/            CircuitBreaker, HealthProbe, HealthRecorder
│   │   ├── Sync/              ModelSyncService, SyncScheduler
│   │   ├── Models/            AiProvider, AiProviderCredential, AiModel, …
│   │   └── Services/          ProviderRegistry, CredentialResolver, UsageRecorder
│   ├── Billing/               Plans, Subscriptions, Payments, Credits, Coupons
│   ├── Chat/                  Conversations, Messages, Streaming, Personas
│   ├── Files/                 Upload, Extraction, Chunking, KnowledgeBase, Retrieval
│   ├── MediaAi/               Image generation
│   ├── Audio/                 Speech-to-text, text-to-speech
│   ├── Identity/              Users, profiles, OAuth, sessions, MFA
│   ├── Security/              Roles, permissions, audit log, rate limiting
│   ├── Theming/               Themes, tokens, CSS compilation, preview
│   ├── Branding/              Logos, favicons, media library
│   ├── Content/               Pages, sections, banners, FAQ, menus, SEO
│   ├── Settings/              SettingsService, typed registry, feature flags
│   ├── Notifications/         Templates, announcements, delivery
│   └── Analytics/             Cost, revenue, margin, usage aggregation
├── Filament/                  Admin panel resources, pages, widgets
├── Http/                      Controllers, middleware, form requests
├── Livewire/                  Customer-facing interactive components
└── Providers/                 Service container wiring
```

Each domain folder is self-contained: its models, services, jobs, events and policies sit
together. To understand billing, you open one folder.

## 5. Two front doors, one application

| | Customer application | Admin panel |
|---|---|---|
| URL | `aziv.example.com` | `aziv.example.com/admin` |
| Built with | Blade + Livewire + Alpine + Tailwind | Filament 5 (which is itself Blade + Livewire + Alpine + Tailwind) |
| Styled by | The DB-driven theme system — fully admin-controlled | Filament's own admin styling, with Aziv branding applied |
| Guard | `web`, standard users | `web` + admin-role check + optional MFA |

The customer-facing theme engine (blueprint §5) controls the **customer** application. The admin
panel receives your logo and brand colours but is not itself re-skinnable into eight themes —
that would be a large amount of work delivering value to a handful of staff accounts. This is
flagged as decision **D-07** rather than silently decided.

## 6. Where Filament fits, and why

Blueprint §3–§27 describe roughly **90 distinct admin screens**. Building each by hand means
writing the same list/filter/search/paginate/form/validate/save code ninety times.

Filament 5 is a Laravel admin framework built on precisely the stack the blueprint specifies:
Blade, Livewire, Alpine and Tailwind. It provides tables, forms, validation, file uploads,
relationship pickers and dashboard widgets as reusable building blocks, and it integrates
directly with `spatie/laravel-permission` for the granular role control §9 requires.

Using it cuts the admin panel from months to weeks, and every screen is still ordinary Laravel
code that any Laravel developer can read.

**The honest trade-off:** Filament is a significant dependency. Major version upgrades require
a migration effort every year or two, and its visual style is its own. The alternative —
hand-building — gives total control and no dependency, at several times the cost and with more
bugs, because well-tested framework code is being replaced with new untested code.

Recommendation: **use Filament.** Recorded as decision **D-02** for you to confirm or reject.

## 7. Configuration model — how "admin controls everything" actually works

Blueprint Rule 4 requires application settings to be admin-configurable. Three tiers exist, and
being clear about which is which is what §28 demands:

| Tier | Stored in | Changed by | Examples |
|---|---|---|---|
| **Infrastructure** | `.env` on the server | Developer / hosting access | `APP_KEY`, database credentials, Redis host, S3 keys, mail transport host |
| **Application** | `system_settings` table | **You, in the Admin Panel, instantly** | Branding, themes, content, routing defaults, credit costs, limits, feature flags, plan pricing, retention rules |
| **Operational data** | Domain tables | You, in the Admin Panel | Providers, models, plans, coupons, users, knowledge bases |

The Admin Panel will **display** infrastructure settings as read-only, clearly marked
"set on the server" — so you always know what a setting is, even when you cannot change it there.
The blueprint explicitly asks for this honesty rather than a panel that pretends to control
things it cannot (§28).

### The settings service

```php
settings('branding.app_name');              // typed, cached, one query per request cycle
settings()->set('ai.default_routing_mode', 'lowest_cost');   // validated + audit-logged
```

Every setting is declared in a registry with a type, validation rule, default, permission and
"is secret" flag. The entire table is cached as one blob and invalidated on write, so having
several hundred settings costs one cache read per request, not several hundred queries.

## 8. Failure and safety principles

| Principle | Implementation |
|---|---|
| A failed AI call never costs the user credits | Hold is released; ledger is untouched |
| A provider outage never takes the platform down | Circuit breaker opens, router picks the next healthy candidate |
| A fallback never silently downgrades capability | Capability guard: a vision request cannot fall back to a text-only model |
| A duplicate payment webhook never double-credits | Unique constraint on provider event id; replays are no-ops |
| A credit balance can never go negative | Holds + row-level locking inside a transaction |
| A secret never reaches the browser | Encrypted at rest, never serialised into any response or view payload |
| A sensitive admin action is never invisible | Policy check + audit log entry, both mandatory |
| A bad theme never bricks the site | Preview before publish; one-click restore of the previous active theme |
