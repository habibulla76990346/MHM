# Aziv AI — working rules

A multi-provider AI SaaS platform in PHP/Laravel, where the Admin Panel is the control layer for
branding, themes, content, AI providers, models, routing, billing, credits, tax and operations.

**The plan is the contract.** `docs/00-README.md` is the index; `docs/12-decision-log.md` is the
authoritative record of what is settled. Read the decision log before changing anything
architectural.

## Stack

PHP 8.4 (runs on 8.3–8.5) · Laravel 13 · Livewire 4 · Alpine · Tailwind 4 · Filament 5 ·
Spatie Permission 8 · MySQL/MariaDB · Redis where available, database drivers otherwise.

## Rules that are checked, not just stated

These have tests behind them. Breaking one fails the build.

1. **No hard-coded colours.** Every colour, radius, shadow, spacing value and font references a
   design token. A template containing `bg-blue-600` or `#3b82f6` silently stops responding to the
   theme system. → `SmokeTest::test_no_blade_template_hard_codes_a_colour`
2. **No horizontal overflow, 44px touch targets, 16px inputs.** Checked at six viewports on every
   screen. A screen failing at any width fails the phase. → `npm run test:responsive`
3. **Nothing sensitive inside `public/`.** The one thing written there at runtime is a derived
   brand image — raster only, named by content hash, never an uploaded SVG (D-13). The original
   upload always stays on the private disk. → `DeploymentSecurityTest`
4. **No credential value reaches the diagnostics layer.** Checks ask "is this valid?" and get a
   boolean. `CheckResult` scrubs at construction, so no output path can leak.
   → `CheckResultTest`, `RedactorTest`
5. **No tax name, rate or code anywhere in application code, and no gateway name in billing code.**
   Both were review rules; a review rule lasts as long as the person who remembers it. The scan
   reads identifiers and string literals with comments stripped, so documenting the rule cannot
   trip it. → `NoHardCodedTaxOrGatewayTest`
6. **A balance can never go negative, and an issued invoice can never change.** Proved with forked
   processes and by editing a rate out from under a document that had already been sent.
   → `CreditConcurrencyTest`, `InvoiceTest`

## Rules enforced by review

- **No hard-coded model names** (`gpt-4o`, `gemini-…`) in business logic. The router asks the
  database which models support the required capability.
- **No gateway names** (`razorpay`, …) in subscriptions, plans, invoices, credits or checkout.
  Those hold a gateway id and call the interface.
- **No tax rate, label or code anywhere in code.** Tax is data; issued invoices are frozen
  snapshots.
- **Environment is configuration, not architecture.** No absolute paths, no provider-specific
  branches, no `exec()` in a request path, nothing assuming Redis or a persistent worker. Moving
  host must be an `.env` change plus a data copy — never a code change.
- **Every long operation is a queued job**, so shared hosting differs in *speed*, never in
  *capability*.
- **Every admin write does three things or it does not ship:** authorise, validate, audit with
  before/after.

## Layout

```
app/Domains/<Module>/    Models, Services, Actions, DTO, Contracts, Events, Jobs, Policies
app/Filament/            Admin panel resources
resources/css/app.css    Design tokens — the source of every colour in the app
tests/Responsive/        The six-viewport gate
docs/                    The plan. 19 documents
brand/                   Master artwork (never modified) + derived variants
```

## Commands

```sh
php artisan test                 # PHPUnit
npm run build                    # compile assets (no network needed)
npm run test:responsive          # six-viewport gate (needs the app served)
npm run test:chat                # chat behaviour gate: mobile keyboard, scroll anchoring
php artisan aziv:diagnose        # what is wrong with this server, and whose problem it is
php artisan aziv:diagnose --json # same, machine-readable
```

## Local development

MariaDB runs locally; `aziv` and `aziv_test` databases, user `aziv`. If MariaDB is not running:

```sh
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
nohup /usr/sbin/mariadbd --user=mysql --datadir=/var/lib/mysql \
  --socket=/run/mysqld/mysqld.sock > /tmp/mariadb.log 2>&1 &
```

Playwright uses the preinstalled browser — set `CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome`.
Do not run `playwright install`.

## Where the build is

**Phases 0–7 complete.** Phase 6 delivered the money: plans with per-currency pricing and
configurable limits, an append-only credit ledger with pre-authorisation holds, the configurable
tax engine, immutable invoices with gap-free numbering and credit notes, coupons, countries and
currencies, and the multi-gateway payment framework with Razorpay, webhooks, reconciliation and
refunds. Phase 7 delivered the breadth — and needed exactly one new class to do it: Anthropic,
whose four genuine differences each have a test, while DeepSeek, Mistral, Groq, OpenRouter and
Hugging Face are rows in the preset list served by the adapter that already existed. A provider
Aziv AI has never heard of goes from nothing to answering without a line of code, and a test
proves it by reading `app/` for the provider's name.
**Phase 8 is next** — files/RAG, image and voice. See `docs/09-development-phases.md`.

**Everything from Phase 3 onward was built and tested entirely against fixtures.** No real OpenAI,
Gemini or Razorpay credential has been used — the plan has the owner supply those. Every adapter
behaviour, routing decision and payment path is proven against recorded shapes; the first real call
happens when a credential is entered.

### Where the owner enters what the money screens need

Nothing is required to run: the platform is unmetered until a plan is published, and tax is off
until it is configured.

- **API keys** — Admin → AI Providers → *(a provider)* → Credentials. Never in chat, never in a file.
- **Model prices** — Admin → AI Models → *(a model)* → Prices: provider cost and credit price per
  unit, with an effective-from date. An unpriced model records a visible zero rather than a guess.
- **Budgets** — Admin → AI Providers → *(a provider)* → Budgets. "Warn" keeps serving and tells
  you; "block" stops spending until the next period.
- **Plans** — Admin → Plans. Publishing a DEFAULT plan is what switches metering on for everybody.
- **Tax** — Admin → Tax and compliance, then Admin → Tax rules. Nothing is charged until you enter
  your business details, add the rates your accountant confirms, and switch tax on.
- **Payment gateways** — Admin → Payment gateways → *(a gateway)* → Credentials. Sandbox and live
  are separate; the webhook URL to paste into the gateway's dashboard is on the Webhook action.
- **Reporting currency, rate feed, routing defaults** — Admin → Routing and Health → Defaults, and
  Admin → Countries and currencies.


### Routing, in one paragraph

Nothing decides which model answers except `AiRouter`, and it decides in six stages: what the
REQUEST needs (`Capability::VISION`, never a model name), which models survive the hard filters,
how the survivors score under this conversation's mode, the attempt with retries, the substitute
when that fails, and the record. A substitution re-runs the whole pipeline with the same
requirement, which is what makes "a vision request never falls back to a model that cannot see"
true by construction rather than by a check someone could forget. Every candidate and every
rejection reaches `routing_logs`, so "why did this go to the expensive model?" has an answer six
months later. Health and latency come from real customer traffic, never synthetic pings, and a
provider nobody has used yet scores as healthy — no evidence is not bad evidence.

### Cost, in one paragraph

A call is costed at the price that applied WHEN IT HAPPENED and the figure is stored, so editing a
price never rewrites the profitability of history. Provider cost stays in the provider's own
currency with that currency beside it; the conversion into the owner's currency happens in the
nightly rollup at the rate that applied on that day, and is stored too. A missing rate records a
visible zero and the screen names the currency — a zero can be found and corrected, a guess cannot.

### Credits, in one paragraph

The ledger is APPEND-ONLY and every write happens under a row lock on the balance, in the same
transaction — so the cached balance can never disagree with the sum of the ledger, and two parallel
requests cannot both spend the same credit. A chat turn takes a generous HOLD before the provider
is called and settles it to what the call actually cost; a failed call releases the hold and
charges nothing, because a customer pays for answers and never for attempts. Metering begins when
the owner publishes a default plan, not when this code shipped, and every account lands on that
plan the first time it chats.

### Money, in one paragraph

An issued invoice is a COPY of its own computation — supplier and customer identity, every tax
component's name, rate and amount — so editing or deleting a tax rate cannot alter a document
already sent, and corrections are credit notes rather than edits. Nothing in code knows any tax
name, rate or code; nothing outside `app/Domains/Payments/Adapters/` and the registry knows any
gateway's name. Both rules are checked by a tokeniser scan, not by review. A payment is settled by
ONE idempotent handler that asks the gateway directly, reached by three paths — the browser
returning, a webhook, and a scheduled sweep — with four separate idempotency guards behind it,
because a replayed webhook that grants a second month of credits costs real money.

### Adding a provider, in one paragraph

For anything OpenAI-shaped — which is most of the market — it is a row: Admin → AI Providers → Add,
pick a preset or type a name, an address and how the key is sent, paste the key, refresh the
catalog, enable a model. `ProviderRegistry::presets()` carries the addresses for the providers the
product already knows, and every value it fills in stays editable. A provider that copies nobody's
shape is described in the panel's mapping builder instead — what to send, and where the answer is
— and the placeholders there are substituted literally, never evaluated. A new adapter class is
justified only by differences that would silently produce wrong behaviour otherwise; Anthropic has
four, and each one is a test.

### AI providers, in one paragraph

Nothing above `app/Domains/AI/Contracts/ProviderAdapter.php` knows which company answered. The
application asks for CAPABILITIES (`Capability::VISION`), the catalog says which models have them,
and an adapter translates. `ProviderRegistry` is the only place a provider's shape is named, so
adding a provider is a row plus — for anything OpenAI-shaped, which is most of the market — no code
at all. A credential is `$hidden` and encrypted; `secret()` is the one way to read it back, and every
caller is pinned by a test rather than by this sentence
(`CredentialSecurityTest::test_only_the_request_boundary_ever_reads_a_credential_back`). A provider's raw error text never crosses the boundary: several APIs echo the
failing request, and that request carried the key.

### Chat, in one paragraph

Livewire owns which conversation is open; `resources/js/chat.js` owns the live text, because a
server round trip per token would be thousands of requests for one reply. `ChatService::beginTurn()`
saves the customer's message BEFORE calling a provider and creates the assistant row immediately in
`pending`, so a failure never loses what they typed and never leaves a conversation that silently
stops. Stop, failure and completion all SETTLE that row. Regenerating creates a new row pointing at
the one it replaces — both survive (§15). Streaming degrades to `/chat/{message}/complete` when the
host buffers output (risk R-01). The streaming routes compare ownership DIRECTLY rather than through
the Gate, because Spatie's `Gate::before` would otherwise let a Super Admin read a customer's
conversation.

### Content, in one paragraph

Pages are assembled from `SectionType`'s closed set of blocks, never free HTML — that is what keeps
page content inside the theme system and inside the responsive gate, since arbitrary markup could
hard-code a colour and would pass six viewports only by luck. Pages live at `/p/{slug}` so an
administrator can never create one that shadows an application route. Scheduling is a future
`published_at`, not a third status, so there is one answer to "is this live?". Navigation is rows in
`navigation_items`, and an item whose destination no longer resolves is dropped rather than
rendered as a dead link.

### Branding, in one paragraph

`brand('logo_light')` gives a web-root-relative path and always falls back to the artwork that
ships with the product, so no template needs to guard for an administrator who has never uploaded
anything. An upload goes onto the **private** disk through the ordinary Phase 1 pipeline first;
only then is a derived raster copy written to `public/brand/<purpose>-<checksum8>.<ext>`. Never
publish an uploaded SVG. The master artwork in `brand/` is never modified and never deleted.

### Useful commands added in Phase 1

```sh
php artisan aziv:admin:create   # create an administrator (never seeded)
php artisan aziv:test-user      # local account the responsive gate signs in as
php artisan aziv:test-fixtures  # local plan + no-money gateway, so the gate can check checkout
```

### Traps worth remembering

- **Dots are structure, not text — in three different places.** Laravel reads a dot in a validation
  key as nested-array access, so validating under `auth.password_min_length` passes every rule
  vacuously. Livewire reads a dot in `wire:model` the same way, so binding `values.color.primary`
  writes `$values['color']['primary']` and the real token is never touched — silently, with no
  error. Both bit this project. Anywhere a token or setting key reaches a validation rule, a
  `wire:model`, or a message bag, flatten the dot first.
- **`Gate::after` cannot downgrade an allow** (`$result ??= $afterResult`), and Spatie registers
  its own `Gate::before`. Permission denials belong in `User::hasPermissionTo()`.
- **A version column that gates a cache must be bumped with `increment()`.** A model created
  without an explicit value holds NULL in memory while the database holds 1, so `version + 1`
  writes 1 over 1 — the version never moves and the cached stylesheet is served forever.
- **Only scalars and plain arrays go into the cache.** `Cache::remember()` on an Eloquent model or
  any rich object serialises it; on a real store (file, database, Redis) that payload outlives its
  class and returns as `__PHP_Incomplete_Class`, taking down every page that reads it. Tests run
  the `array` driver, which never serialises, so PHPUnit cannot catch it. Cache an id and re-query.
  This bit twice — the theme service and the System Health page. Both now have a regression test
  asserting the cached value is plain data.
- **`Http::fake()` called twice for the same pattern does NOT replace the first stub.** Laravel
  keeps the first match, so a test that re-fakes an endpoint to simulate a CHANGE silently keeps
  the original response — and every "the model disappeared" assertion passes vacuously. Register
  one stub whose closure reads mutable test state instead.
- **A method name can collide with Eloquent's own and take the whole application down.**
  `ExchangeRate::on()` clashed with `Model::on($connection)`; an incompatible signature is a fatal
  error at class load, not a failed query. Check the base class before naming a static helper.
- **`upsert()` then `increment()` counts the first write twice.** The upsert seeds the row with the
  value, and the increment adds it again. Insert at zero and let the increment be the one place a
  number is added.
- **The six-viewport gate only measures what is VISIBLE.** A form inside a section that is
  collapsed by default is a form nothing checks. Leave it expanded, or the gate is decorative.
- **`min-height` does nothing on an inline element.** The browser ignores it silently. Filament's
  link-style table actions sat at 32px for two phases with a 44px rule pointing straight at them.
- **A gate is only as good as the page it runs against.** Every admin table was checked while empty,
  so no row action was ever measured. Seed a row before trusting a table screen.
- **`WithoutModelEvents` in a seeder breaks anything generated in a `creating` hook.** uuids and
  slugs both. `php artisan db:seed` failed on a fresh database, which is the first thing a new
  owner runs.
- **`firstOrCreate()` returns a thin model on INSERT.** It holds only the attributes you passed,
  while the database holds the column defaults — so the very first read after creation sees nulls.
  `->refresh()` if you are about to read anything you did not write.
- **A searchable Select is not a `<select>`.** Filament swaps it for a button with a different
  class, so a rule written for `.fi-select-input` never touches it. It sat at 36px from Phase 1
  until a screen with one was finally added to the responsive gate.
- **Dead code cannot be sabotaged, so it cannot be trusted.** `AiRouter::fallback()` duplicated the
  live substitution logic and had no callers; breaking it on purpose changed no test result, which
  is how it was found. Two copies of a safety guarantee is one copy and one thing that drifts.
- **A gate is worth only what it can catch.** Break it on purpose before trusting it. The
  hard-coded-colour gate silently checked two directory levels for a whole phase, because PHP's
  `glob('**')` does not recurse. Every design token lives in `resources/css/tokens.css`, imported
  by both `app.css` and the Filament theme — one copy, so the two panels cannot drift.
