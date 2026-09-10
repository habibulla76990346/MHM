# Aziv AI — Decision Log

The single authoritative record of every decision and its status. No decision is settled unless it
appears here as **APPROVED**.

**Nothing in Phase 0 or Phase 1 begins until every item marked `BLOCKS PHASE 0/1` is resolved.**

---

## Status summary

| Status | IDs | Count |
|---|---|---|
| ✅ **APPROVED / RESOLVED** | D-01, D-04, D-11, D-12, **D-07, D-08, D-09**, E-1 … E-8 | 8 + 8 |
| 🔴 **BLOCKING** | — | **none** |
| 🟡 **Recommended, awaiting confirmation** | D-02, D-03, D-05, D-06, D-10 | 5 |

> ## ✅ No blockers remain. Phase 0 can begin on the owner's approval.
>
> Owner Addendum G resolved the last one. The owner has not chosen a hosting provider and will not
> be asked to: **E-2 … E-8 became diagnostic checks the application performs on whatever server it
> is deployed to**, rather than questions to answer in advance. See
> [`17-system-health-diagnostics.md`](17-system-health-diagnostics.md) §9.
>
> **The owner changed D-07, D-08 and D-09** — all three recorded below. Five recommendations
> remain unconfirmed: D-02, D-03, D-05, D-06 and D-10. **Silence is not approval.**
>
> **Phase 0 will not start until the owner explicitly approves this decision board.**

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

### D-07 · Admin Panel theming — ✅ **CHANGED BY OWNER: fully themeable**

**My recommendation was overruled, and recorded as decided.** I proposed the Admin Panel receive
only logo and brand colours. The owner requires the **complete practical design system**.

**Now in scope for the Admin Panel:** multiple themes · light/dark/system modes · brand, primary,
secondary and accent colours · backgrounds · surfaces · text · borders · buttons · forms · cards ·
sidebar · navigation · chat UI · status colours · typography · font sizes and weights · border
radius · shadows · spacing and design tokens · logo · favicon · branding · homepage visual settings.

**How:** the same token engine as the customer application, with a second admin-scoped token set
compiled into Filament's CSS custom properties via a render hook. One engine, two token sets.

**One implementation detail:** Filament expects a full 50–950 shade ramp per colour, not a single
hex — so picking one brand colour **generates the ramp** in a perceptually uniform colour space,
with per-step override available.

**The owner's own constraint is the harder half:** *"keep the interface organized into categories
so the Admin Panel remains easy to use."* ~190 tokens per panel as a flat list would be technically
complete and practically unusable. So: grouped editor, progressive disclosure (set ~8 brand colours
and the rest derive), search, "affects" hints, live preview, per-group reset, contrast warnings.

**Honest boundary, and the owner's word *"practical"* is the right one:** every visual property is
themeable; Filament's internal component *structure* is not. Changing how a table header is
assembled means overriding Filament's Blade views, which creates upgrade work on each release.
Any visual property — yes. Layout restructuring — a scoped piece of work, not something the theme
system covers.

**Cost: +1 to +2 sessions in Phase 2.** Full design in
[`08-theme-branding-system.md`](08-theme-branding-system.md) §8c.

---

### D-08 · Upload security — ✅ **MODIFIED BY OWNER: Phase 1, not Phase 8**

**The owner is right, and this is a better decision than mine.** File upload is the most commonly
exploited feature in web applications, and security added after the feature is security applied to
code already written around insecure assumptions.

**All nine controls now land in Phase 1:** file type allowlist · MIME validation from content, not
the request · extension cross-checked against detected type · layered size limits · filename and
path security · **storage isolation outside the web root** · dangerous-file prevention · upload
authorization before any bytes are written · secure download rules with UUIDs, per-download policy
checks and signed expiring links.

**Scanning is an extensible layer**, exactly as instructed: a `FileScanner` interface with
`NullScanner` as default, `ClamAvScanner` for VPS, and `ApiScanner` which **works on shared
hosting** since it needs only outbound HTTPS. Selected by configuration.

**And the basic security is not weakened by a scanner's absence.** Without one configured, Aziv AI
is not scanning for malware and the diagnostics screen says exactly that — GREY, not a reassuring
false green. The nine controls carry the security either way.

**Cost: +1 session in Phase 1, −0.5 in Phase 8. Net +0.5.** Full design in
[`18-upload-security.md`](18-upload-security.md).

---

### D-09 · Initial branding — ✅ **CHANGED BY OWNER: use the real asset**

**Found it.** The owner said the branding asset was already provided. No logo file was uploaded to
this session — but the **cover page of the Master Blueprint carries embedded artwork**, and that is
the asset. Extracted and committed to [`brand/`](../brand/README.md).

| Property | Value |
|---|---|
| Format / size | JPEG, 1536 × 1536 |
| Background | Solid black `#000000`, **no transparency** |
| Subject | Stylised head in profile formed from flowing black-and-white ribbon shapes |

**No placeholder will be used.** It remains fully changeable later from the Admin Panel.

**Four practical constraints, and what Phase 2 does about each:**

| Constraint | Phase 2 action |
|---|---|
| No transparency — black is baked in | Generate a transparent variant; the background is uniform `#000000`, so removal is clean |
| Light-on-dark artwork | Needs a light-mode treatment — a dark lockup container or tonal inversion. **A design decision I need from you** |
| Raster only, no vector | Fine at every size the app needs; a vector redraw is worth commissioning eventually, not required to ship |
| Highly detailed | Derive a **simplified compact mark** — head silhouette only — for favicon and collapsed sidebar, where the ribbons would merge into grey |

All eight assets blueprint §4 requires are derived from it in Phase 2.

**One neutral note, stated once:** if this artwork came from a third party or stock source, confirm
the licence covers commercial use **and** use as a brand identity — those are often licensed
separately. A business check, not a technical one; it does not affect the build.

---

## 🔴 Blocking Phase 0/1

### E-1 … E-8 · Environment facts — ✅ **ALL RESOLVED**

**E-1** was answered directly: cPanel offers PHP **8.3, 8.4 and 8.5**. Target is **8.4**; the
Composer constraint is `^8.3` so the release runs on all three; **no PHP 8.5-only feature is used**;
CI runs the suite on 8.3 and 8.4.

**E-2 … E-8 are no longer questions.** Owner Addendum G changes their nature entirely:

| Was a blocking question | Is now |
|---|---|
| E-2 · Outbound HTTPS permitted? | `network.outbound_https` — **Critical** check, run at install, first login, on schedule and on demand |
| E-3 · MySQL/MariaDB version | `database.version` check |
| E-4 · Cron available? | `cron.heartbeat` check — detects whether the scheduler is actually running |
| E-5 · SSH / Terminal access? | Not required at all — browser installer and Admin maintenance utilities |
| E-6 · Document root changeable? | `security.env_not_web_reachable` — **Critical** check that tests it over HTTP |
| E-7 · Upload limits | `php.upload_limits` check, compared against configured admin caps |
| E-8 · Memory / execution limits | `php.resource_limits` check |

**Why this is the better answer.** Pre-verifying one specific server is the wrong shape of solution
for a product that must install on servers nobody inspected in advance. A system that tests every
server it lands on, and reports the result in language the owner can forward to support, is
correct in every case rather than one.

> **The risk itself has not vanished, and I want to be plain about that.** A host that blocks
> outbound HTTPS still cannot run Aziv AI. What has changed is that this is now **detected within
> minutes, named precisely, and accompanied by the exact sentence to send to the hosting provider**
> — instead of surfacing as a mysterious failure after the platform is built. The installer refuses
> to complete on a Critical failure, so it cannot be missed or ignored.

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

## 🟡 Recommended — awaiting confirmation (5 remaining)

The owner confirmed these stay as proposed **for now**. Silence is still not approval.

| ID | Decision | Recommendation | Needed by |
|---|---|---|---|
| **D-02** | Filament for the admin panel | **Yes.** ~90 screens on exactly the blueprint's stack. Note: D-07 makes Filament's themeability a live concern — it is met via CSS custom properties, confirmed workable | Phase 2 |
| **D-03** | Vector storage for document search | **Start with MySQL.** Schema allows swapping the backend later without a rewrite | Phase 8 |
| **D-05** | Launch after Phase 6 or Phase 9 | **After Phase 6.** Nothing is skipped; Phases 7–9 happen with real customers already using it | Phase 6 |
| **D-06** | Tailwind build tooling | **Standard Node build.** Node never touches your server — assets are built in CI and shipped compiled, so cPanel needs neither Node nor Composer | Phase 0 |
| **D-10** | PWA depth | **Installability in Phase 2, service worker in Phase 9.** Full offline not proposed: AI answers cannot be cached, and caching customer data creates privacy risk | Phase 2 |

---

## What happens next

1. **Nothing is blocking.** You review this decision board and **explicitly approve it**.
2. Five recommendations remain: D-02, D-03, D-05, D-06, D-10.
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
| Owner decision | **D-07 CHANGED** — Admin Panel fully themeable, not partially |
| Owner decision | **D-08 MODIFIED** — upload security moved to Phase 1; scanning becomes an extensible layer |
| Owner decision | **D-09 CHANGED** — official Aziv AI artwork used as initial branding; no placeholder |
| Owner Addendum G | System health & diagnostics — two deployment modes, self-detecting environment, 11-field findings, secret-free reporting. **Resolved E-2 … E-8; no blockers remain** |
