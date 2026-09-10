# Aziv AI — Multi-Gateway Payment Architecture

**Status: OWNER ADDENDUM D** — added to the requirements baseline at the owner's instruction,
amending decision D-01. Razorpay is the **initial default** gateway for India, not the only one.
Its 25 requirements are tracked as `PG-1 … PG-25` in the requirements register, to avoid confusion
with decision IDs `D-01 … D-12`.

> The owner's governing constraint: *"Do not hard-code Razorpay-specific logic into subscriptions,
> plans, invoices or transactions."*

---

## 1. The design: payments mirror the AI provider manager

Aziv AI already solves this exact problem once — many external providers, different APIs, one
internal format, admin-controlled, adapter-based, no code changes to add a provider. That is the
Universal AI Provider Manager in `07-ai-provider-architecture.md`.

**The payment layer uses the same architecture.** This is not a coincidence to be noted; it is a
deliberate reuse, and it means:

- the patterns are already proven in this codebase,
- the Admin Panel screens work the same way, so there is one thing to learn, not two,
- encrypted credentials, connection testing, priority ordering, enable/disable and health tracking
  are the same mechanisms.

```
                  ┌────────────────────────────────────────┐
                  │   Aziv AI internal payment format      │
                  │   CheckoutRequest / CheckoutSession    │
                  │   TransactionStatus / RefundResult     │
                  │   WebhookVerification / GatewayError   │
                  └────────────────────────────────────────┘
                                      │
        ┌───────────┬─────────────┬───┴────┬──────────────┬─────────────┐
        ▼           ▼             ▼        ▼              ▼             ▼
   Razorpay     PhonePe        PayU    Cashfree      CCAvenue     Future
    Adapter     Adapter       Adapter   Adapter       Adapter     adapters
        │           │             │        │              │             │
        ▼           ▼             ▼        ▼              ▼             ▼
    Razorpay     PhonePe        PayU    Cashfree      CCAvenue    Stripe, Paddle,
      API          API           API      API            API      PayPal, local…
```

**Subscriptions, plans, invoices, credits and the ledger never reference a gateway by name.** They
call the interface. A gateway is a row in a table with an adapter class attached.

---

## 2. The gateway contract

```php
interface PaymentGateway
{
    public function key(): string;                       // 'razorpay', 'phonepe', …
    public function capabilities(): array;
    public function supportedCurrencies(): array;
    public function supportedCountries(): array;
    public function checkoutMode(): CheckoutMode;        // redirect | hosted | sdk_modal | s2s

    public function createCheckout(CheckoutRequest $r): CheckoutSession;
    public function verifyReturn(array $payload, array $headers): ReturnResult;
    public function verifyWebhook(string $rawBody, array $headers): WebhookVerification;
    public function fetchTransaction(string $gatewayRef): TransactionStatus;   // authoritative
    public function refund(RefundRequest $r): RefundResult;
    public function testConnection(): TestResult;
}
```

### Capability contracts

A gateway declares what it can actually do. Nothing assumes every gateway does everything.

| Contract | Meaning |
|---|---|
| `SupportsOneTimePayment` | Single charges |
| `SupportsSubscriptions` | Native recurring billing managed by the gateway |
| `SupportsMandates` | UPI Autopay / e-NACH / standing instruction for auto-debit |
| `SupportsRefunds` / `SupportsPartialRefunds` | Refund handling |
| `SupportsTokenization` | Card-on-file tokens (mandatory in India for stored cards) |
| `SupportsInternational` | Non-INR / cross-border acceptance |
| `SupportsPaymentLinks` | Hosted link-based collection |

### Checkout modes — why this matters

The five named gateways do not present checkout the same way. Some redirect the browser away, some
open a JavaScript modal over your page, some post server-to-server. The adapter declares its mode
and the **checkout wrapper handles all of them behind one consistent Aziv-branded flow**, so the
customer experience does not change when you switch gateways — which is exactly what the owner
required.

| Mode | Behaviour | Aziv's wrapper |
|---|---|---|
| `sdk_modal` | Gateway JS opens a modal over Aziv's page | Aziv page stays visible; modal opens on it |
| `redirect` | Browser leaves for the gateway's page | Aziv shows a branded "taking you to payment" interstitial, then returns to a branded result page |
| `hosted` | Gateway-hosted payment page | Same as redirect |
| `s2s` | Server-to-server, Aziv collects nothing sensitive itself | Aziv page throughout |

---

## 3. Capability-aware gateway selection

This is the payment equivalent of the AI router's capability guard, and it prevents a real class of
failure.

**Not every gateway supports recurring billing to the same degree.** Some are primarily one-time
payment processors; some offer full subscription APIs; some support auto-debit only through
mandates. If a monthly subscription were routed to a gateway that cannot do recurring, it would
either fail at checkout or silently become a one-off payment that never renews.

So gateway selection filters on capability first:

```
Payment request
   │
   ├─ What does this need?  one-time │ recurring │ mandate │ refund
   │
   ├─ Candidate gateways:  enabled
   │                     ∧ mode matches (sandbox/live)
   │                     ∧ declares the required capability
   │                     ∧ supports this currency
   │                     ∧ supports this country
   │                     ∧ allowed for this payment type by admin rule
   │                     ∧ credentials present and verified
   │
   ├─ Order by:  admin rule → priority → default gateway
   │
   └─ No capable gateway?  ──►  fail loudly at configuration time,
                                never silently downgrade a subscription
                                to a one-time payment
```

> **A subscription is never routed to a gateway that cannot renew it.** Where a plan must be sold
> through a one-time-only gateway, it is explicitly configured as **manual renewal** — the customer
> receives an invoice and a payment link each period. That is a deliberate admin choice with
> different customer messaging, never an accident.

### Indian recurring payments — a note the architecture must respect

Recurring card and UPI payments in India operate under RBI rules covering e-mandates, additional
factor authentication, mandate registration and limits on auto-debit without re-authentication.
Card-on-file tokenisation is required for stored cards.

The practical consequence for Aziv AI: **"subscription" is not one mechanism.** Depending on the
gateway and the payment method, renewal may run through a native subscription API, a UPI Autopay
mandate, an e-NACH mandate, or manual invoice-and-pay. The `subscriptions` schema therefore records
`renewal_mechanism` and `mandate_reference` alongside the gateway reference, rather than assuming a
single model.

> **Each adapter's real capabilities are verified against that gateway's current documentation at
> implementation time**, and recorded in the `payment_gateways.capabilities` column — not assumed
> from this document. Gateway APIs and Indian regulation both change, and a capability table
> written today would be stale by the time an adapter is built. The architecture reads capabilities
> from data precisely so this stays correct without code changes.

---

## 4. Preventing duplicate payments and duplicate activation

The owner called this out explicitly, and it is the part of any payment system most likely to cost
real money when it goes wrong. Four independent mechanisms:

### 4.1 Server-side reconciliation — never trust the browser

A customer returning from a gateway with "payment successful" in the URL proves nothing. That
redirect can be replayed, tampered with, or simply lost when someone closes the tab.

**Three paths converge on one idempotent handler:**

| Path | When | Authority |
|---|---|---|
| **Return callback** | Customer's browser comes back | Signature verified, then **the server calls `fetchTransaction()`** and believes only that |
| **Webhook** | Gateway notifies server-to-server | Signature verified, then reconciled the same way |
| **Scheduled sweep** | Every few minutes | Queries any payment still `pending` past a threshold |

The sweep is what makes the system correct when a customer closes the browser mid-payment and the
webhook is delayed or lost. Without it, money is taken and nothing is delivered.

### 4.2 Idempotency at four levels

| Level | Mechanism |
|---|---|
| Webhook events | `payment_webhook_events` — **unique (gateway_id, event_id)**. A replay is a no-op |
| Payment creation | `payments.idempotency_key` unique — a double-submitted checkout reuses the same payment |
| Credit granting | Ledger entry keyed to the payment; a second attempt finds it and stops |
| Subscription activation | **Unique (subscription_id, period_start)** — a period cannot be activated twice |

### 4.3 Row-level locking

Activation runs inside a database transaction with `SELECT … FOR UPDATE` on the subscription row,
so two simultaneous webhooks cannot both read "not yet active" and both activate.

### 4.4 Signature verification, done correctly

Every gateway signs its webhooks differently, but one rule is universal and easy to get wrong:

> **Signatures are computed over the raw request body.** Verification must use the exact bytes
> received, before any JSON parsing or re-encoding. Parsing and re-serialising changes whitespace
> and key order, and the signature will fail — or worse, a lax implementation will skip verification
> to "fix" it.

Middleware preserves the raw body for the webhook route. Verification is **mandatory for every
gateway** — an adapter that cannot verify its webhooks does not ship.

---

## 5. Credentials and the frontend

The owner's requirement: *"Never store secret/API credentials in frontend code."*

There is a distinction worth stating precisely, because getting it wrong in either direction causes
problems:

| Key type | Example | Where it lives | Safe in frontend? |
|---|---|---|---|
| **Secret / API key** | `key_secret`, salt, merchant secret | Encrypted in database, server-side only | **Never.** Full account access |
| **Webhook secret** | Signing secret | Encrypted, server-side only | **Never** |
| **Publishable / merchant ID** | `key_id`, merchant ID | Database; rendered into the checkout page | **Yes — by design.** These identify the merchant to the gateway's own JS and are meant to be visible. They cannot authorise anything on their own |

Aziv AI keeps both in encrypted storage; only the publishable identifier is ever rendered, and only
into the checkout page that needs it. Secrets are excluded at the model level via `$hidden`, never
serialised into any API response, and displayed masked (last 4 characters) in the Admin Panel.

**Sandbox and live credentials are stored separately**, per gateway, and the active mode is an
explicit setting. Switching a gateway from sandbox to live is a deliberate, permission-gated,
audit-logged action with a confirmation step — because doing it accidentally means either taking
real money in a test, or failing to take real money in production.

---

## 6. Database schema

New and extended tables. Full context in `04-database-architecture.md`.

| Table | Purpose | Key columns |
|---|---|---|
| `payment_gateways` | The registry | uuid, key (unique), name, adapter_class, status, is_default, priority, mode (sandbox/live), supported_countries (json), supported_currencies (json), capabilities (json), checkout_mode, logo_media_id, maintenance_mode |
| `payment_gateway_credentials` | **Encrypted, per mode** | gateway_id, mode, label, credentials (**encrypted json**), webhook_secret (**encrypted**), publishable_key, status, last_verified_at, verified_by |
| `payment_gateway_rules` | Which gateway for what | gateway_id, payment_type (subscription/one_time/credit_topup), country, currency, plan_id, priority, is_active |
| `payment_webhook_events` | Idempotency + audit | gateway_id, event_id, **unique(gateway_id, event_id)**, event_type, raw_payload, signature_valid, processed_at, result, attempts |
| `payments` | Extended | + gateway_id, gateway_payment_id, gateway_order_id, mode, idempotency_key (unique), failure_code, failure_reason |
| `payment_transactions` | Extended | + gateway_id, gateway_transaction_id, gateway_reference, type, raw_payload |
| `refunds` | **New** | uuid, payment_id, gateway_id, gateway_refund_id, amount, currency, status, reason, requested_by, processed_at |
| `subscriptions` | Extended | + gateway_id, gateway_subscription_id, **renewal_mechanism**, **mandate_reference**, **unique(subscription_id, period_start)** on the activation guard |
| `gateway_health_logs` | Availability tracking | gateway_id, checked_at, success, latency_ms, error_class |

**Every payment record carries both identifiers**, per the owner's requirement: Aziv's internal
`uuid` and the gateway's own reference. Support conversations, refunds and reconciliation all
depend on being able to move between the two.

---

## 7. Admin Panel — the thirteen required controls

Each of the owner's listed capabilities, mapped to where it lives.

| # | Requirement | Implementation |
|---|---|---|
| 1 | Enable/disable any gateway | Status toggle on the gateway record; disabling removes it from selection immediately |
| 2 | Set a default gateway | `is_default`, enforced single across the table |
| 3 | Configure credentials securely | Encrypted fields, masked display, `billing.gateways.credentials` permission — **denied to Support and Content roles by default** |
| 4 | Test gateway connection | Live minimal API call; returns status, latency, error class. **Never reveals the secret** |
| 5 | Set priority/order | Drag to reorder; used when several gateways qualify |
| 6 | Enable by country/currency | `supported_countries` / `supported_currencies`, plus per-rule overrides |
| 7 | Which payment types use which gateway | `payment_gateway_rules` — subscription vs one-time vs credit top-up, optionally per plan |
| 8 | View transaction status | Transactions list with gateway, both IDs, amount, status, timestamps |
| 9 | Successful / failed / pending / refunded views | Saved filters as first-class tabs, with counts |
| 10 | Webhook configuration & status | Per gateway: the callback URL to paste into the gateway's dashboard, secret, last event received, signature-failure count, recent deliveries |
| 11 | Sandbox / test / live mode | Explicit mode switch, separate credentials per mode, confirmation step, audit logged |
| 12 | Gateway-specific settings stored securely | `credentials.extra_config` encrypted JSON, so a gateway needing unusual fields needs no schema change |
| 13 | Add future gateways without rewriting | New adapter class + a database row. **No change to subscriptions, plans, invoices, credits or checkout** |

All of it is mobile-adaptive per Owner Addendum A: gateway list becomes cards below 768px,
transaction filters open as a sheet, and credential forms are single-column with a sticky save bar.

### Reconciliation and dispute tooling

Because payments are where support tickets come from, two extra screens earn their place:

- **Reconciliation view** — payments whose Aziv status and gateway status disagree, with a
  one-click "re-query the gateway" action.
- **Transaction detail** — the full timeline for one payment: creation, redirect, return callback,
  every webhook received, reconciliation attempts, credit grant, subscription activation. When a
  customer says "I paid and got nothing", this screen answers it.

---

## 8. What stays gateway-agnostic

The test for whether this architecture is real: search the codebase for a gateway name and see
where it appears.

| Component | May reference a gateway? |
|---|---|
| `subscription_plans`, `subscriptions` | ❌ Only a gateway **id**, never a name or gateway-specific logic |
| `invoices`, `credit_ledger`, `payments` | ❌ |
| `EntitlementService`, `CreditService` | ❌ |
| Checkout controller and UI | ❌ Uses `checkoutMode()` and the interface |
| Webhook route and dispatcher | ❌ Resolves the adapter by gateway key |
| `PaymentGatewayRegistry`, `GatewaySelector` | ✅ By id, from the database |
| `RazorpayAdapter` and siblings | ✅ Of course — that is their entire job |

**A gateway name appearing anywhere in the first six rows is a defect**, and is checked in the
Phase 6 review and again in Phase 9.

---

## 9. Adding a gateway later — the actual work

The point of all of this is that gateway six is cheap. What it takes:

1. Write the adapter class implementing `PaymentGateway` plus the capability contracts it genuinely
   supports (**one file**).
2. Write its webhook signature verification.
3. Add fixture-based contract tests — the same shared suite every adapter passes.
4. Add a row in the Admin Panel and enter credentials.

**No migration. No change to subscriptions, plans, invoices, credits, checkout or the ledger.**

Estimated: **half a session to one session per additional gateway**, versus the multi-week
rewrite that hard-coded payment logic would require.

The same applies to gateways beyond the five named — Stripe, Paddle, PayPal, or a local
processor — should the business expand outside India. That is the currency-readiness the owner
asked for: INR is the initial primary currency, but nothing in the schema, the selector or the
adapters assumes a single currency.

---

## 10. Effort impact

| | Was | Now |
|---|---|---|
| Phase 6 | 4–5 sessions | **5–7 sessions** |
| **Project total** | 31–43 | **32–45 sessions** |

Phase 6 delivers the **full multi-gateway framework plus Razorpay live and reconciling**. The other
four named gateways are then incremental — added when you have merchant accounts for them, at
roughly half a session to a session each, without touching the core.

Building the framework costs more up front than a single hard-coded Razorpay integration. It costs
dramatically less the first time you need a second gateway — and for an Indian SaaS business,
needing a second gateway is close to inevitable: gateways have outages, merchant accounts get held
for review, and settlement terms differ. **A platform that can only take money one way stops
earning entirely when that way is unavailable.**
