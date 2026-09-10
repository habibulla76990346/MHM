# Aziv AI — Decision Log

The single authoritative record of every decision and its status. No decision is settled unless it
appears here as **APPROVED**.

**Nothing in Phase 0 or Phase 1 begins until every item marked `BLOCKS PHASE 0/1` is resolved.**

---

## Status summary

| Status | IDs | Count |
|---|---|---|
| ✅ **APPROVED / RESOLVED** | D-01, D-04, D-11, **E-1**, **D-12** | 5 |
| 🔴 **BLOCKS PHASE 0** | **E-2 only** (outbound HTTPS) | 1 |
| 🟠 **Needed, but adjusts rather than blocks** | E-3 … E-8 | 6 |
| 🟡 **Recommended, awaiting confirmation** | D-02, D-03, D-05, D-06, D-07, D-08, D-09, D-10 | 8 |

**One hard blocker remains: E-2.**

---

## ✅ Approved decisions

### D-01 · Payment gateway & currency — **APPROVED: India**

**Decision:** business registered in **India**.

**Consequences, now locked into the plan:**

| Item | Resolution |
|---|---|
| **Gateway** | **Razorpay as the initial default** — strongest local coverage (UPI, netbanking, cards). **Amended by Owner Addendum D: Aziv AI is multi-gateway from the start**, with Razorpay, PhonePe, PayU, Cashfree and CCAvenue all supported by the architecture. See `14-payment-gateway-architecture.md` |
| **Default currency** | **INR**, 2-decimal precision for customer-facing money |
| **Provider costs** | Remain **USD** at 6-decimal precision — per-token prices are that small |
| **Margin reporting** | New `exchange_rates` table with **dated** rates. Margin for any period uses the rate effective on each usage date, so past margins never change retroactively when the rupee moves |
| **Tax** | GST applies. Invoice schema extended: GSTIN, place of supply, CGST/SGST/IGST breakdown, SAC code, sequential numbering, export flag |

**Why the currency question was blocking Phase 1:** it sets money-column precision and the default
currency in the schema, and changing those after real payments exist is genuinely painful.

**A new decision this raises: D-12 (GST handling), below.**

**Amended by Owner Addendum D.** Razorpay is the initial default gateway. The payment layer is
adapter-based and provider-agnostic, supporting Razorpay, PhonePe, PayU, Cashfree and CCAvenue,
with additional gateways addable later as independent modules. No gateway-specific logic is
permitted in subscriptions, plans, invoices or transactions. Full design in
[`14-payment-gateway-architecture.md`](14-payment-gateway-architecture.md).

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
| ~~**E-1**~~ | ~~PHP version available~~ | ✅ **RESOLVED.** Owner confirmed **8.3, 8.4 and 8.5** available. **Target: PHP 8.4.** Composer constraint `^8.3` so the release runs on all three; **no PHP 8.5-only feature used**; CI matrix on 8.3 + 8.4 |
| **E-2** | **Outbound HTTPS permitted** | 🔴 **THE ONLY REMAINING HARD BLOCKER.** Some shared hosts block outbound connections. Every AI provider and payment gateway call depends on this — without it Aziv AI cannot function at all |
| **E-3** | MySQL / MariaDB version | Determines JSON column and index behaviour |
| **E-4** | Cron jobs available | Queues and the scheduler both depend on cron. Without it, background work becomes manual |
| **E-5** | SSH or cPanel Terminal access | Running migrations and cache commands. Without it, a secured web migration runner is added |
| **E-6** | Can the domain's document root be changed? | Security of `.env` and source code. Alternative layout exists if not |
| **E-7** | `upload_max_filesize`, `post_max_size` | Sets admin file-size caps honestly |
| **E-8** | `memory_limit`, `max_execution_time` | Tunes chunk sizes for file processing |

**E-1 is resolved. E-2 is the only remaining true blocker.** E-3 … E-8 adjust the plan rather than
stopping it, and can arrive after Phase 0 starts if necessary.

---

### D-12 · GST handling — ✅ **RESOLVED BY OWNER DIRECTION**

The owner's final clarification answers this decisively, and in a better way than the options I
offered:

> *"GST must NOT be hard-coded… Do not automatically assume a specific GST rate or tax treatment…
> The system must keep historical invoice/tax records unchanged after future tax configuration
> changes."*

**Resolution: a fully configurable tax engine, with nothing assumed.** Recorded as Owner Addendum F
— full design in [`16-tax-and-international-billing.md`](16-tax-and-international-billing.md).

| Question I asked | Answer |
|---|---|
| Are you GST-registered? | **No longer blocking.** Tax is enabled/disabled and configured entirely from the Admin Panel. Registration status is a setting, not a build-time assumption |
| Will you sell outside India? | **Yes** — international customers are now an explicit requirement (Addendum F §4) |
| Do you have an accountant? | Recommended, and the architecture is built so their answers are *configuration*, not code changes |

**Two design consequences worth stating:**

1. **No tax rate, label or code appears anywhere in application code.** Not GST, not 18%, not
   CGST/SGST/IGST. Seeders ship *inactive, unfilled* templates the admin activates. Until
   configured, tax is simply off.
2. **Issued invoices are frozen.** The full tax computation is snapshotted onto the invoice at
   issue; the record becomes immutable; corrections use credit notes, never edits. This is the only
   structure under which "historical records never change" can actually hold — the naive design of
   recomputing from current configuration silently rewrites every past invoice the day a rate
   changes.

**Standing limitation, stated plainly:** Aziv AI provides a configurable tax *engine*, not tax
*advice*. It will not decide which jurisdictions you must register in, determine correct rates,
track threshold obligations, or file returns. Your accountant does that; the software guarantees it
will never be the reason compliance is impossible.

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

1. You confirm **E-2** — that your host allows outbound HTTPS connections. **This is the only
   remaining hard blocker.** E-3 … E-8 are useful but can follow.
2. I confirm the final decision list back to you.
3. **Phase 0 begins** — MySQL setup, Laravel 13 project on **PHP 8.4** with a `^8.3` constraint,
   Livewire 4, Filament 5, Spatie Permission 8, the six-breakpoint Tailwind scale, the Playwright
   responsive harness, the release build pipeline, and the deployment verification that fails the
   phase if `.env` is reachable over HTTP.
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
| Owner Addendum D | **D-01 amended** — multi-gateway payment architecture; Razorpay is the initial default, not the only gateway |
| Owner final clarification | **E-1 RESOLVED** — PHP 8.3/8.4/8.5 available; target 8.4, constraint `^8.3`, no 8.5-only features |
| Owner final clarification | **D-12 RESOLVED** — fully configurable tax engine, nothing assumed (Addendum F) |
| Owner Addendum E | Delivery, handover & ownership — installable, transferable product; no SSH/Supervisor/Redis/Node/root assumed |
| Owner Addendum F | Tax & international billing — configurable tax, multi-currency, international customers |
