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

### D-09 · Branding — ✅ **RESOLVED: both variants built, master preserved**

**Owner's decision:** build and retain **both** presentation variants; keep the simplified
small-size mark; **never permanently alter or replace the official artwork**; let the theme system
select the appropriate variant; keep everything replaceable from the Admin Panel.

**Done — the assets exist now**, in [`brand/`](../brand/README.md), derived and verified rather than
merely specified.

| Asset | Status |
|---|---|
| `aziv-ai-logo-master.jpg` | **Master, preserved untouched.** Every variant derives from it |
| `logo-dark-bg.png` | Master artwork, trimmed — native to dark surfaces |
| `logo-light-bg.png` | Tonal inversion, desaturated to neutral — reads clearly on white |
| `logo-lockup-dark.png` | Artwork preserved exactly on a rounded dark panel, for email and tiles |
| `mark-compact-dark/light.png` | Head profile only, for collapsed sidebar and mobile |
| `favicon-16/32/48/64.png` | Browser tab |
| `app-icon-180/192/512 + maskable` | Home screen and PWA |

#### Correction: the black could not simply be keyed out

I previously wrote that the background was uniform `#000000` and removal would be "clean and
mechanical." **That was wrong, and the correction matters.** Measurement shows the artwork is
**72% near-black**, and a single horizontal scan crosses **25 alternating light/dark runs** — the
black ribbons are structural elements of the design, not a removable surround. Keying black to
transparency deletes half the artwork.

Three approaches were built and compared on a real white background. **Tonal inversion, desaturated
to neutral, was chosen**: form, depth and profile all survive. (Inverting the cool silver produced a
warm sepia cast, hence the 85% desaturation.) The dark lockup is retained for email headers and
tiles, where a contained block is appropriate.

#### Known limitation — the 16px favicon needs a designer

**Flagged rather than quietly shipped.** The artwork is fine alternating ribbons; at 16–32px they
merge into grey however the reduction is done. Three approaches were tested at actual size — direct
downscale, solid silhouette, bold-ribbon reduction — and none reads clearly at 16px.

| Size | Status |
|---|---|
| ≥ 64px | ✅ Real artwork works well |
| 32–48px | 🟡 Simplified reduction acceptable |
| **16px** | 🔴 **Weak** — a dark shape, not a recognisable mark |

Interim assets ship so nothing is missing. **The proper fix is a hand-drawn simplified mark** — the
face profile alone, or a three-ribbon abstraction, as a vector. A one-to-two-hour design job, and it
drops in through the Admin Panel later without touching anything else.

---

## ✅ Formerly blocking — all resolved

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
| Owner decision | **D-09 RESOLVED** — both variants built and committed; master preserved; 16px favicon flagged as needing a designed mark |
| Owner Addendum G | System health & diagnostics — two deployment modes, self-detecting environment, 11-field findings, secret-free reporting. **Resolved E-2 … E-8; no blockers remain** |
| Owner instruction | **Phase 1 implemented and committed** — identity, roles and permissions, settings, audit, upload security, app shell |
| Owner instruction | **Phase 2 started** — full theme engine first, with the Admin Panel on the same token system (D-07) |

---

## Findings from Phase 2 implementation

Recorded because each one changes something previously believed to be true.

### The hard-coded-colour gate was only checking two directory levels

`SmokeTest::test_no_blade_template_hard_codes_a_colour` globbed `views/**/*.blade.php`. PHP's
`glob()` does not recurse — `**` matches exactly one directory level — so every template nested
deeper, which is most of them, went unchecked. Replaced with a recursive iterator. It immediately
found hard-coded Tailwind greys in the Admin Panel's System Health page, i.e. the exact D-07
violation it exists to prevent.

**Rule this confirms:** a gate is only worth what it can catch. Each of the four checked rules
is now exercised against a deliberate fault before it is trusted.

### The responsive gate covered no Admin Panel screen

Owner Addendum A says the requirement applies to "EVERY user-facing page ... and the COMPLETE
Admin Panel". The gate checked seven customer screens and zero admin screens, so half the
requirement was asserted by nobody. The Admin Panel now has three screens in the gate, and it
failed all three on the first run: Filament ships 36px controls, 32px icon buttons, 14px input
text and a 20px checkbox row. Fixed in the admin theme, in tokens, not per template.

### Caching an Eloquent model breaks the site on a real server

`Cache::rememberForever()` on a model serialises the whole object. On any serialising store —
file, database, Redis — that payload outlives the class definition that wrote it and returns as
`__PHP_Incomplete_Class`, taking down every page including login. The test suite runs the `array`
driver, which stores by reference and never serialises, so PHPUnit cannot see it.

Found twice: in the theme service, and in the Phase 1 diagnostics page, where it broke System
Health — the one screen an administrator opens when something is already wrong.

**Rule going forward:** only scalars and plain arrays go into the cache. Both sites now have a
regression test asserting the cached value is plain data, which holds on any driver.

### Custom CSS sanitisation stripped the scheme but left the URL

`url(https://evil.test/a.png)` became `evil.test/a.png)` — invalid CSS that a browser would
discard, which is a parser doing security work by accident. Rewritten default-deny: only relative
URLs survive, everything else becomes `none`.

### Two colour-maths defects that would have shipped as "looks slightly off"

- Clamping RGB channels for an out-of-gamut colour rotates the hue, so a request for an orange
  warning came back brown (70° → 60°). Now chroma is reduced until the colour fits, and the hue
  arrives intact.
- An achromatic colour has no hue, and the conversion reports it as 0° — which is red. Mixing
  white toward a blue-grey therefore travelled through pink and produced a warm grey. Now the
  powerless-hue rule from CSS Color 4 applies: a neutral adopts the other end's hue.

### Themes are derived, not authored

A theme is ~7 palette colours; the remaining tokens are derived. The derivation guarantees WCAG
AA on every checked text/background pair by construction — and it had to, because four of the
eight built-in themes failed the link-contrast check as originally authored. The guarantee holds
for administrator-authored palettes too, which is tested with a deliberately hostile one.

### The Appearance editor: what D-07 actually costs

D-07 asks for control of "the complete practical design system" and, in the same breath, to "keep
the interface organized into categories so the Admin Panel remains easy to use". Taken literally
the first gives 69 tokens x 2 scopes x 2 modes = 276 editable values on one screen, which is
complete and unusable.

The screen resolves it with two tiers rather than by dropping either half of the requirement:

1. **Brand colours** — seven values, two modes. Changing them re-derives every other token, for
   both scopes and both modes at once. Most owners will never open the second tier.
2. **Everything else** — grouped, collapsed, searchable, with each value stating what it affects
   ("the dimming behind a modal or drawer"), so it can be found without knowing its name.

Built-ins refuse edits and offer Duplicate instead, which is what guarantees there is always a
known-good theme to return to.

### Two more defects, both silent

- **Livewire reads a dot in `wire:model` as a nested array path.** `values.color.primary` wrote
  `$values['color']['primary']`; the token was never touched and nothing reported an error. Token
  values are now keyed dot-free. This is the same shape as the Laravel validation-key trap from
  Phase 1 — the rule is now generalised in `CLAUDE.md`.
- **`version + 1` never moved the version on a newly created theme.** `Theme::create()` leaves the
  attribute NULL in memory while the database holds 1, so the arithmetic wrote 1 over 1. Because
  the version is part of the compiled-CSS cache key, every edit to a freshly duplicated theme
  would have been invisible until the cache was cleared by hand. Now `increment()`, which reads
  the stored value.

### Validation: exclude the dangerous, do not enumerate the valid

`TokenValidator`'s first attempt parsed shadow CSS with a regex and rejected
`0 1px 2px rgb(0 0 0 / 0.06)` — a value this application generates itself — because the leading
`0` carries no unit. Enumerating every valid CSS grammar is not the job. Excluding what is
dangerous is, and that list is much shorter: a character allowlist plus a colour-function
allowlist, so `url()` and anything else that fetches from a third party cannot appear.

---

## D-13 · How brand assets are served — ✅ **APPROVED BY OWNER: option C**

**Decided.** Derived, deliberately-public images are written to a versioned path under
`public/brand/`. Every original upload — and the master artwork — stays on the private disk.

Phase 1 established that uploads live on a private disk with no public URL, served through an
authorised controller (Addendum H). Brand assets break that shape: a logo and favicon must be
fetchable by anonymous visitors on the login page, and a PWA icon must be fetchable by the
operating system with no session at all.

Three options, each with a real cost:

| | How | Cost |
|---|---|---|
| **A** | A public controller streams from the private disk | Nothing new in `public/`. But every logo request is a PHP request — on shared hosting, on every page, for every visitor |
| **B** | Laravel's standard `public` disk plus `storage:link` | Standard and fast. Needs a symlink, which many cPanel accounts do not permit — and Addendum B says the product must not depend on that |
| **C** | Derived assets are written to a versioned path under `public/brand/` when branding is saved | No PHP per request, no symlink, cacheable, works identically on both deployment modes. But the web root becomes writable at runtime, and the deployment security test has to keep proving only non-sensitive derived images ever land there |

**Owner chose C.** It is the only option that satisfies Addendum B (no symlink, no
provider-specific behaviour) and Addendum E (shared hosting differs in speed, never in capability)
at the same time.

### What C obliges the implementation to do

The web root becoming writable is the cost of this choice, so the controls that contain it are
part of the decision, not an implementation detail:

1. **The private disk stays the record of truth.** Uploads go through the Phase 1 pipeline
   (`FileStorage` → `UploadValidator` → scanner) exactly as any other upload, with all nine
   Addendum H controls. Publishing is a second, separate step that copies bytes out.
2. **Only raster images are ever published.** PNG, JPEG, WebP and ICO. Never SVG from an upload —
   SVG is a document format that can carry script, and it would be served same-origin. The
   `mark.svg` that ships with the product is ours and is committed, not published at runtime.
3. **A quarantined or unscanned-and-rejected file is never published.**
4. **The published name is derived, never the client's.** `<purpose>-<first 8 of the sha256>.<ext>`
   — so the name is a content hash, caching is safe forever, and no part of a client filename
   reaches the filesystem.
5. **Publishing replaces, and the old file is removed**, so the web root does not accumulate.
6. **`DeploymentSecurityTest` proves the containment**: everything under `public/brand/` must be a
   raster image or the committed `mark.svg`, and nothing else may appear in the web root.

A host whose `public/` is not writable degrades to the shipped default branding and says so in
System Health — capability preserved, exactly as Addendum G requires.

### D-13 as built

`media_assets` was dropped. It had been created earlier in Phase 2, before a close look at the
Phase 1 `files` table — which already carries checksum, dimensions, purpose and owner, and is the
only path through `UploadValidator` and the scanner. A second media table would have been a second
place for upload security to be got wrong, and the first time the two drifted the weaker one would
have been the one an administrator used. Brand assets are `files` rows with a `brand_*` purpose.

Two defects found while wiring branding into the templates:

- **Two of the shipped defaults were not in the web root.** `app-icon-maskable-512.png` and
  `mark-compact-dark.png` existed in `brand/` but had never been copied to `public/brand/`, so the
  generated manifest advertised an icon that 404s. A default that does not resolve is worse than
  no default, because every other fallback guarantee rests on it. There is now a test asserting
  every shipped default exists, and the manifest test fetches every icon it advertises.
- **The homepage took the product name from `config('app.name')`**, so renaming the product in the
  Admin Panel changed the browser tab and not the page. Branding is data, never environment.

`branding.view` and `branding.manage` are separate from the theme permissions. Choosing a colour
and writing a file into the web root are different kinds of trust, and Support holds neither.

The containment D-13 promised is asserted, not assumed: `DeploymentSecurityTest` now walks
`public/brand/` and fails on anything that is not a real raster image, and separately proves the
publisher cannot delete outside that directory. Both were verified by deliberately planting an
uploaded-looking SVG and a PHP script named `.png` and watching the gate fail.

---

## Phase 2 content management — what was decided while building it

### Pages are assembled from blocks, not written as HTML

A rich-text field accepting arbitrary HTML would have been less work and is what most CMSs do. It
was rejected for two reasons, the second of which is the one that actually matters:

1. It would be the single place in Aziv AI where an owner could hard-code a colour, which defeats
   the theme system the whole of D-07 exists to build.
2. Owner Addendum A applies to "EVERY user-facing page". A page built from free HTML passes the
   six-viewport gate only by luck, and the owner who broke it would have no way to know — the
   failure would surface as a customer complaint, not a build failure.

So `SectionType` declares a closed set of blocks with their fields. Each renders from tokens and is
responsive by construction, and the editor builds itself from the same declaration, so the editor
and the renderer cannot drift apart.

### Pages live under `/p/`

An administrator can create any slug they like. Without a prefix, a page called `login` would
shadow the login route and lock everyone out of the product — a content edit causing an outage.
The prefix makes that impossible rather than merely unlikely.

### Scheduling is a timestamp, not a status

`status = published` plus a future `published_at`. A separate "scheduled" status would allow a row
that is both published and scheduled, and then two parts of the code would answer "is this live?"
differently. The cached lookup re-checks the freshly loaded page as well, because a cache entry can
outlive the schedule that made it valid.

### Navigation drops what it cannot resolve

An item pointing at a renamed route, a deleted page, an unpublished page or a `javascript:` URL is
removed from the rendered menu rather than shown as a dead link. The admin list shows the same
thing from the other side: a "Goes to" column showing where the link **actually** resolves, so a
broken item is visible before a customer finds it.

The bottom bar's four-item cap is enforced in the editor with an explanation, because it is a
consequence of the 44px touch minimum at 320px — a fifth tab would fail the responsive gate, and
refusing it in the editor is better than letting someone break the build.

### Legal pages ship as drafts

Terms, Privacy and Refund Policy are seeded with placeholder text and left **unpublished**.
Placeholder legal text served as a live page is worse than no page at all, because it reads as
though somebody wrote it.

### The touch-target rule now measures the target, not the box

Adding the content admin screens surfaced Filament's table chrome: 16px selection checkboxes and
24px switches. Neither can simply be made 44px — both are drawn with `appearance: none`, so growing
the box grows the visible control into something absurd, and neither has a `<label>` to measure
instead.

The fix is an absolutely positioned transparent pseudo-element: the control keeps its shape and the
TARGET meets the minimum. The gate was changed to match — it now accepts a control whose
pseudo-element declares a large enough area.

This is a better measurement, not a relaxation: it asks the question the rule is actually about
(can a finger hit this?) rather than a proxy for it, and a bare undersized control with no expanded
area still fails. Proven by shrinking both hit areas to 4px and watching 24 checks fail.

An earlier attempt used `elementFromPoint` to hit-test the corners. It was wrong: it only reports
what is currently inside the viewport, so every control below the fold was counted as failing.
Computed style works wherever the element sits.

---

## Phase 3 — the universal AI gateway

### What was built against fixtures, and what that means

The plan has the owner supply an OpenAI key and a Gemini key. Neither exists yet, so **every
adapter behaviour in Phase 3 is proven against recorded response shapes**, not against a live API:
request translation, reply normalisation, streaming fragments, model discovery, and each of the
nine failure classes. What fixtures cannot prove is that a given provider's real API matches the
shape recorded here. The test console exists precisely to answer that in one click the moment a
key is added.

### Credentials: four separate escapes, four separate controls

A key can leak from the database, from a serialised model, from a rendered screen, or from an
audit entry. Closing one says nothing about the others, so each has its own control and its own
test:

1. **At rest** — `credential` is an `encrypted` cast. The stored value is ciphertext.
2. **In transit through the application** — the column is `$hidden`, so every `toArray()`,
   `toJson()`, API resource and Livewire snapshot omits it structurally rather than by habit.
3. **On screen** — `hint` holds the last four characters in plaintext, written on save. The Admin
   Panel identifies a key from THAT, so listing keys never decrypts anything (§25). The edit form
   shows an empty key field: pre-filling it would mean sending the secret to a browser.
4. **In the audit trail** — entries record the label and the hint. An audit log that captures a
   credential defeats the point of encrypting it, and is read by more people than the key is.

`secret()` is the only way to read the value back, and has exactly two callers.

### A provider's own error text never crosses the boundary

Several APIs echo the failing request back in their error message — and that request carried the
key. So the adapter layer discards the prose at the point of failure and keeps a normalised CLASS
plus an HTTP status. Every class carries a remedy written for a non-developer ("The API key is
wrong, expired or revoked. Create a new key in the provider's dashboard"), which is what Owner
Addendum G asks for and what a raw provider message could never be.

Classification reads only well-known machine-readable markers (`error.code`, `error.type`), never
free text, for the same reason.

### `{{credential}}`, because a header template is not encrypted

`CustomHttpAdapter` lets an administrator describe an API from the panel. Its `headers_template` is
an ordinary JSON column — it describes a shape, so it is not encrypted. An administrator who pasted
a real key into it would be writing that key to the database in plaintext and into every backup.

Authentication is therefore applied by `BaseAdapter` from the encrypted credential, and a template
that needs the key somewhere unusual writes `{{credential}}`, substituted at call time. The stored
template holds only the placeholder.

Relatedly: a request template is **configuration written by a person, and is never evaluated**. It
is walked and substituted literally, so `{{ phpinfo() }}` in a template arrives at the provider as
those exact characters.

### Rule 7, asserted against the source

Multiple credentials are supported for genuine rotation and redundancy. Aziv AI does **not** cycle
keys when one hits its quota: that behaviour has no purpose but evading the limit a provider set.

A behaviour test can only show that cycling does not happen on the paths it exercises, so this is
asserted by reading the source — no file in `app/Domains/AI` may combine quota/rate-limit handling
with credential rotation. It fails loudly if someone later adds one believing it to be a helpful
retry.

### Provider diagnostics are contributed by the adapter, and cost money

`AiProviderCheck` does not know how any provider signals trouble — it asks each adapter to report
on itself. Centralising that would put provider-specific knowledge back above the adapter layer,
which is what §12 exists to prevent.

It is also marked `costsMoney()`, so it never runs unattended. A connectivity test is an
authenticated call; running them across every provider every few minutes would quietly spend an
owner's money on diagnostics.

### Three catalog rules, each with a failure it prevents

- **A newly discovered model arrives DISABLED.** Otherwise a provider's release schedule decides
  what an owner's customers can spend money on, without the owner seeing it.
- **A model that disappears is deprecated, never deleted.** Usage records, invoices and analytics
  refer to it; deleting it would orphan that history and silently change past reports. A synced
  model is not deletable from the panel either, for the same reason — and the next sync would
  bring it back.
- **A manually added model is never overwritten.** An administrator typed it in because the
  provider does not list it; a sync must not undo that.

### Two bugs found while testing

- **A classified failure was being re-classified as "unknown".** `BaseAdapter::send()` caught
  `\Throwable` around the request, which swallowed the `ProviderFailed` that `client()` raises for
  a missing credential. An owner with no key would have been told "Aziv AI could not classify this
  failure" instead of "add an API key".
- **The model price repeater started with an empty required row**, so a model could not be created
  until someone invented a price. A model can exist before anyone knows what it costs.

---

## Phase 4 — OpenAI, Gemini, and chat

### Gemini is why the adapter layer exists

OpenAI and Gemini disagree about nearly everything: messages are `contents` with `parts`; the
assistant's role is `model`; the system prompt is lifted out into `systemInstruction`; generation
settings live under `generationConfig`; the key goes in the query string; the model identifier is
part of the URL path rather than the body; streaming frames are shaped differently; and usage comes
back under different field names again.

Every one of those is contained inside `GeminiAdapter`. Nothing above `ProviderAdapter` changed to
add it, which is the claim §12 makes and this is the first real test of it.

Gemini also reports a rejected key as **HTTP 400** with a machine-readable reason, where most APIs
use 401. Without handling that, an owner with an expired key would be told "the provider rejected
the shape of the request" and would go looking at their settings instead of their key.

### Streaming: SSE, and why the fallback is not an error path

A reply is one-way, server to browser. SSE is plain HTTP — no extra service, no persistent worker,
no open port — so it works wherever it works at all and needs nothing new on a VPS. A WebSocket
would add infrastructure for a feature with no return channel.

Risk R-01 says shared hosting buffers output and cuts long connections. So
`/chat/{message}/complete` produces the same answer in one response, and the browser uses it when
streaming is switched off OR when the stream fails to open. The platform degrades; it does not stop
working. `X-Accel-Buffering: no` is set because Nginx buffers proxied responses by default, which
defeats SSE entirely.

### What "stop" has to do

Stopping is a cache flag, not a signal: the streaming loop and the request that started it may be
in different processes, and a flag is the one mechanism that works on every deployment mode without
assuming Redis or a persistent worker.

A stopped reply **keeps what arrived**. It was produced and paid for; discarding it would throw
away work the customer already owns. And it is SETTLED — a message left in `streaming` forever is
worse than one marked stopped.

### Regeneration preserves history in two ways

§15 requires the original answer to survive. It does, as a row — the replacement carries
`regenerated_from_id`, and `visibleMessages()` filters the superseded one out rather than deleting
it.

Less obviously: the regeneration's CONTEXT must be the conversation as it stood *before* that
answer, or the model is asked to improve on something it can already see. `ContextBuilder::build()`
takes an `$upTo` cut-off for exactly this.

### Context is the biggest lever on what a chat costs

Every message resends the history, so an unbounded context gets more expensive with every turn
until it hits the model's ceiling and fails. Three limits apply in order, because each catches
something the others miss: a message count; a token budget (twenty short messages and twenty pasted
documents are not the same amount of money); and the model's own context window, minus room for the
reply — a context that exactly fills the window leaves the model nowhere to answer.

The newest message always goes, even if it alone exceeds the budget. Dropping what the customer
just typed would answer a question nobody asked; the provider's own limit refuses it, with a
message that says so.

### A security bug found by a test

Spatie registers its own `Gate::before` granting a Super Admin every ability — the trap already
recorded in `CLAUDE.md` from Phase 1. It meant `$this->authorize('stream', $message)` let an
**administrator read a customer's conversation as it streamed**.

Reading someone's chats is a support action with its own permission and its own audit trail, not a
side effect of being an administrator. The streaming routes now compare ownership directly, which
cannot be short-circuited by a Gate callback. The policy remains as the statement of intent.

### The chat behaviour gate

Two of Addendum A's chat requirements are about how the page BEHAVES, so the six-viewport gate
cannot see them. `npm run test:chat` exercises both against the real page: the composer staying
above a mobile keyboard (visualViewport is replaced before any page script runs, so the page sees
what iOS actually gives it), and new text following the reader only while they are at the end.

Proven by breaking both on purpose — removing the keyboard inset and forcing `pinned = true` — and
watching three checks fail.

### The Http::fake trap, twice more

The trap recorded after Phase 3 bit two more times while writing Phase 4's tests: a re-faked
endpoint silently kept returning the first stub's response, so "regenerate produces a different
answer" and "a provider failure returns 502" both passed vacuously at first. Both test classes now
register one stub that reads mutable state.

---

## Phase 5 — smart routing, health, fallback and cost

### The router decides; it does not call providers

Six stages, each doing one thing: resolve what the request needs → filter every model against hard
conditions → score the survivors by what the mode cares about → attempt with retries → substitute
on failure → record. The decision is separated from the attempt, so the same logic will serve
images and voice in Phase 8 without being rewritten, and a routing decision can be tested without a
network.

### The capability guard lives in the pipeline, not in the fallback

The failure §14 names is a vision request falling back to a text-only model: it does not error, it
answers wrongly. That is guaranteed here by construction — a fallback re-runs the WHOLE pipeline
with the same requirement, so the substitute passes the same filter the first choice did. Keeping a
pre-built list and popping from it is how that guarantee gets lost, because the list would have
been built before anyone knew what would fail.

### Every rejection is recorded, not just the winner

`CandidateBuilder` deliberately does not use the `routable()` scope: a model excluded by a query
would vanish from the log with no reason attached. Every model is assessed, and "why was this model
not used?" — the question the routing log exists to answer — has a recorded answer for each one.

### Retry and substitute answer different questions

A retry is for a blip on a provider that is otherwise fine; a substitution is for a provider that is
not. Trying a different provider for a 429 would spread one impatient request across every provider
an owner has. So retries stay on the same provider, only for transient classes, with full jitter —
"base plus a little random" leaves a thundering herd still clustered — and a provider's own
`Retry-After` always wins over our arithmetic.

### The circuit breaker lives in the cache; the database is a mirror

The router reads it on every request, so the check must cost nothing. The mirror is what the Admin
Panel displays and what an owner resets, and writing it is best-effort: a breaker that could take
the site down by failing to write a log row would be worse than the outage it exists to handle.
Every admin control now goes through `CircuitBreaker`, because clearing only the mirror row would
report success and change nothing.

A rejected key does not open the circuit. A configuration mistake is not a provider being down, and
taking a healthy provider out of rotation over one would hide the real fix.

### No evidence is not bad evidence

A provider nobody has used yet scores as healthy and neutral on speed. Scoring it as if it had
failed would make every newly added provider permanently unreachable — a product that could never
be extended. For the same reason an unpriced model scores neutrally on cost rather than as free:
"free" would win Lowest Cost every time, which is how an owner unknowingly serves their most
expensive model for nothing.

### Cost is frozen twice: at the price, and at the rate

Provider cost is computed at the price that applied when the call happened, and stored. It is then
converted into the owner's currency at the rate that applied on that DAY, and that figure is stored
too. Either half left to read time would rewrite last quarter's margin — once when a price is
edited, once every time the rupee moves.

A missing rate records a visible zero rather than an invented figure, and the screen says which
currency is missing. A zero that can be found and corrected is better than a guess that cannot.

### What a customer is told is not what the log records

The routing log keeps the exact rejection for the owner. A customer hears only what they can act
on: "temporarily unavailable" when every provider is out of rotation, "start a new chat" when the
conversation outgrew every context window, and otherwise that an administrator needs to enable a
model. "The model is disabled" is an administrator's sentence, not an answer to somebody trying to
chat.

### Fallback stops at the first fragment of a stream

Once a customer has seen text, switching models would splice two different answers together in one
bubble. A provider that dies on connect — the common case, and the one fallback exists for — stays
invisible to them; one that dies mid-answer settles the partial reply as failed.

### Two bugs the Phase 5 tests found in earlier code

`ExchangeRate::on()` collided with Eloquent's own `Model::on($connection)`. Redeclaring it with a
different signature is a **fatal error at class load** — the whole application stops, not just the
report. It is now `rateOn()`.

`CredentialUsageCounter::record()` seeded the row with the call's own figures and then incremented
it, counting the first call of every hour twice. It now inserts at zero, so the increment is the one
place a number is added.

---

## Phase 6 — subscriptions, credits, tax and payments

### The credit ledger is append-only, and the lock is the point

Every write happens under `SELECT … FOR UPDATE` on the balance, inside the transaction that also
writes the ledger row. Two consequences, and both are the whole design: the cached balance can
never disagree with the sum of the ledger, and parallel requests cannot both pass an affordability
check against the same balance.

`if ($balance >= $amount)` is true in two processes at the same instant. Only the database can
serialise them, which is why the test that proves it forks: a sequential test passes whether or not
the lock exists, so it proves nothing about the thing that actually goes wrong. With the lock
removed, ten requests for 30 credits against a balance of 100 all succeed — the owner sells 300
credits' worth of AI for 100.

### A hold is a pre-authorisation, not a quote

The estimate held before a call is deliberately generous — the context plus the model's maximum
output — because nobody knows the real figure until the answer exists. It is settled down to what
the call actually cost.

Settling is capped by the BALANCE, not by the estimate. Capping at the estimate would make the
owner subsidise every reply that ran longer than expected; the unrecovered remainder when an
estimate is low is bounded by the model's own output limit, and that is the right place for the
cost of estimating to fall. A failed call charges nothing at all: a customer pays for answers,
never for attempts.

### Metering begins when the owner publishes a plan

Not when prices are entered, and not when this code shipped. Otherwise an owner who priced their
models before configuring billing would find every customer refused by a subscription system they
had not set up. New and existing accounts land on the default plan the first time they chat, which
is what lets billing be switched on without a migration over every account.

### An issued invoice is a copy, not a query

Every figure and every name on it is snapshotted at issue: the business address, the customer's
details, each tax component's name, rate and amount. Nothing is looked up again when it is
displayed.

The naive alternative — storing a rule reference and recomputing on display — looks correct and is
quietly catastrophic: the day a rate changes, every historical invoice changes with it, filed
returns stop matching, and the records shown to an auditor no longer match what the customer was
charged. Deleting a tax rate outright leaves the invoices that used it completely unmoved, because
`invoice_tax_lines` has no foreign key back to `tax_rates`. That absence is deliberate.

### Not one tax name, rate or code is in the code

The engine knows how to compare a customer's country and state to the business's, how to read dated
rates, and how to add or extract a percentage. What the percentages are called and what they are
worth is entirely data an administrator entered. The rule is now checked rather than reviewed: a
tokeniser-based scan reads identifiers and string literals with comments stripped, so explaining
why a name must not appear does not itself trip the gate.

The same scan enforces Addendum D's rule that no gateway name appears in billing code — scoped to
the billing area, because searching the whole application for a payment brand that is also an
ordinary English word flags a theme token called "table-stripe", and a gate that cries wolf is a
gate somebody switches off.

### Four idempotency guarantees, because one is never enough

`payment_webhook_events` unique on (gateway, event id) · `payments.idempotency_key` unique ·
`subscription_periods` unique on (subscription, period start) · the payment row locked while it is
settled.

Breaking any one of them on purpose showed why all four are there. Removing the event guard is
caught by the payment lock; removing the payment lock is caught by the period guard. Each layer
catches what another misses, and the tests now assert each one specifically rather than only
asserting the outcome — an outcome test passes while a layer silently rots.

### Three paths, one handler, and the gateway is the only authority

A customer's browser coming back, a webhook, and the scheduled sweep all converge on `settle()`,
which asks the gateway directly. A URL that says "success" can be replayed, edited, or never
arrive; a webhook is authentic once verified but is delivered more than once. The sweep is what
makes the system correct when somebody closes the tab mid-payment — without it, money is taken and
nothing is delivered, and the first anyone hears of it is a support ticket.

`authorized` is not `captured`. Treating a hold that can expire as a completed payment is how a
customer gets credits for money that is later released.

### A subscription is never routed to a gateway that cannot renew it

Capability is a hard filter applied before an owner's preferences are read: a routing rule orders
the gateways that can already do the job, and can never widen them. Capability is read from the
ADAPTER, not the configuration row, because a row can be edited to claim anything and the claim
that matters is the one the code can honour.

The shipped adapter declares one-time payments, refunds and partial refunds — not recurring, and
not mandates. Both exist on the platform, but each carries its own registration flow and RBI
e-mandate rules, and declaring a capability that is not implemented is exactly the failure the
guard exists to prevent. Plans sold through it renew by invoice-and-pay until that work is done,
which is a deliberate choice with different customer messaging.

### A development gateway that can never take money

"Checkout completes on a 320px viewport" cannot be checked against a page that redirects because
nothing is on sale. So there is a gateway adapter that calls nothing, and it refuses to operate
outside local and testing environments at every entry point rather than at one — a gateway that
reported success without taking money would, in production, be a way to get credits for nothing.
It never reports a payment as complete, even in development.

### Refunds take back what is unspent

The owner's decision, implemented literally: claw back up to the refunded amount from the remaining
balance, never below zero. Credits already used cost real provider money and cannot be recovered by
arithmetic, and trapping an honest customer at a negative balance would punish somebody who may
have had a good reason to ask. A refund also produces a credit note rather than editing the
invoice.

### Three bugs the phase found in earlier code

`php artisan db:seed` failed on a fresh database: `WithoutModelEvents` suppressed the `creating`
hooks that generate uuids and slugs, so every seeded page violated a NOT NULL constraint. Seeding a
fresh database is the first thing a new owner does.

Filament's table row-action links were never 44px tall, because `min-height` does nothing on an
inline element — the browser ignores the property rather than warning. The rule had been in the
theme for two phases; every admin table checked before this one happened to be empty, so the gate
had nothing to measure.

An empty relationship column rendered a 32px empty link. `TextColumn::make('prices')` on a HasMany
resolves to a collection Filament renders as nothing, and the fix is `->state()`. The gate found it
the first time a table had rows in it.

## Phase 7 — Claude, DeepSeek, Mistral, Groq, and anything OpenAI-shaped

### Five providers, one new adapter

Phase 7 was asked for five providers and shipped ONE class. Anthropic needed it; DeepSeek,
Mistral and Groq did not, and neither did OpenRouter or Hugging Face, which came along free
because they copy the same shape. Each of those is a row in `ProviderRegistry::presets()` naming
an adapter that already existed — a base URL and where to get a key, both editable afterwards.

That ratio is the argument §12 was making. If adding DeepSeek had needed a `DeepSeekAdapter`,
"multi-provider" would mean "the providers we got round to", and the sixth would wait for a
release. It is asserted rather than admired: the preset test fails if fewer than five presets
are served by the shared adapter.

### The four things Anthropic genuinely does differently

An adapter is justified by real differences, not by the provider being famous. Anthropic's are:

1. **The system prompt is a top-level field**, not a message with `role: system`. Sent inline it
   is ignored, so a persona silently stops working — no error, just a different personality.
2. **`max_tokens` is required.** Omitted, the request is rejected outright.
3. **Its own auth headers** — `x-api-key` plus a dated `anthropic-version`. Not a bearer token,
   and the version is not something an owner should have to know about.
4. **Typed streaming events.** Text arrives as `content_block_delta` with `text_delta`, the stream
   ends on `message_stop`, and other block types (thinking, tool use) share the channel. An
   adapter that forwarded every delta would paste a model's private reasoning into the reply.

Each of the four has its own test. Together they are the entire justification for the class, and
if a future version removed them the class should go with them.

### `baseRequest()`, so timeouts cannot drift

Anthropic overrides `client()` to build its own auth. Before this phase that meant restating the
timeouts, the JSON handling and the owner's extra headers — the way two adapters end up with
different timeout behaviour for no reason anyone remembers. `client()` now composes
`baseRequest()`, and an adapter with unusual auth overrides only the auth.

### Stage 5 has one implementation, not two

`AiRouter::fallback()` was unreachable. Every substitution goes through `ChatService::substitute()`,
which re-runs the router with the failed models excluded — and the two copies held the same two
guards (a pinned conversation never substitutes; the owner's depth limit is obeyed) with nothing
keeping them in step.

The multi-provider gate found it by being sabotaged: breaking cross-family fallback in
`AiRouter::fallback()` changed no test result, because nothing calls it. Breaking the same thing
in `ChatService::substitute()` failed three tests immediately. Two copies of a safety guarantee is
one copy and one thing that drifts, so the dead one is gone and the router documents where stage 5
actually lives.

### A provider Aziv AI has never heard of, added from the panel

The plan's claim was that this needs no code. It is now a test that walks the owner's actual
journey — create, paste a key, test the connection, refresh the catalog, enable a model, chat —
against an invented company, and then reads every PHP file under `app/` and fails if that
company's name, slug or address appears in any of them. Nothing but a code change can make that
assertion fail, which is the only way to state "no code change" as a fact.

The presets are convenience and are proved to be: the provider in that test uses none.

### What an owner can point a provider at, deliberately not restricted

The API address is whatever the owner types, including a private address. Blocking those would
break the case this phase exists to serve — a model server the owner runs themselves, on
localhost or inside their own network, is an OpenAI-shaped provider like any other. The screen is
reachable only by an administrator with `providers.manage`, which is the same authority that could
add any provider at all.

### Two gaps the phase found in earlier work

**A searchable select was never 44px.** Filament replaces a searchable `Select` with a button
carrying a different class, so the rule covering `.fi-select-input` never touched it: 36px, on
every screen with a searchable select, since Phase 1. The provider form was the first such screen
the responsive gate visits — the gate can only find what it is pointed at, so the screen was added
along with a way to open a form section that is hidden until someone interacts. Both the preset
picker and the custom-API mapping builder are now measured at six viewports.

**`secret()` had grown a third caller with nothing checking.** CLAUDE.md stated "exactly two
callers" as a fact, and a fact in a document is a fact until someone adds a third. The list of
everything that reads a credential back in plaintext is now pinned by a test that strips comments
first, so documenting the rule cannot trip it and adding a caller cannot pass quietly.

## Still owed from Phase 6 — verified during Phase 7, and delivered after it (see below)

Phase 7 included a check the owner asked for: **is native gateway auto-renewal required by the
approved plan?** It is not. No requirement in `docs/01-requirements-register.md` (PG-1..PG-25) asks
for it, and Addendum D §3 explicitly treats manual renewal as a deliberate choice with different
customer messaging. The Phase 6 gate item that *is* required — a subscription is never routed to a
gateway lacking recurring capability — is implemented and tested.

Two things from Phase 6's own build list are genuinely missing, and are recorded here rather than
left to be discovered:

1. **The renewal invoice and payment link.** Addendum D §3 says a manual-renewal customer
   "receives an invoice and a payment link each period". Nothing sends one: the scheduled work is
   `subscriptions:advance`, which applies downgrades and expires lapsed subscriptions. A
   manual-renewal subscription therefore ends quietly, and the customer's first signal is losing
   access. Needs a scheduled job that issues the next period's invoice ahead of expiry and a
   reminder carrying its payment link.

2. **The notification system, templates and announcements** (§22, listed in Phase 6's build).
   There is no `app/Notifications` and no `app/Mail`; the only mail in the product is email
   verification. Every "we will email the customer" in the billing flow is currently unimplemented,
   which also blocks item 1.

Both are billing-facing and neither was a Phase 7 concern. **Both were built immediately after
Phase 7 was approved, before any of Phase 8 was started** — the section below records how.

## Closing the Phase 6 gaps — manual renewal and the notification system

Built after Phase 7 was approved and before Phase 8 was started, because a subscription that
cannot be renewed and a platform that cannot tell anybody anything are not features to schedule —
they are two halves of one hole.

### An announcement is a message, not a second banner

The first design put announcements on the page. It was wrong, and the reason is worth keeping:
Aziv AI already has a banner. `Banner` (Phase 2) owns the strip at the top of a page, its
priority, its audience and its per-browser dismissal. A second strip would have meant two
audiences, two dismissals, and two ways to push content below the fold on a phone — and the second
one is always the one that forgets a rule.

What §22 asks for and a banner cannot do is REACH PEOPLE: an audience defined by their
subscription, delivered in the app and by email, with a record that it went. So an announcement is
composed, targeted and sent, and it goes out through the same notifier as a renewal notice. The
banner is placement; the announcement is people. A test asserts the announcement never grows a
`priority`, a `cta_url` or a `is_dismissible` — the columns it would need to become a banner.

### Every notification is declared before it can be sent

`NotificationEvent` is the catalogue, and it works the way the settings registry does: an
undeclared key THROWS rather than being delivered. A typo would otherwise become an email nobody
can find in the Admin Panel, nobody can edit, and no owner can switch off.

Each event declares its channels and its VARIABLES — a closed list with a plain-English meaning —
and the renderer substitutes those and nothing else. That is the security property: a template
cannot be edited into printing something it was never given, because a placeholder the event does
not provide is REMOVED rather than resolved. Every value that does reach a template is scrubbed by
the same `Redactor` the diagnostics layer uses, so a credential cannot reach an inbox even if a
caller passes one.

The one carve-out is a link back to this platform, and it is as narrow as it can be: absolute,
http(s), same host. A signed payment link ends in a long opaque signature, and the scrubber removes
long opaque strings — correctly, since that is also what a bearer token looks like. Scrubbing it
would have mailed customers a broken link, and nobody would have found out until one of them tried
to pay.

### Wording ships with the product; a database row is an override

Every event carries its own subject and body in code. An empty `notification_templates` table
therefore sends complete, correct email — a notification system that needs seeding before it works
is one that silently does nothing on a fresh install, which is exactly the failure mode this whole
delivery exists to remove. Deleting a template row restores the shipped wording; SWITCHING AN EVENT
OFF is a separate, deliberate act, because those are two different things an owner might mean.

### The delivery log records that, never what

`notification_deliveries` answers "did the renewal notice for invoice 42 go out, and did it fail?".
It cannot answer "what did it say". A rendered body carries a payment link, an amount and a
customer's name, and an audit table is read by more people than an inbox is. The error column is
scrubbed by the model on write, not at the call site — a mail driver's exception routinely carries
the SMTP password, and a scrub that has to be remembered is a scrub that gets forgotten.

### Manual renewal: what the lifecycle actually promises

Addendum D §3 said a manual-renewal customer "receives an invoice and a payment link each period".
Before this, that sentence was aspirational: the subscription simply stopped receiving credits and
the customer's first news of it was losing access. Now:

| when | what happens | what the customer gets |
|---|---|---|
| period end − notice days | the invoice is issued | the invoice and a signed payment link |
| period end, unpaid | `past_due` — still working | a reminder with the same link |
| period end + grace days | it ends | told that it has ended, and how to start again |
| paid, at any point | the ordinary payment path renews it | a receipt |

All three intervals are settings. `RenewalService` decides WHEN; the invoice still comes from
`InvoiceService`, the payment and gateway from `CheckoutService`, the renewal itself from
`SubscriptionService`. There is one billing system and this is not a second one.

### One invoice per period, and the guard is the database

Three things can reach the same period at the same instant: the nightly scheduler, an
administrator pressing "send the renewal now", and the customer opening their billing page.
Sequentially they all find the invoice the first one made. In parallel they can all find none and
all raise one — and three bills for one month, each with its own number, cannot be corrected by
deleting two, because an issued number cannot be un-issued.

So `invoices` has a unique index on (subscription, renewal period start). The test forks six
processes; with the index downgraded to an ordinary index it produces three invoices, which is
what makes it a proof rather than a decoration.

### The payment link is signed, dated, and says almost nothing

It is an HMAC over the URL under the application key — the same mechanism as the
email-verification link, chosen rather than invented so there is one way of proving "this person
received our email". It cannot be edited to point at another payment, it expires on its own, and
the routes behind it sit outside the auth group deliberately: the whole purpose is to work for
somebody reading their email on a phone they have never signed in on.

What the page shows is therefore kept to the four facts the email already carried — plan, amount,
invoice number, date. No name, no address, no email, no account. A forwarded email must not become
a window into somebody's account. A separate guard refuses a signed link pointed at an ordinary
checkout payment, whose result page belongs behind a login.

### A free plan renews itself

A plan that costs nothing has nothing to invoice, but it still has PERIODS — that is how its
allowance refreshes. Leaving it out would have meant a free customer's credits silently stopped
arriving with no bill to explain why. This was a real bug in Phase 6, found while building the
renewal path.

### Two lifecycles for ending a subscription, one implementation

A CANCELLED subscription ends when the time it paid for runs out: no grace, no chasing, because the
customer asked to stop. An UNPAID one ends after the grace period, having been invoiced and
reminded first. Both call `SubscriptionService::expire()`, so the act of ending cannot drift
between them, but they decide WHEN separately — and `expireLapsed()` was narrowed to cancellations
so the two schedules cannot race to close the same account, with the stricter one always winning
and quietly cancelling the grace period the owner configured.

### Email follows the owner's theme

Email clients strip stylesheets, ignore custom properties, and Gmail removes `<style>` blocks — so
the only colour that survives is one written into the element. `ThemeService::emailPalette()`
resolves the owner's tokens to plain values and the mail template inlines them. A white-label
owner's email therefore looks like their platform, and no mail template contains a colour of its
own, so Rule 1 holds there too.

### The check that catches the silent failure

Laravel's default mailer is `log`. Everything "sends" perfectly, every delivery is recorded as
sent, and not one message reaches anybody — so a renewal notice goes into a file, the subscription
lapses, and the first news of it is a customer asking why they lost access. `aziv:diagnose` now
reports it, graded by environment: on a developer's machine `log` is correct and a red would be
noise that teaches people to ignore the screen; in production it is critical. It reads the driver
name and whether a host and from-address exist — never the password, so Rule 4 holds by
construction.

### A Phase 6 gate caught this work

The renewal path first set `due_at` AFTER issuing the invoice, and the model layer refused it:
only `status` and `paid_at` may move once a document is issued. The due date is part of what the
invoice says, so it belongs on the draft. The immutability guarantee written in Phase 6 stopped a
Phase 8-era mistake the first time the test ran, which is the whole argument for writing guarantees
as code rather than as review notes.

### What was deliberately NOT built

**Per-customer notification preferences.** §22 does not ask for them, and inventing an
unsubscribe surface for transactional billing mail is a decision with legal weight in several
jurisdictions. There is a single global switch (`notifications.email_enabled`), which is
configuration; per-customer opt-out is a decision for the owner to ask for.

**Push notifications.** §22 calls them future-ready, and they are: the in-app half uses Laravel's
own notification table, so a push channel is added to the notifier rather than built beside it.
