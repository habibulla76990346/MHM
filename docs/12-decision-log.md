# Aziv AI — Decision Log

The single authoritative record of every decision, its status and who made it. Maintained from
here on: no decision is considered settled unless it appears below as **APPROVED**.

**Nothing in Phase 0 or Phase 1 begins until every decision marked `BLOCKS PHASE 0/1` is resolved.**

---

## Status summary

| Status | Count | Meaning |
|---|---|---|
| ✅ **APPROVED** | 1 | Owner has decided. Locked in. |
| 🔴 **NEEDS OWNER INPUT** | 2 | Cannot proceed without a fact or choice only the owner has |
| 🟡 **RECOMMENDED — awaiting confirmation** | 8 | My recommendation stands; silence is not approval |

---

## ✅ Approved

### D-11 · Mobile bottom navigation — **APPROVED**

**Decision:** Bottom bar carries **Chat · Library · Images · Account**. Less frequent features
live under *More* / the drawer.

**Owner additions accepted:**

1. Navigation must remain **fully admin-configurable in future** — labels, icons, ordering,
   visibility and destination, *where technically appropriate*.
2. The bottom bar must remain touch-friendly and must not interfere with the chat composer,
   keyboard, safe-area insets or scrolling.
3. Owner Addendum A requirements remain exactly as documented.

**How this is implemented:** navigation becomes **data, not code** — `navigation_menus` and
`navigation_items` rows drive every device class, so changes need no developer. Full design in
`11-responsive-design-system.md` §3.

**The four honest limits on "where technically appropriate"**, recorded so they are not a surprise
later:

| Limit | Reason |
|---|---|
| Bottom bar capped at **4 items + More** | At 320px, six tabs give ~53px each and seven fall below the 44px touch minimum. The admin chooses *which* four and their order, not how many |
| Items cannot link to destinations that do not exist | Routes validated against the real route list |
| A nav permission **hides a link, it does not grant access** | The destination's own policy still governs. A hidden item is not a security control |
| Every route to account settings cannot be hidden at once | Would strand users with no way to manage their own subscription. Panel warns and blocks this case |

---

## 🔴 Needs owner input — these block Phase 0/1

### D-04 · Hosting — **BLOCKS PHASE 0/1**

Blocking because it determines the database, cache and queue configuration written in Phase 0,
and whether streaming chat is built as the primary path or the fallback.

Four realistic routes, costed:

| Option | Cost/mo | Streaming | Queues | Server admin burden | Verdict |
|---|---|---|---|---|---|
| **Managed Laravel platform** (Laravel Cloud, Ploi, Forge + droplet) | **$25–75** | ✅ | ✅ | **Lowest — deploys, SSL, backups handled** | **Recommended for a non-developer** |
| Plain VPS (Hetzner, DigitalOcean, Vultr) | $12–40 | ✅ | ✅ | Highest — you or I configure everything | Cheapest that works properly |
| Shared hosting | $5–15 | ❌ Usually broken | ❌ Unreliable | Low | Not recommended |
| Serverless/PaaS (Vercel-style) | Varies | Partial | ❌ | Low | Poor fit for long-lived PHP streams |

**Recommendation: a managed Laravel platform.** You are not a developer, and the difference
between this and a plain VPS is not the ~$20/month — it is who handles SSL renewal, security
patches, deploy failures and backups at 2am. On a plain VPS that is a developer's job, and you
would need one on retainer. On a managed platform it is the platform's job.

Blueprint §29 already anticipates moving off shared hosting for streaming, queues and large file
processing. This is that decision.

**What I need:** which of the four, or a "you choose" and I will proceed on the recommendation.

---

### D-01 · Payment gateway — **BLOCKS PHASE 0/1 only for currency; gateway itself is needed by Phase 6**

The gateway *implementation* is not needed until Phase 6. But **your country and currency are
needed in Phase 1**, because they set the default currency, tax display and money-column
precision in the database schema — and changing those after billing data exists is painful.

Once you name the country, the gateway follows almost mechanically:

| If your business is registered in | Recommended gateway | Why |
|---|---|---|
| **United States, Canada, Australia, Singapore** | **Stripe** | Best subscription tooling and documentation; available and mature |
| **United Kingdom or EU** | **Paddle** or Stripe | Paddle acts as merchant of record and **handles VAT/sales-tax filing for you** — a large administrative saving for a small business selling internationally |
| **India** | **Razorpay** | Local method coverage (UPI, netbanking); Stripe's India support is more limited |
| **Bangladesh, Pakistan, Nigeria, and much of South Asia / Africa** | **Paddle** or a local gateway | Stripe is often unavailable to businesses registered there. Paddle can sell on your behalf; otherwise a local gateway plus its own adapter |
| **Anywhere Stripe does not serve** | **Paddle** | Merchant-of-record models exist precisely for this case |

**What I need — two facts only you have:**

1. **The country your business is registered in** (not where you live, if they differ — gateway
   eligibility follows registration)
2. **The currency you intend to charge customers in**

I will not guess these. Guessing the country risks planning around a gateway you cannot legally
sign up for, and guessing the currency risks a schema change after real money has moved through it.

**Note:** the architecture keeps the gateway pluggable regardless, so this decision cannot trap
you. Switching later means writing one adapter, not rebuilding billing.

---

## 🟡 Recommended — awaiting confirmation

Silence is not approval. Each of these proceeds on my recommendation only if you say so.

| ID | Decision | My recommendation | Needed by |
|---|---|---|---|
| **D-02** | Filament for the admin panel | **Yes** — ~90 admin screens on exactly the blueprint's stack. Trade-off: a major dependency needing an upgrade every year or two | Phase 2 |
| **D-03** | Vector storage for document search | **Start with MySQL**; the schema allows swapping the backend later without a rewrite | Phase 8 |
| **D-05** | Launch after Phase 6 or Phase 9 | **Launch after Phase 6.** Nothing is skipped; Phases 7–9 happen with real customers already using it | Phase 6 |
| **D-06** | Tailwind build tooling | **Standard Node build** — better tooling. Node never touches your server; deployed result is identical either way | Phase 0 |
| **D-07** | Admin panel fully themeable | **No** — it gets your logo and brand colours, not the full 95-token engine. Flagged because §5 could be read as covering everything | Phase 2 |
| **D-08** | Malware scanning for uploads | **Strict type/size validation for launch**, real scanning before opening uploads to the public | Phase 8 |
| **D-09** | Brand details | **Placeholders are fine** — all editable in the panel later. Confirm the name is exactly "Aziv AI" | Phase 2 |
| **D-10** | PWA depth | **Installability in Phase 2, service worker in Phase 9.** Full offline not proposed: AI answers cannot be cached, and caching customer data creates privacy risk | Phase 2 |

---

## What happens when the blockers clear

Once **D-04** is answered and **D-01**'s two facts are known:

1. I confirm the final decision list back to you in this same shape
2. **Phase 0** begins — install the database, create the Laravel 13 project, wire up Livewire 4,
   Filament 5, Spatie Permission 8, the six-breakpoint Tailwind scale, and the Playwright
   responsive test harness
3. Phase 0 ends with a test gate and a stop point before Phase 1

No application code is written before that point.

---

## Change history

| Date | Change |
|---|---|
| Initial plan | D-01 … D-09 raised |
| Owner Addendum A | D-10 (PWA depth) and D-11 (bottom navigation) added |
| Owner decision | **D-11 APPROVED** with admin-configurable navigation and bottom-bar behaviour constraints |
