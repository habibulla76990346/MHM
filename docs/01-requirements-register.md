# Aziv AI — Requirements Register (Traceability)

Source: *Aziv AI FINAL Master Blueprint — Admin Control / Laravel*, 8 pages, revision 11 September 2026.

**Purpose of this file.** Blueprint Rule 2 says features must not be removed without explicit
approval. This register lists **every** numbered section of the blueprint and maps it to the
module and phase that delivers it. If a row has no phase, it has been dropped — no row is
allowed to be empty.

Status legend: `PLANNED` = in scope, scheduled. `PARTIAL` = in scope but limited by a real
constraint (limit named in the Notes column). `INFRA` = not application-level; requires hosting
or a provider dashboard (blueprint §28 acknowledges this class).

| § | Blueprint requirement | Module | Phase | Status | Notes |
|---|---|---|---|---|---|
| 1 | Project vision: centralised multi-AI SaaS; auth → plan/credit → cache/context → routing → provider → response → usage/cost → storage | All | 1–9 | PLANNED | Flow is the spine of the architecture doc |
| 2 | Stack: PHP 8.3+, latest stable Laravel, Blade+Livewire+Alpine, Tailwind, MySQL 8+, Redis, S3-compatible, no Node dependence in core app | Foundation | 0–1 | PLANNED | Node used at **build time only**; see risk R-02 |
| 3 | Admin Panel as master control centre; labels, validation, safe defaults, permissions, audit logging, masked secrets | Admin | 2 | PLANNED | Cross-cutting; every admin write is audited |
| 4 | Branding: primary/dark/light/compact/login/email logo, favicon, app icons, names, tagline, contact, social, footer, default avatar, per-page branding toggles, media library | Media + Settings | 2 | PLANNED | — |
| 5 | Multi-theme + complete colour control; 8 built-ins; create/duplicate/rename/activate/delete; design tokens; ~17 colour roles; component-level controls; separate light/dark; custom CSS; preview + restore; radius/shadow/spacing/typography | Theming | 2 | PLANNED | Custom CSS sanitised + permission-gated |
| 6 | UI/UX control: sidebar labels/visibility/order, dashboard widgets, landing sections, banners, announcements, pagination, table density, date/time, locale, timezone, currency, maintenance mode, feature flags | Content + Settings | 2 | PLANNED | — |
| 7 | Homepage & content management: hero, features, benefits, providers, pricing, FAQ, banners w/ scheduling+priority, footer/legal links, SEO + social preview, contact content | Content | 2 | PLANNED | Rendered through a sanitising pipeline |
| 8 | User & account management: search/filter, activate/suspend/verify/restrict, view subscription/credits/usage, change plan, adjust credits with reason, registration + verification + password policy, OAuth, session limits, idle timeout, rate limits | Identity | 1–2, 6 | PLANNED | Credit adjust lands in the ledger with a reason |
| 9 | Roles & permissions: 5 suggested roles, custom roles, granular permissions across 11 domains, deny-by-default, support role must not reach credentials/finance, log role changes | Security | 1 | PLANNED | Deny-by-default enforced via policies |
| 10 | Universal AI Provider Manager: add/edit/disable/test; 11 provider fields; official + generic OpenAI-compatible adapters; custom HTTP mappings; encrypted server-side credentials; multi-credential rotation; budgets, rate limits, fallback, maintenance status | AI | 3 | PLANNED | Rotation for redundancy only — see Rule 7 / risk R-05 |
| 11 | Model catalog & auto-sync: no hard-coded latest model, provider discovery, manual add, 10 model fields, 5 statuses, enable/disable globally or per plan, Refresh Models + scheduled sync, sync logging | AI | 3 | PLANNED | Manual fallback where discovery is absent |
| 12 | Providers: OpenAI + Gemini initially; Claude, DeepSeek, Mistral, Groq recommended; OpenRouter/HuggingFace optional; xAI, Cohere, Perplexity, Together, Replicate, Fireworks, Cerebras adapter-ready | AI | 4, 7 | PLANNED | Blueprint itself rejects "every API supported" claim |
| 13 | Free + paid API management: account classification, 6 billing classifications, provider cost tracked separately from customer price, credit costs per model/capability, promotional allowances, respect free-tier quotas, never circumvent limits | Billing + AI | 3, 5, 6 | PLANNED | Cost vs price are two distinct columns |
| 14 | Smart AI Router: 10 routing inputs, 8 routing modes, capability-aware fallback, retry w/ backoff, circuit breaker, log every decision | AI | 5 | PLANNED | Capability guard prevents text-only fallback on vision jobs |
| 15 | Chat: new/history/search/rename/delete, streaming, stop, regenerate, copy, feedback, manual + auto model choice, context limits, admin persona/system prompt, max length, attachment + rate limits, retention rules | Chat | 4 | PLANNED | Streaming needs VPS — risk R-01 |
| 16 | Image generation: text-to-image, provider/model choice, credits, history, status, prompt history, regeneration, admin controls, ready for edit/bg-removal/upscale | Media AI | 8 | PLANNED | Edit/upscale = adapter-ready, not built in v1 |
| 17 | File analysis & knowledge base: upload→validate→store→extract→scan→RAG→respond, admin file rules, retention, storage limits, plan access, embeddings/vector module, knowledge bases w/ access + retrieval settings, RBAC on sensitive files | Files | 8 | PARTIAL | Vector store + malware scan need decisions D-03/D-08 |
| 18 | Voice/audio/future video: STT → AI → TTS, admin controls providers/models/quotas/credits/availability, realtime as separate module, video adapter-ready | Audio | 8 | PARTIAL | Realtime voice + video explicitly deferred by blueprint |
| 19 | Subscription & credit control: FREE/PRO/PREMIUM configurable, admin controls 11 plan dimensions, ledger records grants/deductions/refunds/adjustments w/ reason, promotional credits, expiry, optional rollover, idempotent webhooks | Billing | 6 | PLANNED | Idempotency enforced by unique webhook event id |
| 20 | Payment & billing: purchase/upgrade/downgrade/renewal/cancel/history/records, gateway chosen by country+compliance, admin controls pricing/coupons/tax display/invoices, never store raw card data | Billing | 6 | PLANNED | Gateway is pluggable — decision D-01 |
| 21 | Cost & profit analytics: daily/monthly/provider/model/plan cost, revenue, credit consumption, margin, budget alerts, threshold actions (alert / restrict model / reroute cheaper / disable provider) | Analytics | 5–6 | PLANNED | Threshold actions wire into the router |
| 22 | Notifications: in-app + email, announcements/offers/maintenance/reminders/updates, templates w/ subject+content+status+audience, push-ready | Notifications | 6 | PLANNED | Push = interface ready, not shipped in v1 |
| 23 | Security & audit: hashing, secure sessions/tokens, CSRF, validation, rate limiting, API protection, encrypted credentials, least privilege, optional MFA for admins, activity logs across 9 domains, backup/recovery, monitoring, pre-launch security testing | Security | 1, 9 | PLANNED | Hardening concentrated in Phase 9 |
| 24 | Admin system settings: site status, maintenance, locale, timezone, currency, pagination, upload limits, retention, SMTP, notifications, support config, app URLs, storage driver, queue config, default routing mode + provider priority + model policy, global limits, kill switches, feature flags | Settings | 2, 5 | PLANNED | Queue/storage driver selection is app-level; provisioning is INFRA |
| 25 | API/integration test console: admin-only, pick provider/model, controlled test request, status/latency/error class/usage/cost, never reveal full secret, connection-test + model-sync buttons | Admin + AI | 3 | PLANNED | Secrets shown masked (last 4 only) |
| 26 | Database master modules: 6 groups of tables enumerated | Data | 1–8 | PLANNED | Expanded to ~62 tables in `04-database-architecture.md` |
| 27 | Admin-control matrix: 10 control domains | Admin | 2–9 | PLANNED | Matrix reproduced in `06-admin-panel-architecture.md` |
| 28 | Honest limits: infra changes need hosting access, provider credentials need provider dashboards, deep features need a coding session, panel must separate app-level from infra-level | Docs + Admin | All | PLANNED | Panel labels INFRA settings as read-only guidance |
| 29 | Deployment & ownership: owner controls domain/hosting/DB/storage/provider accounts/gateway/repo; dev+staging+prod; env docs, migrations, deployment + API docs; move to VPS/cloud for concurrency/streaming/queues/large files | Ops | 0, 9 | PLANNED | Ownership checklist in `10-decisions-and-risks.md` |
| 30 | Development order: 9 named phases, each tested before the next | Process | 1–9 | PLANNED | Kept verbatim; a Phase 0 prep step is added ahead of them |

## Owner addenda — requirements added after the original blueprint

These were added by the project owner after the blueprint was issued. They carry the same
weight as blueprint sections and are subject to the same no-removal rule.

| ID | Requirement | Module | Phase | Status | Notes |
|---|---|---|---|---|---|
| A-1 | **Fully responsive and device-adaptive from the beginning.** Mobile must feel like a modern AI mobile app, not a shrunken desktop site. Mobile-first touch-friendly forms; drawers, bottom navigation, bottom sheets, sticky actions, responsive chat composer, mobile-friendly tables/cards. No horizontal overflow. Inputs, buttons, dropdowns, modals and validation usable with mobile keyboards and touch. | All UI | **Cross-cutting, 0–9** | PLANNED | Full spec: `11-responsive-design-system.md` |
| A-2 | Desktop must feel like a professional SaaS application — sidebars, top navigation, multi-column layouts, dashboards, tables, filters, wide workspaces | All UI | Cross-cutting | PLANNED | §3–§4 of the responsive spec |
| A-3 | **Do not simply shrink the desktop UI.** Proper distinct layouts at mobile/tablet/desktop; components adapt layout, spacing, navigation and interaction by screen size | All UI | Cross-cutting | PLANNED | Adaptive ≠ responsive — §1 of the spec |
| A-4 | Test important screens at mobile, tablet and desktop widths | QA | Every phase | PLANNED | **Automated** via Playwright at 6 viewports — §11 |
| A-5 | Keep the design system consistent across all breakpoints | Theming | 1–2 | PLANNED | Responsive token layer — §7 |
| A-6 | **Admin Panel must also be fully mobile responsive** | Admin | 2–9 | PARTIAL | Every screen usable on mobile; 3 dense surfaces are comfort-optimised for desktop and signposted — §9 |
| A-8 | **Mobile bottom navigation = Chat · Library · Images · Account**, less frequent features under More/Drawer. Navigation must stay admin-configurable in future: labels, icons, ordering, visibility and destination where technically appropriate. Bottom bar must stay touch-friendly and must not interfere with the chat composer, keyboard, safe-area insets or scrolling | Content, Theming | 1–2 | PLANNED | **Owner-approved D-11.** Navigation is data, not code — `11-responsive-design-system.md` §3 |
| A-7 | PWA-ready structure: manifest, icons, installability where appropriate. **Not a native app at this stage** | Branding, Ops | 2, 9 | PLANNED | Manifest generated from admin branding settings — §10 |

### Owner Addendum B — deployment portability (decision D-04)

| ID | Requirement | Where delivered |
|---|---|---|
| B-1 | Laravel application stays hosting-provider agnostic | `13-deployment-portability.md` §1 |
| B-2 | Nothing hard-coded to the shared hosting provider | §1, enforced in every phase review |
| B-3 | Database, cache, queue, storage, mail, AI providers all configurable via environment | §1 config table |
| B-4 | Database-backed queue where no persistent worker exists | §3 — cron-driven bounded worker |
| B-5 | Ready to switch to Redis, persistent workers and streaming infrastructure | §1, §4 |
| B-6 | **No feature removed or downgraded because of shared-hosting limits** | §2 — every feature built in both environments |
| B-7 | Document what is fully functional on shared hosting vs what needs cloud/VPS | §2 capability matrix |
| B-8 | Final production architecture supports streaming, queues, background jobs, file processing, image generation, voice, scaling, monitoring | §2, Phase 9 |
| B-9 | Migration as simple as configuration change, data migration and deployment steps | §4 — a 10-step checklist, zero code changes |

### Owner Addendum C — Indian business requirements (decision D-01)

| ID | Requirement | Where delivered |
|---|---|---|
| C-1 | Razorpay as the first payment gateway implementation | Phase 6; gateway stays pluggable |
| C-2 | INR as default currency; money precision set accordingly | Phase 1 schema |
| C-3 | **USD provider cost vs INR revenue** reconciled with dated exchange rates | `04-database-architecture.md` — `exchange_rates` |
| C-4 | GST-compliant invoicing: GSTIN, place of supply, CGST/SGST/IGST split, SAC code, sequential numbering | Phase 6 — pending decision **D-12** |

### Owner Addendum D — multi-gateway payment architecture (amends D-01)

Razorpay is the **initial default** gateway, not the only one. Full design in
[`14-payment-gateway-architecture.md`](14-payment-gateway-architecture.md).

| ID | Requirement | Where delivered |
|---|---|---|
| PG-1 | Modular, provider-agnostic, **adapter-based** gateway architecture | §1–§2 — mirrors the AI provider manager |
| PG-2 | Architecture supports Razorpay, PhonePe, PayU, Cashfree, CCAvenue | §2 — one adapter each, capabilities declared as data |
| PG-3 | Additional gateways later **without changing core subscription/payment architecture** | §9 — new adapter class + a database row |
| PG-4 | Admin: enable/disable any gateway | §7.1 |
| PG-5 | Admin: set default gateway | §7.2 |
| PG-6 | Admin: configure credentials securely | §7.3, §5 — encrypted, masked, permission-gated |
| PG-7 | Admin: test gateway connection | §7.4 — never reveals the secret |
| PG-8 | Admin: set priority/order | §7.5 |
| PG-9 | Admin: enable by country/currency | §7.6 |
| PG-10 | Admin: which payment types use which gateway | §7.7 — `payment_gateway_rules` |
| PG-11 | Admin: view transaction status | §7.8 |
| PG-12 | Admin: successful/failed/pending/refunded views | §7.9 |
| PG-13 | Admin: webhook configuration & status | §7.10 |
| PG-14 | Admin: sandbox/test/live mode | §7.11 — separate credentials per mode |
| PG-15 | Admin: gateway-specific settings stored securely | §7.12 — encrypted `extra_config`, no schema change per gateway |
| PG-16 | Consistent customer checkout experience across gateways | §2 — checkout modes wrapped in one Aziv-branded flow |
| PG-17 | **No Razorpay-specific logic in subscriptions, plans, invoices, transactions** | §8 — a gateway name in those components is a defect, checked in Phases 6 and 9 |
| PG-18 | Never store secret credentials in frontend code | §5 — publishable identifiers distinguished from secrets |
| PG-19 | Credentials encrypted / securely stored | §5 |
| PG-20 | **Webhook verification for every gateway** | §4.4 — raw-body signature verification; an adapter that cannot verify does not ship |
| PG-21 | Payment status reconciled **server-side** | §4.1 — return callback, webhook and scheduled sweep converge on one handler |
| PG-22 | Prevent duplicate payment processing and duplicate subscription activation | §4.2–§4.3 — idempotency at four levels plus row-level locking |
| PG-23 | Complete payment/audit logs | §7 — transaction timeline; every admin action audit-logged |
| PG-24 | **Gateway transaction IDs alongside internal Aziv transaction ID** | §6 — every payment record carries both |
| PG-25 | INR primary initially, architecture ready for more currencies | §3, §9 — nothing assumes a single currency |

### Owner Addendum E — delivery, handover & ownership

Full spec in [`15-delivery-and-handover.md`](15-delivery-and-handover.md).

| ID | Requirement | Where delivered |
|---|---|---|
| DL-1 | **Not locked to any hosting provider**; deployable to any server meeting documented requirements | §1, §7 + Addendum B |
| DL-2 | Complete source-code ZIP + complete Laravel source | §2.1 |
| DL-3 | Database migrations | §2.1 |
| DL-4 | Seeders | §2.1 |
| DL-5 | Clean SQL schema/database export for import | §2.1–2.2 — **generated from migrations, version-stamped** |
| DL-6 | Public/web-root deployment instructions | Guide 4 |
| DL-7 | `.env.example` with **every** variable documented | §2.1 |
| DL-8 … DL-27 | The 20 guides: installation, database, storage, queue/cron, mail, AI providers, payment gateways, tax, admin account creation/reset, production, cPanel, Cloud/VPS, migration, backup/restore, troubleshooting, server requirements, permissions, cron, workers, build commands, security checklist, upgrade | §3 |
| DL-28 | **Do not assume SSH, Supervisor, Redis, Node.js or root** | §4 — web installer, `vendor/` and assets pre-built, cron queue, DB drivers |
| DL-29 | Portable feature implementation with documented shared-hosting mode (A) and Cloud/VPS mode (B) | Addendum B §2 capability matrix |
| DL-30 | Complete, installable, transferable product the owner owns; installable by another developer without the original environment | §1 **handover test**, §7 |

### Owner Addendum F — tax & international billing

Full spec in [`16-tax-and-international-billing.md`](16-tax-and-international-billing.md).

| ID | Requirement | Where delivered |
|---|---|---|
| TX-1 | **GST must not be hard-coded** | §1 Rule 1 — no tax value, rate, label or code in application code |
| TX-2 | Admin controls: enabled/disabled, GSTIN, legal name, address, state, country, place of supply | §2 `tax_settings` |
| TX-3 | Admin controls: CGST, SGST, IGST as **admin-defined components** | §2 `tax_rates` — nothing in code knows these names |
| TX-4 | Admin controls: GST rate, SAC code | §2 — **no rate assumed or pre-filled** |
| TX-5 | Tax-inclusive or tax-exclusive pricing | §2 `pricing_mode` |
| TX-6 | Invoice numbering | §3 — sequences, padding, reset policy, gap-free |
| TX-7 | Tax invoice settings & display settings | §7 |
| TX-8 | Tax rules | §2 `tax_rules` |
| TX-9 | Effective dates | §2 — rates carry `effective_from` / `effective_until` |
| TX-10 | Tax exemptions | §2 `customer_tax_profiles` |
| TX-11 | Customer tax information; business/customer billing details | §2 |
| TX-12 | Tax stored as configurable data, not hard-coded | §1, §2 |
| TX-13 | **No specific rate or treatment assumed** | §2 — seeders ship inactive, unfilled templates only |
| TX-14 | **Historical invoice/tax records unchanged after config changes** | §1 Rule 2 — full snapshot, invoice immutable after issue, corrections via credit note |
| TX-15 | Billing architecture serves Indian **and** international customers | §4 |
| TX-16 | Configurable customer country, billing address | §4 `countries`, `customer_tax_profiles` |
| TX-17 | Configurable currency | §4 `currencies` — including per-currency decimal places |
| TX-18 | Country-specific gateway availability | §4 + Addendum D |
| TX-19 | Per-country tax configuration | §2 `tax_jurisdictions` |
| TX-20 | Invoice information; customer tax/VAT information | §1, §2 |
| TX-21 | International payment methods where supported | §5.1 — configured per what the merchant account actually supports |
| TX-22 | Currency conversion / exchange-rate records | §4 `exchange_rates` |
| TX-23 | **Historical exchange rates** for financial reporting | §4 — dated, never overwritten |
| TX-24 | Country/currency-specific gateway rules; **no India-only assumptions in core billing**; INR initial primary with more currencies supported | §4, §5 |

## Implementation rules (§31) — how each is enforced

| Rule | Requirement | Enforcement mechanism |
|---|---|---|
| 1 | Laravel/PHP primary; no silent switch to Next.js/Node | Stack fixed in Phase 0; no Node runtime on the server; Node only compiles CSS/JS |
| 2 | Blueprint is the baseline; no feature removal without approval | This register; any change needs a row edit and your sign-off |
| 3 | Module-by-module; no huge untested codebase in one step | 10 phases, each with its own test gate and stop point |
| 4 | Admin-configurable wherever practical | `system_settings` + typed settings service; hard-coded values are treated as defects |
| 5 | No hard-coded permanent "latest model" list | Model catalog is DB-driven and sync-fed; code references capabilities, never model names |
| 6 | Keys server-side, encrypted | `ai_provider_credentials` encrypted at rest; never serialised to any frontend payload |
| 7 | Free tiers are not unlimited; do not bypass provider limits | Per-credential quota tracking; rotation is redundancy-only and documented as such |
| 8 | Sensitive admin actions permission-checked + audit logged | Policy gate + `activity_logs` writer on every admin mutation |
| 9 | After each module, run tests and report done/failed/remaining | Every phase ends with a written status report in that exact shape |
| 10 | Backwards compatibility + migrations preserved | Additive migrations only; no destructive edits to shipped tables |

## Final target flow (§32)

```
USER → AUTH → PLAN/CREDIT → CAPABILITY CHECK → AI ROUTER → PROVIDER ADAPTER
     → AI PROVIDER → RESPONSE/STREAM → USAGE/COST → CREDIT LEDGER → STORAGE → USER

ADMIN → BRANDING / THEMES / CONTENT / USERS / PROVIDERS / MODELS / ROUTING
      / BILLING / CREDITS / FILES / ANALYTICS / SECURITY / SYSTEM SETTINGS
```

Every arrow above is a named component in `03-architecture.md`.
