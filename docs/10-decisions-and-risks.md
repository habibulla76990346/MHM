# Aziv AI — Decisions Needed & Honest Risks

> **The live status of every decision is in [`12-decision-log.md`](12-decision-log.md).** That
> file is authoritative; this one explains the reasoning behind each option.

## Part 1 — Decisions I need from you

I have given a recommendation for each. **If you agree with all of them, you can simply say
"approved" and I will proceed on the recommendations.** Where a decision is not needed until a
later phase, that is marked — those need not hold up Phase 1.

---

### D-01 · Payment gateway — *needed by Phase 6*

Blueprint §20 deliberately does not name one: the right choice depends on your country, your
business structure, and the currencies you sell in.

| Option | Best for | Notes |
|---|---|---|
| **Stripe** | Most countries, most currencies | Best developer support; **not available in every country** |
| **Paddle** | Selling software internationally | Acts as merchant of record — **it handles sales tax/VAT for you**, a substantial administrative saving |
| **Razorpay** | India | Strong local payment method coverage |
| **PayPal** | Widest consumer familiarity | Weaker subscription tooling |
| **Local gateway** | Region-specific requirements | Needs its own adapter |

**What I need from you: the country your business is registered in, and the currency you intend
to charge in.** I cannot recommend responsibly without those two facts.

**Recommendation:** build the gateway as a pluggable interface either way, with the first
implementation chosen once you answer. That is already in the architecture, so this decision
cannot block earlier phases.

---

### D-02 · Filament for the admin panel — *needed by Phase 2*

Discussed fully in `03-architecture.md` §6. Roughly 90 admin screens; Filament supplies the
table/form/validation machinery on exactly the Blade + Livewire + Alpine + Tailwind stack the
blueprint specifies.

**Recommendation: yes.** The alternative is several times the work and more bugs.
**Trade-off: it is a major dependency with periodic upgrade effort.**

---

### D-03 · Vector storage for file/RAG search — *needed by Phase 8*

| Option | Good for | Cost | Trade-off |
|---|---|---|---|
| JSON in MySQL + PHP similarity | Small knowledge bases (< ~5k chunks) | Free | Slows noticeably as it grows |
| **MySQL 9 `VECTOR` type** | Medium | Free | Requires MySQL 9 on your host |
| **PostgreSQL + `pgvector`** | Large, production | Free | Means running PostgreSQL |
| Dedicated service (Qdrant, Pinecone) | Very large | Monthly fee | Another system to run and pay for |

**Recommendation:** start with the MySQL approach for the MVP; the `embeddings` table is designed
so the backend can be swapped without a rewrite. Revisit at Phase 8 based on your real data size.

---

### D-04 · Hosting — *affects Phase 1, decide early*

**This is the most consequential technical decision on the list.**

| | Shared hosting (~$5–15/mo) | **VPS / cloud (~$20–60/mo)** |
|---|---|---|
| Chat streaming | ✗ Usually broken by output buffering | ✓ Works |
| Background queues | ✗ Unreliable without a persistent worker | ✓ Works |
| Redis | Rarely available | ✓ Available |
| Large file processing | ✗ Execution time limits | ✓ Works |
| Long AI requests | ✗ Timeouts | ✓ Works |

**Recommendation: a VPS.** Blueprint §29 already anticipates this. The difference is roughly
$30/month, and shared hosting cannot deliver the streaming chat experience §15 describes. The
application will still *run* on shared hosting, in a visibly degraded non-streaming mode.

---

### D-05 · Launch point — *decide before Phase 6*

Early launch after Phase 6, or full launch after Phase 9? Comparison table in
`09-development-phases.md`. **Recommendation: early launch** — real user feedback improves what
gets built in Phases 7–9.

---

### D-06 · Tailwind build tooling — *minor, needed by Phase 0*

Standard Node-based build, or Tailwind's standalone executable (no Node at all)?
**Recommendation: standard Node build** — better tooling and documentation. Node is required only
on the build machine, never on your server. Say the word and I will use the standalone binary
instead; the deployed result is identical.

---

### D-07 · Admin panel theming — *needed by Phase 2*

The customer-facing site gets the full eight-theme, ~95-token system from §5. Should the admin
panel also be fully re-skinnable?

**Recommendation: no.** The admin panel receives your logo and brand colours, but not the full
theme engine. It is used by a handful of staff, and full theming there is significant work for
little return. **Flagging it because §5 could be read as covering the whole platform — tell me if
you want it and I will scope it in.**

---

### D-08 · Malware scanning for uploads — *needed by Phase 8*

Blueprint §17 lists an *optional* security scan. Options: ClamAV self-hosted (free, needs a VPS
and memory), a commercial scanning API (per-file fee), or type/size validation only.

**Recommendation:** strict type, size and content validation for the MVP; add ClamAV before
opening uploads to untrusted public users.

---

### D-09 · Brand details — *needed by Phase 2*

Confirm the product name is exactly **"Aziv AI"**, and provide your domain, logo files (or
approval to use placeholders), brand colours, support email and company/legal details.

**Placeholders are entirely fine** — every one of these is editable in the Admin Panel afterwards
without a developer. This should not delay anything.

---

### D-10 · How far to take PWA now — *needed by Phase 2*

You asked for PWA-*ready* structure, not a native app. The manifest, icons, theme colour and
installability ship in Phase 2 either way. The open question is the service worker.

| Option | Effect |
|---|---|
| **Manifest + icons + installability only** | Aziv AI installs to a home screen and launches without browser chrome |
| Add app-shell service worker | Also opens instantly and shows a proper offline screen instead of a browser error |
| Full offline mode | Not meaningful — AI responses are generated per request and cannot be cached |

**Recommendation:** manifest and installability in Phase 2; **app-shell service worker in Phase 9**,
once the asset set has stopped changing. Adding it earlier means fighting cache invalidation for
the whole build. Full offline is not proposed, because an AI platform is inherently online.

---

### D-11 · Mobile bottom navigation — ✅ **APPROVED BY OWNER**

**Chat · Library · Images · Account**, with less frequent features under *More* / the drawer.

Owner additions accepted: navigation stays **admin-configurable** (labels, icons, ordering,
visibility, destination) where technically appropriate, and the bottom bar must not interfere with
the chat composer, keyboard, safe-area insets or scrolling.

Implementation and the four honest limits on "where technically appropriate" are recorded in
`12-decision-log.md` and `11-responsive-design-system.md` §3.

---

## Part 2 — Risks stated honestly

### R-01 · Streaming needs proper hosting — **high impact, fully mitigable**

Live word-by-word responses require the server to send output progressively and hold a connection
open. Shared hosting typically buffers output and enforces short timeouts, which breaks this.

**Mitigation:** use a VPS (D-04). A non-streaming fallback ships regardless, so the platform
degrades rather than fails — but it is a visibly worse experience, and it is the feature users
most associate with a modern AI product.

### R-02 · Node.js at build time — **low impact, already resolved**

Rule 1 forbids silently switching to Node. This plan does not. Tailwind uses Node to compile CSS
on the build machine; the deployed application is pure PHP/Laravel and your server never runs
Node. D-06 offers a route that removes Node entirely if you prefer.

### R-03 · AI provider costs are yours from Phase 3 — **medium, controllable**

The moment real API keys are added, every request costs real money — including my testing.
Development testing typically costs a few dollars per phase, not hundreds.

**Mitigation:** provider budgets and threshold actions (§21) are built in Phase 5, before any
real traffic. Set low provider-side spending caps from day one as a second safety net.

### R-04 · Provider APIs change without warning — **medium, structurally mitigated**

AI companies change endpoints, deprecate models and alter response shapes on their own schedule.

**Mitigation:** this is precisely why the adapter architecture exists — a provider change is
isolated to one class. Model sync marks vanished models deprecated rather than deleting them, so
your historical cost data survives. Expect occasional maintenance; it is a normal cost of
integrating with third parties.

### R-05 · Multiple API keys — **compliance risk, resolved by design**

§10 permits multiple credentials for redundancy; Rule 7 forbids using them to bypass quotas.
These are close enough that an implementation could drift across the line.

**Mitigation:** per-credential usage tracking, an explicit in-panel statement of intent, and
**no automatic key-cycling on quota-exhaustion errors** — the one behaviour that would serve no
purpose other than the prohibited one. Full reasoning in `07-ai-provider-architecture.md` §5.

### R-06 · `APP_KEY` loss destroys stored credentials — **high impact, easily prevented**

Provider API keys are encrypted with Laravel's `APP_KEY`. A database restored without its
matching `APP_KEY` cannot decrypt them, and every provider key must be re-entered.

**Mitigation:** the Phase 9 backup procedure treats `APP_KEY` as part of the backup, and the
restore drill verifies decryption actually works.

### R-07 · Free tiers are not a business model — **strategic, be aware**

Free provider tiers carry hard quotas and can be withdrawn at any time. A "Free Only" routing
mode (§14) is genuinely useful for a free customer plan, but it cannot be the foundation of paid
service levels.

**Mitigation:** free-tier quotas are surfaced in the panel, and free models are classified
distinctly so plan design stays realistic.

### R-08 · Blueprint scope is large — **schedule risk, managed by phasing**

30 sections and roughly 62 tables is a substantial product — larger than most first releases.
Nothing has been removed, per Rule 2.

**Mitigation:** the phasing delivers a usable product at Phase 4 and a revenue-generating one at
Phase 6. If a phase turns out larger than estimated, you will hear it at that phase's stop point
rather than at the end.

### R-10 · Adaptive design costs more than responsive design — **schedule, accepted deliberately**

Building genuinely different layouts per device class — rather than one flexible layout — adds
**5 to 8 sessions**, concentrated in Phases 1, 2, 4, 6 and 8. Per-phase detail in
`11-responsive-design-system.md` §12.

**Mitigation:** none needed, and none proposed — this is a deliberate purchase. Retrofitting
adaptive layouts later costs several times more, because every component must be reopened and the
design system fractures in the process. Requiring it before any code exists is the cheapest this
will ever be.

### R-11 · The mobile chat composer is the hardest surface in the product — **high, planned for**

Mobile keyboards resize the viewport unpredictably across iOS and Android. Get it wrong and the
input hides behind the keyboard, the page zooms on focus, or the view yanks to the bottom while
someone is reading. These are the defects users notice immediately and forgive least.

**Mitigation:** the specific techniques are pinned down in advance rather than discovered during
the build — `dvh` units, `visualViewport` tracking, safe-area insets, a 16px minimum input size to
stop iOS zoom, and scroll anchoring that only follows the stream when the user is already at the
bottom. Each has an automated test at six viewports.

### R-12 · Three admin surfaces are genuinely desktop-first — **medium, signposted not hidden**

The theme colour editor (~95 tokens × light/dark), the cost/margin analytics grid, and the custom
provider mapping builder are dense, multi-column tasks. Every admin screen will be *usable* on a
phone — navigable, readable, no overflow, every record editable — but these three are more
comfortable on a large screen.

**Mitigation:** each gets a deliberate mobile form (tabbed light/dark, summary cards with
drill-down, read-and-test-only for JSON mapping) and the panel says so, rather than presenting a
cramped grid and letting an admin discover the problem mid-task. Detail in
`11-responsive-design-system.md` §9.

### R-13 · Shared hosting becoming permanent — **medium, and the real one to watch**

D-04 puts development and staging on cPanel deliberately, with production on cloud/VPS later. The
risk is not technical — the portability architecture handles it. The risk is **inertia**: once it
works well enough, the migration keeps getting postponed, and streaming chat stays degraded
permanently.

**Mitigation:** the capability matrix in `13-deployment-portability.md` §2 states plainly which
features are affected, the migration is a 10-step checklist rather than a project, and Phase 9
includes the migration as a deliverable rather than an optional extra.

### R-14 · Outbound HTTPS blocked on shared hosting — **would be fatal, check first**

A small number of shared hosts block outbound connections by default. Aziv AI is an application
whose entire purpose is calling external AI APIs.

**Mitigation:** this is check **E-2**, verified in the first minutes of Phase 0 before anything is
built. If it is blocked and cannot be lifted, hosting must change before development starts.

### R-15 · GST compliance is a business obligation, not just a schema — **medium, bounded**

D-01 (India) means GST applies, and Razorpay does not file it for you the way a merchant-of-record
service would.

**Mitigation:** all GST fields are in the invoice schema from Phase 1, and tax rules are
configurable rather than hard-coded, so rates and treatment are set without code changes. **I am
not a tax adviser** — the software will support what Indian GST requires; your accountant confirms
the rates, registration status and export treatment. Recorded as decision D-12.

### R-16 · A single payment gateway is a single point of revenue failure — **resolved by Addendum D**

Gateways have outages. Merchant accounts get held for review, sometimes for days, sometimes without
warning. Settlement terms and success rates differ by payment method and bank.

**Mitigation:** the multi-gateway architecture means a second gateway can be enabled from the Admin
Panel in minutes once its adapter exists. **A platform that can only take money one way stops
earning entirely when that way is unavailable** — which is the actual argument for building the
framework rather than a single integration.

### R-17 · Indian recurring payments are not one mechanism — **medium, designed around**

Recurring card and UPI payments in India run under RBI rules covering e-mandates, additional-factor
authentication and tokenisation. Depending on gateway and payment method, renewal may use a native
subscription API, UPI Autopay, e-NACH, or manual invoice-and-pay. Gateways differ in what they
support, and the rules change.

**Mitigation:** capability-aware gateway selection — **a subscription is never routed to a gateway
that cannot renew it**, and `subscriptions.renewal_mechanism` records which mechanism is in use
rather than assuming one. Each adapter's real capabilities are verified against current provider
documentation at implementation time and stored as data, so the platform stays correct without code
changes.

### R-18 · A web installer is a serious attack surface — **high if done carelessly, controlled here**

Because SSH is not assumed, Aziv AI ships a browser-based installer. An unlocked installer on a
live site is a full compromise: it can rewrite `.env` and create an admin account.

**Mitigation:** it refuses to run unless *both* the install marker is absent and the database has no
tables; it self-locks on completion and the route then returns 404; it is deletable and the
application runs fine without it; it is rate limited; it never echoes credentials; and it warns
prominently over plain HTTP. The command-line route stays available and documented as preferred
where SSH exists.

### R-19 · Presentment currency is not settlement currency — **medium, designed for**

Showing a price in USD and receiving USD are different things. An Indian merchant account typically
settles in INR whatever the customer was shown, at the gateway's own rate plus a cross-border fee.

**Mitigation:** every payment stores three amounts — presentment, settlement and base — so
customer-facing records, gateway reconciliation and margin analytics each use the right one.
**Whether a specific gateway and merchant account can take international payments at all is a fact
about your merchant agreement**, configured by the admin, never assumed by the software.

### R-20 · International tax is not automatable, and Aziv AI will not pretend otherwise — **medium, scoped honestly**

Cross-border digital-services tax involves EU VAT rules, place-of-supply tests, reverse charge, US
state nexus thresholds and India's own export-of-services treatment — each with registration
obligations that depend on the business, not the software.

**Mitigation:** Aziv AI provides a **configurable tax engine, not tax advice.** It applies whatever
the admin configures, records the full computation permanently, and captures every field an
accountant needs. It does **not** decide jurisdictions, determine correct rates, track thresholds or
file returns. The engine is structured so an external tax-determination service can be integrated
behind the same interface later, using the same adapter pattern as AI providers and gateways.

### R-21 · A stale SQL export installs a schema the code does not expect — **medium, prevented mechanically**

The owner requires both migrations and a clean SQL export. Maintained separately, they drift, and a
stale export is worse than none.

**Mitigation:** the SQL files are **generated, never hand-edited** — a build command runs migrations
and seeders on a scratch database, exports the result and stamps it with the migration checksum.
Installation verifies that stamp and **refuses to run on a mismatch** rather than half-installing.

### R-09 · Realtime voice and video are deferred — **already scoped by the blueprint**

§18 itself describes realtime voice as "a separate module" and video as adapter-ready "later."
This plan honours that: standard STT/TTS ships in Phase 8; realtime voice and video are
architecture-ready but not built. **Flagging it explicitly so there is no later surprise about
what "voice" includes.**

---

## Part 3 — What only you can provide (§28, §29)

Blueprint §28 is explicit that some things cannot be done from an Admin Panel. Here is your
complete list, so nothing arrives as a surprise:

| Item | When | Why it must be you |
|---|---|---|
| OpenAI API key | Phase 3 | Created in your own OpenAI account |
| Google Gemini API key | Phase 3 | Created in your own Google account |
| Payment gateway account | Phase 6 | Requires your business identity and bank details |
| Additional provider keys | Phase 7 | Each provider's own dashboard |
| Domain name | Phase 9 | Registered and owned by you |
| Production hosting | Phase 9 | Billed to you, controlled by you |
| SSL certificate | Phase 9 | Usually free and automatic via your host |
| SMTP / email sending account | Phase 2+ | Postmark, SES, Resend or similar |
| Object storage (optional) | Phase 8 | S3 or compatible, for scale |

Per §29, **all of these should remain in your name and under your control** — including the code
repository. You should never be dependent on a developer's accounts, mine included.

## Part 4 — Running costs to expect

| Item | Typical monthly |
|---|---|
| VPS hosting | $20–60 |
| Domain | ~$1–2 (annual, amortised) |
| Email sending | $0–15 (free tiers cover early volume) |
| Object storage | $0–10 (only at scale) |
| **AI provider usage** | **Variable — your largest cost, and it scales with customers** |
| Payment gateway | ~2.9% + fixed fee per transaction |

AI usage is the number that matters, and it is why §21's cost-vs-revenue analytics are built into
Phase 5 rather than added later: **you need to know your margin per customer before you scale,
not after.**
