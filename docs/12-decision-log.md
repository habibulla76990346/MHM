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
