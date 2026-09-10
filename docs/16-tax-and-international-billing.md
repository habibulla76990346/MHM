# Aziv AI — Tax & International Billing Architecture

**Status: OWNER ADDENDUM F** — resolves decision D-12 and extends billing beyond India.
Requirements tracked as `TX-1 … TX-24` in the requirements register.

> The owner's governing constraints:
> *"GST must NOT be hard-coded."*
> *"Do not automatically assume a specific GST rate or tax treatment."*
> *"The system must keep historical invoice/tax records unchanged after future tax configuration changes."*
> *"Do not hard-code India-only assumptions into the core billing architecture."*

---

## 1. The principle: tax is data, and issued invoices are frozen

Two rules govern everything below.

**Rule 1 — No tax value, rate, label, or rule appears anywhere in code.** Not GST. Not 18%. Not
CGST/SGST/IGST. Not a SAC code. Every one is a database row an admin can change. A hard-coded tax
value is a defect, checked in the Phase 6 review and again in Phase 9.

**Rule 2 — An issued invoice never changes when configuration changes.** This is the owner's
explicit requirement and it is the single most important design decision in this document.

### Why Rule 2 needs deliberate design

The naive approach stores a tax *rule reference* on the invoice and recomputes the tax when the
invoice is displayed or exported. It looks correct, and it is quietly catastrophic: the day a rate
changes, **every historical invoice silently changes too**. Filed returns stop matching the
system. Records shown to an auditor no longer match what the customer was charged.

So Aziv AI **snapshots** the entire tax computation onto the invoice at the moment it is issued:

| Snapshotted onto every invoice | Not referenced — copied |
|---|---|
| Each tax component's name, rate and computed amount | `invoice_tax_lines` rows |
| Supplier legal name, address, tax registration number | Copied from settings at issue time |
| Customer name, address, country, tax registration | Copied from the customer at issue time |
| Place of supply, jurisdiction, service code | Copied |
| Currency, exchange rate used, base-currency equivalent | Copied |
| Tax-inclusive or exclusive treatment | Copied |

Once `issued_at` is set, the invoice becomes **immutable**. Corrections are made the way accounting
requires: a **credit note** and a new invoice, never an edit. Attempting to modify an issued
invoice is blocked at the model layer, not merely discouraged in the UI.

---

## 2. Configurable tax model

Five tables replace what would otherwise be hard-coded logic.

| Table | Purpose | Key columns |
|---|---|---|
| `tax_settings` | Business identity on invoices | legal_name, address_lines, city, state, postal_code, country, tax_registration_number (GSTIN/VAT/etc.), registration_type, default_place_of_supply, service_code, tax_enabled, pricing_mode (inclusive/exclusive), rounding_mode |
| `tax_jurisdictions` | Where tax rules apply | uuid, name, country, state, is_domestic, priority, is_active |
| `tax_rates` | The rates themselves | uuid, jurisdiction_id, name (**admin-defined label**), code, rate_percent, component_type, applies_to (subscription/credits/all), **effective_from**, **effective_until**, is_active |
| `tax_rules` | When a rate applies | uuid, jurisdiction_id, condition_type (same_state / different_state / export / customer_registered / customer_unregistered / threshold), rate_ids (json), priority, is_active |
| `customer_tax_profiles` | Customer-side tax data | user_id, country, state, billing_address, tax_registration_number, registration_verified_at, is_business, exemption_reference, exemption_expires_at |

### How the owner's listed controls map

| Owner requirement | Where |
|---|---|
| GST enabled/disabled | `tax_settings.tax_enabled` |
| GSTIN | `tax_settings.tax_registration_number` |
| Business legal name / address / state / country | `tax_settings` |
| Place of supply | `tax_settings.default_place_of_supply`, overridden per invoice by rules |
| CGST / SGST / IGST | **`tax_rates` rows the admin creates and names.** Nothing in code knows these names |
| GST rate | `tax_rates.rate_percent` — no default assumed |
| SAC code | `tax_settings.service_code`, overridable per plan |
| Tax-inclusive or exclusive pricing | `tax_settings.pricing_mode` |
| Invoice numbering | `invoice_number_sequences` — §3 |
| Tax invoice settings / display settings | `system_settings` under `tax.*` |
| Tax rules | `tax_rules` |
| Effective dates | `tax_rates.effective_from` / `effective_until` |
| Tax exemptions | `customer_tax_profiles.exemption_reference` + exemption rule conditions |
| Customer tax information | `customer_tax_profiles` |
| Business/customer billing details | `tax_settings` + `customer_tax_profiles` |

**Ships with templates, not assumptions.** Seeders provide *inactive, unconfigured* templates —
an Indian intra-state/inter-state/export shape, an EU VAT shape, a no-tax shape — that the admin
activates and fills in. **No rate is pre-filled and no treatment is applied until the admin
configures and enables it**, exactly as the owner required. Until then, tax is simply off.

---

## 3. Invoice numbering

Gaps and duplicates in invoice numbers are a compliance problem in most jurisdictions.

`invoice_number_sequences` holds: prefix, suffix, current value, padding width, reset policy
(never / yearly / monthly / financial-year), financial-year start month, and format template.

Numbers are allocated inside the invoice-creation transaction using a row lock, so two
simultaneous checkouts cannot receive the same number. A number is allocated **only when an
invoice is actually issued** — never on a payment attempt — so failed payments do not consume
numbers and create gaps.

The financial-year reset policy exists because India's financial year does not start in January;
it is a setting rather than an assumption.

---

## 4. International customers

The owner will sell outside India. The core billing architecture therefore carries **no India-only
assumption**.

### Countries and currencies as data

| Table | Purpose |
|---|---|
| `countries` | code (ISO-3166), name, default_currency, requires_state, is_billing_enabled, tax_jurisdiction_id |
| `currencies` | code (ISO-4217), symbol, decimal_places, display_format, is_active, is_base |
| `exchange_rates` | from, to, rate, effective_date, source — **dated, never overwritten** |

`currencies.decimal_places` matters: not every currency has two. Storing money at a fixed two
decimals breaks zero-decimal currencies. The column exists so the formatter and rounding logic read
it rather than assuming.

### Plan pricing per currency

`plan_prices` — plan_id, currency, amount, is_active — so a plan can be priced deliberately per
market rather than converted at a live rate. Converted pricing looks unprofessional (₹1,847.32) and
moves daily. Where no explicit price exists for a currency, the admin chooses the fallback:
convert-and-round, or hide the plan in that market.

### Gateway availability by country and currency

Already designed in `14-payment-gateway-architecture.md`; extended here so selection filters on
**customer country** and **presentment currency**, with admin rules per country/currency pair.

---

## 5. Two honest limits worth stating plainly

### 5.1 Presentment currency is not settlement currency

Showing a price in USD and *receiving* USD are different things.

A merchant account in India typically **settles in INR** regardless of what the customer was shown.
The gateway converts at its own rate and takes a cross-border fee. So a customer charged $20 may
produce ₹1,6xx in the account — not a fixed rupee figure, and not one Aziv AI controls.

The architecture handles this correctly by storing **three** amounts on every payment:

| Field | Meaning |
|---|---|
| `presentment_amount` + `presentment_currency` | What the customer saw and agreed to |
| `settlement_amount` + `settlement_currency` | What actually landed, once the gateway reports it |
| `base_amount` | Converted to the reporting currency at the dated rate, for analytics |

Revenue analytics use `base_amount`; customer-facing records use presentment; reconciliation
against the gateway uses settlement. Conflating them produces books that never balance.

> **Whether a given gateway and merchant account can accept international payments at all, and in
> which currencies, is a fact about your specific merchant agreement** — not something the software
> determines. The admin configures what the account actually supports; Aziv AI does not assume.

### 5.2 Aziv AI will not claim automatic worldwide tax compliance

Cross-border digital services tax is genuinely complicated: EU VAT rules for digital services,
place-of-supply tests, reverse charge for business customers, US state-level nexus thresholds,
and India's own treatment of service exports — each with its own registration obligations.

**Aziv AI provides a configurable tax engine, not a tax advisory service.** It will:

- ✅ apply whatever rules the admin configures, correctly and consistently
- ✅ record the full computation on every invoice, permanently
- ✅ support multiple jurisdictions, components, effective dates and exemptions
- ✅ produce the data needed for filing and for an accountant to review

It will **not**:

- ❌ decide which jurisdictions the business must register in
- ❌ determine the correct rate for a given country
- ❌ automatically track threshold or nexus obligations
- ❌ file returns

> **I am not a tax adviser and the software is not one.** Selling internationally from India also
> involves cross-border payment and export-of-services considerations that belong with a qualified
> accountant. What Aziv AI guarantees is that **the software will never be the reason compliance is
> impossible** — every field an accountant asks for is captured, configurable and permanently
> recorded.
>
> Where a business grows into needing automated multi-country tax determination, the tax engine is
> structured so an external tax service can be integrated behind the same interface — the same
> adapter pattern used for AI providers and payment gateways.

---

## 6. Tax calculation flow

```
Checkout
   │
   ├─ Resolve customer jurisdiction     country + state from their tax profile
   │
   ├─ Tax enabled?  ── no ──►  zero tax, invoice records "tax not applicable"
   │
   ├─ Find matching tax_rules           by jurisdiction + condition
   │     same state · different state · export · registered business · exempt
   │
   ├─ Collect tax_rates                 those effective ON THE INVOICE DATE
   │                                    (not today's — historical invoices
   │                                     reissue identically)
   │
   ├─ Apply pricing mode                inclusive → extract from the price
   │                                    exclusive → add to the price
   │
   ├─ Round per configured rounding mode, per component
   │
   └─ SNAPSHOT every component onto invoice_tax_lines, then freeze the invoice
```

The two details that matter most: rates are selected by **invoice date, not current date**, and the
result is **copied, not referenced**. Together they make Rule 2 hold.

---

## 7. Admin Panel — tax and international

New section, adaptive per Owner Addendum A:

```
TAX & COMPLIANCE   Business tax identity — legal name, address, registration number
                   Tax enabled/disabled · Inclusive or exclusive pricing
                   Jurisdictions · Tax rates with effective dates
                   Tax rules — conditions and which rates apply
                   Invoice numbering — prefix, padding, reset policy
                   Tax invoice layout & display settings
                   Customer tax profiles · Exemptions
                   Tax report by period, jurisdiction and component
                   Preview: "what tax would this customer pay?"

COUNTRIES &        Countries — enable for billing, default currency,
CURRENCIES           state requirement, tax jurisdiction
                   Currencies — enable, symbol, decimal places, formatting
                   Exchange rates — current, history, refresh source, manual override
                   Plan pricing per currency
                   Gateway availability by country and currency
```

The **tax preview** tool is worth its cost: it lets the owner check configuration against a
hypothetical customer *before* a real invoice is issued — and an issued invoice cannot be corrected,
only credited.

---

## 8. Effort impact

| Item | Cost |
|---|---|
| Configurable tax engine — jurisdictions, rates, rules, effective dates, snapshots | **+1 to +2 sessions** (Phase 6) |
| Countries, currencies, per-currency plan pricing, exchange-rate management | **+1 session** (Phase 6) |
| Invoice numbering, immutability, credit notes | **+0.5 session** (Phase 6) |

**Total: +2.5 to +3.5 sessions**, in Phase 6.

Building the tax engine configurable costs more than hard-coding 18% GST. It costs far less than
the alternative: a rate change, a new market, or an accountant's correction requiring a developer
every time — and it is the only structure under which the owner's requirement that **historical
records never change** can actually be kept.
