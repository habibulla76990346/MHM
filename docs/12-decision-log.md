# Aziv AI — Decision Log

The single authoritative record of every decision and its status. No decision is settled unless it
appears here as **APPROVED**.

**Nothing in Phase 0 or Phase 1 begins until every item marked `BLOCKS PHASE 0/1` is resolved.**

---

## Status summary

| Status | IDs | Count |
|---|---|---|
| ✅ **APPROVED** | D-01, D-04, D-11 | 3 |
| 🔴 **BLOCKS PHASE 0/1** | E-1 … E-8 (hosting facts), D-12 (GST) | 2 groups |
| 🟡 **Recommended, awaiting confirmation** | D-02, D-03, D-05, D-06, D-07, D-08, D-09, D-10 | 8 |

---

## ✅ Approved decisions

### D-01 · Payment gateway & currency — **APPROVED: India**

**Decision:** business registered in **India**.

**Consequences, now locked into the plan:**

| Item | Resolution |
|---|---|
| **Gateway** | **Razorpay** — strongest local coverage (UPI, netbanking, cards). Stripe's India availability is restricted. The gateway stays behind a pluggable interface, so switching later means one adapter, not a billing rebuild |
| **Default currency** | **INR**, 2-decimal precision for customer-facing money |
| **Provider costs** | Remain **USD** at 6-decimal precision — per-token prices are that small |
| **Margin reporting** | New `exchange_rates` table with **dated** rates. Margin for any period uses the rate effective on each usage date, so past margins never change retroactively when the rupee moves |
| **Tax** | GST applies. Invoice schema extended: GSTIN, place of supply, CGST/SGST/IGST breakdown, SAC code, sequential numbering, export flag |

**Why the currency question was blocking Phase 1:** it sets money-column precision and the default
currency in the schema, and changing those after real payments exist is genuinely painful.

**A new decision this raises: D-12 (GST handling), below.**

---

### D-04 · Hosting — **APPROVED: cPanel shared for dev/staging, cloud/VPS for production**

**Decision:** deploy to existing cPanel shared hosting for development, testing and staging to
minimise initial cost. Shared hosting must **not** become a permanent architectural dependency.
Production later moves to cloud / VPS / managed Laravel.

**All nine owner requirements accepted and recorded** as Owner Addendum B. Full design in
[`13-deployment-portability.md`](13-deployment-portability.md).

The governing principle adopted: **environment is configuration, not architecture.** The test
this must pass — *migrating from cPanel to a VPS is a change of `.env` values, a database import
and a file copy, with zero application code changes.*

| Requirement | How it is met |
|---|---|
| 1 · Hosting-provider agnostic | No provider-specific branches anywhere in application code |
| 2 · Nothing hard-coded to the host | No absolute paths, no hard-coded URLs; enforced in every phase review |
| 3 · Infrastructure configurable via environment | Full config table in §1 of the portability doc |
| 4 · Database queue where no persistent worker exists | cPanel cron running a bounded `queue:work --stop-when-empty --max-time=55` |
| 5 · Ready for Redis, persistent workers, streaming | Same job classes, same code; drivers swap by `.env` |
| 6 · **No feature removed or downgraded** | Every feature built in both environments. The capability matrix records *performance*, never missing functionality |
| 7 · Document what works where | The capability matrix — 27 features rated across both environments |
| 8 · Production supports streaming, queues, jobs, files, images, voice, scaling, monitoring | Phase 9 target architecture |
| 9 · Migration simple | A 10-step checklist, zero code changes |

**What this honestly costs during the shared-hosting period:**

- **Streaming chat will most likely not work properly.** cPanel/LiteSpeed usually buffers output.
  Aziv AI detects this and falls back to a clean complete-answer mode rather than appearing frozen.
  One `.env` change restores real streaming after migration.
- **Background jobs run up to ~60 seconds behind**, because cron ticks once a minute rather than a
  worker running continuously.

Both are properties of the environment, not gaps in the product.

**The risk worth naming:** the temptation, once it works, to keep *production* on shared hosting
because it is cheaper. That would leave streaming — the feature users most associate with a modern
AI product — permanently degraded. This is recorded as a staging decision and the plan treats it
as one.

---

### D-11 · Mobile bottom navigation — **APPROVED**

**Decision:** bottom bar carries **Chat · Library · Images · Account**. Less frequent features
under *More* / the drawer.

**Owner additions accepted:** navigation stays **admin-configurable** (labels, icons, ordering,
visibility, destination) where technically appropriate; the bottom bar must not interfere with the
chat composer, keyboard, safe-area insets or scrolling; Owner Addendum A unchanged.

Navigation becomes **data, not code** — `navigation_menus` / `navigation_items` rows drive every
device class. Design in [`11-responsive-design-system.md`](11-responsive-design-system.md) §3.

**Four honest limits on "where technically appropriate":**

| Limit | Reason |
|---|---|
| Bottom bar capped at **4 items + More** | At 320px, six tabs give ~53px each and seven fall below the 44px touch minimum. The admin chooses *which* four and their order, not how many |
| Items cannot link to destinations that do not exist | Routes validated against the real route list |
| A nav permission **hides a link, it does not grant access** | The destination's own policy governs. A hidden item is not a security control |
| Every route to account settings cannot be hidden at once | Would strand users with no way to manage their subscription. The panel warns and blocks this case |

---

## 🔴 Blocking Phase 0/1

### E-1 … E-8 · cPanel account facts — **BLOCKS PHASE 0**

Decision D-04 makes these specific to the owner's hosting plan, and they change what Phase 0 does.
Most can be read from cPanel in a few minutes.

| # | Check | Why it matters |
|---|---|---|
| **E-1** | **PHP version available** (cPanel → MultiPHP / PHP Selector) | **Hard blocker.** Laravel 13 requires **PHP ≥ 8.2**. Below that, we use Laravel 12 instead |
| **E-2** | **Outbound HTTPS permitted** | **Hard blocker.** Some shared hosts block outbound connections. Every AI provider call depends on this — without it Aziv AI cannot function at all |
| **E-3** | MySQL / MariaDB version | Determines JSON column and index behaviour |
| **E-4** | Cron jobs available | Queues and the scheduler both depend on cron. Without it, background work becomes manual |
| **E-5** | SSH or cPanel Terminal access | Running migrations and cache commands. Without it, a secured web migration runner is added |
| **E-6** | Can the domain's document root be changed? | Security of `.env` and source code. Alternative layout exists if not |
| **E-7** | `upload_max_filesize`, `post_max_size` | Sets admin file-size caps honestly |
| **E-8** | `memory_limit`, `max_execution_time` | Tunes chunk sizes for file processing |

**E-1 and E-2 are true blockers.** The rest adjust the plan rather than stopping it.

---

### D-12 · GST handling — **BLOCKS PHASE 6, decide before Phase 1 schema is final**

Raised by decision D-01. India applies GST to SaaS, and Razorpay — unlike a merchant-of-record
service such as Paddle — **does not handle GST filing on your behalf.** Aziv AI must therefore
produce compliant invoices itself.

What compliant invoicing requires:

| Field | Purpose |
|---|---|
| Supplier GSTIN | Your registration number |
| Customer GSTIN | For B2B sales |
| Place of supply | Determines which tax applies |
| **CGST + SGST** (intra-state) or **IGST** (inter-state) | The split depends on customer location |
| SAC code | Service classification for software services |
| Sequential invoice numbering | Gaps are a compliance problem |
| Export flag | Sales outside India are treated differently |

**What I need from you — three facts:**

1. **Are you GST-registered?** (If turnover is below the threshold you may not be yet.)
2. **Will you sell to customers outside India**, or India only?
3. **Do you have an accountant** who should confirm the tax treatment?

**Recommendation:** build the invoice schema with all GST fields from Phase 1 — they cost nothing
to include and are painful to retrofit — and make tax rules **configurable** via the `tax_rates`
table rather than hard-coded. Then set the actual rates and treatment in Phase 6 once your
accountant confirms them.

> **I am not a tax adviser and will not act as one.** The system will support the fields and
> calculations Indian GST requires; the correct rates, registration status and export treatment
> for your business are for your accountant to confirm. What I can guarantee is that the software
> will not be the thing preventing compliance.

---

## 🟡 Recommended — awaiting confirmation

Silence is not approval. Each proceeds on my recommendation only if you say so.

| ID | Decision | Recommendation | Needed by |
|---|---|---|---|
| **D-02** | Filament for the admin panel | **Yes.** ~90 screens on exactly the blueprint's stack. Trade-off: a major dependency needing upgrades every year or two | Phase 2 |
| **D-03** | Vector storage for document search | **Start with MySQL.** Schema allows swapping the backend later without a rewrite | Phase 8 |
| **D-05** | Launch after Phase 6 or Phase 9 | **After Phase 6.** Nothing is skipped; Phases 7–9 happen with real customers already using it | Phase 6 |
| **D-06** | Tailwind build tooling | **Standard Node build.** Node never touches your server — and with D-04, assets are built in CI and deployed as artifacts, so cPanel needs neither Node nor Composer | Phase 0 |
| **D-07** | Admin panel fully themeable | **No.** It gets your logo and brand colours, not the full 95-token engine | Phase 2 |
| **D-08** | Malware scanning for uploads | **Type/size validation for launch**, real scanning before opening uploads publicly. Note: self-hosted scanning is not possible on shared hosting, so this naturally aligns with migration | Phase 8 |
| **D-09** | Brand details | **Placeholders are fine** — all editable in the panel later. Confirm the product name is exactly "Aziv AI" | Phase 2 |
| **D-10** | PWA depth | **Installability in Phase 2, service worker in Phase 9.** Full offline not proposed: AI answers cannot be cached, and caching customer data creates privacy risk | Phase 2 |

---

## What happens next

1. You provide the **E-1 … E-8** cPanel facts (E-1 PHP version and E-2 outbound HTTPS are the
   two that can actually stop the project) and answer **D-12**'s three GST questions.
2. I confirm the final decision list back to you.
3. **Phase 0 begins** — MySQL setup, Laravel project creation, Livewire 4, Filament 5, Spatie
   Permission 8, the six-breakpoint Tailwind scale, the Playwright responsive harness, and the
   cPanel deployment verification that fails the phase if `.env` is web-reachable.
4. Phase 0 ends with a test gate and a stop point before Phase 1.

**No application code is written before step 3.**

---

## Change history

| Event | Change |
|---|---|
| Initial plan | D-01 … D-09 raised |
| Owner Addendum A — responsive/adaptive design | D-10, D-11 raised |
| Owner decision | **D-11 APPROVED** — bottom navigation + admin-configurable navigation |
| Owner decision | **D-04 APPROVED** — cPanel staging → cloud production, with 9 portability requirements (Addendum B) |
| Owner decision | **D-01 APPROVED** — India; Razorpay, INR, dated exchange rates (Addendum C) |
| Arising from D-01 | **D-12 raised** — GST handling |
| Arising from D-04 | **E-1 … E-8 raised** — cPanel account facts needed before Phase 0 |
