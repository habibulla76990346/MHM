# Aziv AI — Universal AI Provider Architecture

This is the core of the platform (blueprint §10–§14). It answers one question:

> How does one application talk to many different AI companies — each with its own API — and
> add new ones later **without a developer rewriting the application**?

## 1. The central idea: normalise, then adapt

Every AI provider has a different API. OpenAI wants `messages` with `role`/`content`. Google
Gemini wants `contents` with `parts`. Anthropic separates the system prompt from the message
list entirely. Their streaming formats and error shapes all differ too.

Aziv AI defines **its own internal format** and gives each provider a small translator.

```
                    ┌──────────────────────────────────┐
                    │   Aziv AI internal format        │
                    │   ChatRequest / ChatResponse     │
                    │   UsageMetrics / ProviderError   │
                    └──────────────────────────────────┘
                                   │
             ┌─────────────┬───────┴───────┬──────────────┬───────────────┐
             ▼             ▼               ▼              ▼               ▼
        OpenAiAdapter GeminiAdapter AnthropicAdapter OpenAiCompatible CustomHttp
             │             │               │           Adapter          Adapter
             ▼             ▼               ▼              │               │
          OpenAI       Google Gemini    Anthropic         ▼               ▼
                                                DeepSeek, Groq,   Any documented
                                                Mistral, xAI,     API, configured
                                                Together,         entirely from
                                                OpenRouter,       the Admin Panel
                                                Fireworks, …
```

**The rest of the application never knows which provider answered.** The chat system asks for a
response; the router and adapter layer handle everything else. This is what makes adding a
provider a configuration task rather than a code rewrite.

### The five adapter types, and why five is the right number

| Adapter | Covers | Effort to add a provider |
|---|---|---|
| `OpenAiAdapter` | OpenAI | Built in Phase 4 |
| `GeminiAdapter` | Google Gemini | Built in Phase 4 |
| `AnthropicAdapter` | Anthropic Claude | Built in Phase 7 |
| `OpenAiCompatibleAdapter` | **DeepSeek, Groq, Mistral, xAI, Together, OpenRouter, Fireworks, Cerebras, Perplexity, and most others** | **Admin Panel only — no code** |
| `CustomHttpAdapter` | Any documented JSON API that fits the request/response mapping model | **Admin Panel only — no code** |

The fourth row is the one that matters commercially. A large majority of AI providers deliberately
copy OpenAI's API shape, so one well-built adapter serves them all: you add the provider in the
panel, paste the base URL and key, and it works.

`CustomHttpAdapter` extends that further — you describe the request and response shape as a
template in the panel, and Aziv AI builds the call from your description.

### What this architecture honestly cannot do

Blueprint §12 already states this, and the plan holds the same line: **Aziv AI will not claim
that every API on the internet is automatically supported.** Providers with genuinely unusual
protocols — binary formats, non-HTTP transports, bespoke authentication handshakes, unusual
streaming schemes — will still need a purpose-built adapter written in code. What the
architecture guarantees is that adding one is an isolated, low-risk job that touches a single
new class, not the rest of the platform.

## 2. Capability contracts

Adapters declare what they can do. The application asks for capabilities, never for brand names.

```php
interface ProviderAdapter
{
    public function capabilities(): array;
    public function testConnection(): TestResult;
}

interface SupportsChat          { public function chat(ChatRequest $r): ChatResponse; }
interface SupportsStreaming     { public function streamChat(ChatRequest $r): Generator; }
interface SupportsVision        { /* image input */ }
interface SupportsImageGeneration { public function generateImage(ImageRequest $r): ImageResponse; }
interface SupportsEmbeddings    { public function embed(EmbedRequest $r): EmbedResponse; }
interface SupportsTranscription { public function transcribe(AudioRequest $r): TranscriptResponse; }
interface SupportsSpeech        { public function synthesise(SpeechRequest $r): SpeechResponse; }
interface SupportsModelDiscovery{ public function listModels(): array; }
```

`SupportsModelDiscovery` is what makes Rule 5 work: where a provider exposes a model-listing
endpoint, Aziv AI reads it. Where it does not, the adapter simply does not implement the
interface, and the catalog is maintained manually — no pretending, no hard-coded model list.

## 3. The Smart AI Router (§14)

The router is a **pipeline**, not a single decision. Each stage narrows the field.

```
Request
  │
  ├─ STAGE 1  CapabilityResolver
  │           What is genuinely required? text / vision / image / audio-in /
  │           audio-out / embeddings / tool-use / long-context
  │
  ├─ STAGE 2  CandidateBuilder — hard filters. A model survives only if ALL hold:
  │             • provider enabled and not in maintenance
  │             • model enabled, status not deprecated/disabled
  │             • model actually supports every required capability
  │             • user's plan permits this provider AND this model
  │             • provider circuit breaker is not OPEN
  │             • provider budget not exhausted (or breach action allows it)
  │             • context window ≥ the size of this conversation
  │
  ├─ STAGE 3  Scoring — ordered by the active routing mode:
  │             Auto             balanced: health, latency, cost, priority
  │             Best Quality     admin quality rank, then health
  │             Fastest          lowest observed p50 latency
  │             Lowest Cost      cheapest credit cost for this capability
  │             Free Only        only free-classified models
  │             Admin Preferred  admin priority order
  │             Specific Provider  pinned provider, its models only
  │             Specific Model     pinned model, no substitution
  │
  ├─ STAGE 4  Attempt — with retry on transient errors only
  │             exponential backoff with jitter; 429 honours Retry-After
  │             retried:     timeout, 429, 500, 502, 503, 504, connection reset
  │             NOT retried: 401/403 auth, 400 invalid request, content filter,
  │                          insufficient provider quota
  │
  ├─ STAGE 5  Fallback — on exhausted retries, take the next candidate
  │             ⚠ CAPABILITY GUARD: fallback must still satisfy Stage 1.
  │               A vision request never falls back to a text-only model.
  │             Bounded by an admin-set maximum fallback depth.
  │
  └─ STAGE 6  Record — routing_logs + api_usage_logs + provider_health_logs
```

### Why routing decisions are logged in full

`routing_logs.candidates` stores every model considered and the reason each was rejected. Six
months from now, when a customer complains their request went to a slow model, the answer is a
database row rather than speculation. This is also what makes the cost analytics in §21
trustworthy — margin is computed from recorded fact, not estimation.

## 4. Circuit breaker and health (§14)

Retrying a provider that is down wastes time and the user's patience. The circuit breaker gives
a failing provider a rest.

| State | Meaning | Behaviour |
|---|---|---|
| **CLOSED** | Healthy | Traffic flows normally |
| **OPEN** | Failed too often in the window | Skipped entirely; router goes straight to the next candidate |
| **HALF-OPEN** | Cooldown elapsed | One probe request. Success → CLOSED. Failure → OPEN again, longer cooldown |

Failure threshold, window length and cooldown are all admin-configurable (§10 "health settings").
State lives in the cache for speed and is mirrored to `provider_circuit_state` so the panel can
display it and an admin can force a reset.

Health samples come from real traffic, not synthetic pings, so latency figures reflect what
users actually experience.

## 5. Credential handling (§10, Rule 6)

| Control | Implementation |
|---|---|
| Encrypted at rest | Laravel `encrypted` cast on `ai_provider_credentials.credential`, keyed by `APP_KEY` |
| Never reaches the browser | Excluded at the model level via `$hidden`; API resources never expose it; Filament renders a masked field |
| Displayed masked | Only the last 4 characters, e.g. `sk-••••••••••••3f9a` |
| Permission-gated | A dedicated `ai.credentials.*` permission set. **Support roles are denied by default** (§9) |
| Audit logged | Create/update/delete/verify all write to `activity_logs` — the value itself is never logged |
| Verifiable | "Test connection" performs a minimal live call and reports pass/fail without revealing the key |

**Consequence you should know about:** because credentials are encrypted with `APP_KEY`, that key
must be backed up alongside the database. A database restored without its matching `APP_KEY`
cannot decrypt stored provider keys, and every key would need re-entering. This is covered in the
Phase 9 backup procedure.

## 6. Model synchronisation (§11, Rule 5)

```
Trigger: scheduled (admin-set interval) OR "Refresh Models" button in the panel
   │
   ├─ For each enabled provider implementing SupportsModelDiscovery:
   │     • fetch the provider's model list
   │     • NEW model      → insert, status "preview", DISABLED by default
   │     • EXISTING model → update metadata, preserve admin overrides
   │     • MISSING model  → mark "deprecated" (never auto-delete)
   │     • record counts + errors in ai_model_sync_logs
   │
   └─ Providers without discovery: untouched; models are managed manually
```

Three deliberate safety choices:

1. **New models arrive disabled.** A newly discovered model never becomes available to customers
   — and never starts costing money — until you enable it. Discovery does not equal deployment.
2. **Vanished models are deprecated, not deleted.** Deleting would orphan the historical usage
   and cost records attached to that model, corrupting your analytics.
3. **Admin overrides survive sync.** Your display name, credit price and plan restrictions are
   never overwritten by an automatic sync.

## 7. Free vs paid handling (§13, Rule 7)

Two independent classifications, because they answer different questions:

- **Account class** on the provider — Free / Paid / Enterprise / Unknown. *"What kind of account
  is this?"*
- **Billing class** on the model — Free / pay-per-token / pay-per-request / time-based /
  subscription-included / custom. *"How does this specific model charge?"*

Free tiers are treated as **limited resources with real quotas**, never as unlimited. Aziv AI
tracks usage per credential, surfaces the limits in the panel, and applies the same rate limiting
and budget logic it applies to paid providers. Where a provider publishes a quota, the panel
records it so you can see how close you are.

The system does not implement, and will not implement, automatic key-cycling to escape quota
exhaustion. That is the behaviour Rule 7 prohibits, and the architecture omits it by design
rather than by policy.

## 8. Budgets and threshold actions (§21)

Each provider can carry a daily and/or monthly budget. When spend crosses the configured
threshold, the admin chooses in advance what happens:

| Action | Effect |
|---|---|
| **Alert only** | Notify admins; traffic continues |
| **Restrict model** | Disable that provider's expensive models; cheaper ones stay live |
| **Reroute to lower cost** | Router demotes this provider; it is used only if nothing cheaper qualifies |
| **Disable provider** | Circuit forced OPEN until an admin re-enables |

Budget state is checked in Stage 2 of the router, so a breach takes effect on the very next
request rather than at the end of a billing period.

## 9. Streaming (§15)

Streaming is what makes an AI product feel responsive, and it is the one feature with a real
hosting constraint.

- Transport: **Server-Sent Events** over a dedicated controller route (not through Livewire,
  which is not designed to hold a long-lived connection). Laravel 13's `eventStream()` helper
  handles the plumbing.
- The adapter yields `ChatChunk` objects; the controller forwards each to the browser as it
  arrives; Alpine.js appends them to the visible message.
- **Stop generation** aborts the upstream HTTP request and settles credits against the partial
  output actually produced — the user pays for what they received, not what they requested.
- If the connection drops mid-stream, the partial message is persisted and marked `interrupted`
  rather than being lost.

> ⚠ **Hosting constraint — risk R-01.** Streaming requires the web server to send output
> progressively and to allow long-lived connections. Many **shared hosting** plans buffer output
> and impose short execution timeouts, which breaks streaming: responses arrive all at once after
> a long pause, or the connection is cut. Blueprint §29 already anticipates this by recommending
> a move to VPS/cloud for streaming and queues. **A VPS is effectively required for the chat
> experience to work as designed.** A non-streaming fallback mode will be included so the
> platform degrades gracefully rather than failing, but it is a visibly inferior experience.

## 10. Error classification

Every provider error is normalised into a `ProviderError` with one class, which then drives
retry, fallback, circuit-breaker and user-facing messaging:

| Class | Retry? | Opens circuit? | What the user sees |
|---|---|---|---|
| `auth` | No | No — it is a config fault | "This provider needs attention" + admin alerted |
| `rate_limit` | Yes, honouring `Retry-After` | After repeats | Transparent — router falls back |
| `quota_exceeded` | No | Yes | Transparent — router falls back |
| `timeout` | Yes | After repeats | Transparent |
| `server_error` | Yes | After repeats | Transparent |
| `invalid_request` | No | No | Clear validation message |
| `content_filter` | No | No | Honest explanation; **no credits charged** |
| `context_length` | No | No | Router retries on a larger-context model |
| `unknown` | Once | After repeats | Generic message; full detail logged |

The principle: **provider problems are Aziv AI's problem, not the customer's.** Wherever a
fallback exists, the user simply gets their answer and never learns a provider failed.
