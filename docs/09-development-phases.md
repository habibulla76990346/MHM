# Aziv AI — Development Phases

The nine phases below are **exactly those named in blueprint §30**, in the same order, with
nothing merged, reordered or removed. A **Phase 0** is added ahead of them for environment
preparation — it creates no application features, it only makes Phase 1 possible.

Blueprint §30 closes with *"Each phase must be tested before moving to the next."* Every phase
therefore ends with a **test gate** and a **stop point** where you review before I continue.

---

## Phase 0 — Preparation *(no features)*

| | |
|---|---|
| **Goal** | A working Laravel skeleton that boots, connects to a database and runs its test suite |
| **Build** | Install MariaDB/MySQL locally · `composer create-project laravel/laravel` (Laravel 13) · configure `.env` · install Livewire 4, Filament 5, Spatie Permission 8, Sanctum · set up Tailwind build · Git structure, `.gitignore`, `.env.example` · CI that runs tests on push |
| **Test gate** | App boots · database connects · `php artisan test` passes · assets compile |
| **You provide** | Nothing |
| **You will see** | The default Laravel welcome page. Nothing that looks like Aziv AI yet — that is expected |
| **Size** | 1 session |

---

## Phase 1 — Laravel foundation, database, authentication, roles, design-token system

| | |
|---|---|
| **Goal** | Accounts, roles and permissions working; the token system that Phase 2's themes build on |
| **Build** | Core migrations (identity, security, settings groups) · registration, login, password reset, email verification · session limits + idle timeout · `SettingsService` with typed registry + caching · roles/permissions with the full §9 matrix, deny-by-default · `ActivityLogger` on every sensitive write · design-token infrastructure + CSS custom property pipeline · base layouts using tokens only |
| **Blueprint** | §2, §8 (partial), §9, §23 (partial), §26, §5 (foundation) |
| **Test gate** | Register/login/verify/reset all work · each of the 5 roles can reach exactly what the matrix permits and nothing more · settings read/write with cache invalidation · audit rows written · **zero hard-coded colours in any template** |
| **You provide** | Nothing |
| **You will see** | A working login. Register an account, sign in, see an empty dashboard |
| **Size** | 2–3 sessions |

---

## Phase 2 — Admin Panel foundation + branding + themes + content management

| | |
|---|---|
| **Goal** | You can control how Aziv AI looks and reads — without me |
| **Build** | Filament panel at `/admin` with permission integration · branding screens (all logo variants, favicon, app icons, names, tagline, contact, social, footer) · media library w/ safe deletion · theme engine: 8 built-in themes seeded, colour editor for all ~95 tokens × light/dark, component tokens, radius/shadow/spacing/typography, custom CSS (sanitised + permission-gated), preview → publish → restore · WCAG contrast warnings · content management: homepage sections, pages, banners w/ scheduling + priority, FAQ, navigation menus, SEO + social preview · UI/UX settings (pagination, density, date/time, locale, timezone, currency) · maintenance mode · feature-flag framework |
| **Blueprint** | §3, §4, §5, §6, §7, §24 (partial), §27 |
| **Test gate** | Every branding asset uploads and appears · switching theme changes the entire site · preview is visible only to the previewer · restore recovers the previous theme · custom CSS is sanitised · content edits appear on the public site · maintenance mode locks out non-admins |
| **You provide** | Logo files, brand colours, company details, homepage copy — *or* accept placeholders and change them later in the panel |
| **You will see** | **This is the first phase that feels like your product.** A real homepage, your branding, your colours, and a working admin panel |
| **Size** | 3–4 sessions |

---

## Phase 3 — Universal AI gateway + provider credentials + model catalog

| | |
|---|---|
| **Goal** | The provider system exists and can be tested — before any chat UI depends on it |
| **Build** | `ProviderAdapter` interface + all capability contracts · normalised DTOs · `ProviderRegistry` · encrypted credential storage + masked display + permission gating · provider CRUD (all 11 fields), budgets, rate limits, maintenance mode · model catalog CRUD (all 10 fields, 5 statuses) · capability flags · `ai_model_prices` with provider cost vs credit price and effective dating · `ModelSyncService` + Refresh Models + scheduled sync + sync logs · **API test console (§25)** · `OpenAiCompatibleAdapter` and `CustomHttpAdapter` |
| **Blueprint** | §10, §11, §12 (foundation), §13 (structure), §25 |
| **Test gate** | Provider added via panel · credential encrypted at rest and never present in any response body · test connection returns real status/latency · model sync populates the catalog · new models arrive **disabled** · deprecated models are not deleted · adapter contract tests pass against fixtures |
| **You provide** | **An OpenAI API key and a Google Gemini API key.** Both come from those companies' own dashboards — I cannot create them for you (blueprint §28) |
| **You will see** | Providers and models listed in your panel, with a working "test connection" button |
| **Size** | 3–4 sessions |

---

## Phase 4 — OpenAI + Gemini + chat streaming/history

| | |
|---|---|
| **Goal** | Working AI chat |
| **Build** | `OpenAiAdapter` and `GeminiAdapter` (chat, vision, streaming, model discovery) · chat UI: new chat, history, search, rename, delete, archive · SSE streaming · stop generation · regenerate (preserving the original) · copy · feedback · manual model selection + Auto mode · `ContextBuilder` with configurable limits · admin personas/system prompts · message length, attachment and rate limits · retention rules |
| **Blueprint** | §12 (initial), §15 |
| **Test gate** | Streamed response from both providers · stop generation halts upstream and settles partial usage · regenerate keeps history · conversation search works · context limits enforced · rate limits enforced · **non-streaming fallback works** (see risk R-01) |
| **You provide** | Nothing new |
| **You will see** | **Aziv AI works.** Real conversations with real AI, streaming live |
| **Size** | 3–4 sessions |

---

## Phase 5 — Smart routing + health + fallback + cost/usage logging

| | |
|---|---|
| **Goal** | Multi-provider intelligence and full cost visibility |
| **Build** | `AiRouter` pipeline: capability resolver, candidate builder, all 8 routing modes, scoring · retry w/ exponential backoff + jitter, honouring `Retry-After` · error classification table · circuit breaker (closed/open/half-open) + admin reset · health recording from live traffic · **capability-guarded fallback** · `routing_logs` with full candidate reasoning · `api_usage_logs` · provider budgets + threshold actions · nightly aggregation into daily summary tables · cost/margin analytics dashboards |
| **Blueprint** | §13 (tracking), §14, §21, §24 (routing defaults) |
| **Test gate** | Each routing mode selects as specified · killing a provider triggers fallback with no user-visible error · **a vision request never falls back to a text-only model** · circuit opens on repeated failure and recovers via half-open · budget breach triggers the configured action · cost figures reconcile against provider-reported usage |
| **You provide** | Your credit pricing intent (what margin you want over provider cost) |
| **You will see** | Aziv AI surviving a provider outage without your customers noticing, plus dashboards showing exactly what each request cost |
| **Size** | 2–3 sessions |

---

## Phase 6 — Subscriptions + credits + payments

| | |
|---|---|
| **Goal** | The platform earns money |
| **Build** | Plan management (FREE/PRO/PREMIUM, all 11 configurable dimensions) · plan → model/provider access matrix · `EntitlementService` · **credit ledger (append-only) + balances + holds** · pre-authorisation and settlement · promotional credits, expiry, optional rollover · manual adjustments with mandatory reason · payment gateway interface + first implementation · purchase, upgrade, downgrade, renewal, cancellation · **idempotent webhooks** · invoices, coupons, tax display · billing history · notification system + templates + announcements |
| **Blueprint** | §19, §20, §22, §8 (billing parts), §13 (pricing) |
| **Test gate** | **A webhook replayed 5× grants credits once** · **parallel requests cannot drive a balance negative** · failed AI call releases its hold and charges nothing · upgrade/downgrade prorates correctly · plan limits enforced · ledger sum always equals cached balance · **no card data anywhere in the database** |
| **You provide** | **A payment gateway account** (decision D-01) and your plan pricing |
| **You will see** | Customers can subscribe and pay, and credits deduct accurately as they use AI |
| **Size** | 3–4 sessions |

---

## Phase 7 — Claude + DeepSeek + Mistral + Groq + generic compatible/custom providers

| | |
|---|---|
| **Goal** | Genuine multi-provider breadth |
| **Build** | `AnthropicAdapter` (its own message format, system prompt handling, streaming) · DeepSeek, Mistral, Groq configured via `OpenAiCompatibleAdapter` — **configuration, not code** · OpenRouter and Hugging Face as optional aggregators · custom provider mapping builder in the panel · adapter contract tests for all |
| **Blueprint** | §12 (full) |
| **Test gate** | Every provider passes the same adapter contract suite · routing spans all of them · fallback crosses provider families correctly · a brand-new OpenAI-compatible provider can be added **entirely from the panel with no code change** |
| **You provide** | API keys for whichever additional providers you want live |
| **You will see** | Many AI providers under one interface, with the router choosing between them |
| **Size** | 2–3 sessions |

---

## Phase 8 — Files/RAG + image + voice

| | |
|---|---|
| **Goal** | Aziv AI stops being chat-only |
| **Build** | **Files:** upload → validation → storage → extraction (PDF/DOCX/TXT/CSV) → optional scan → chunking → embeddings → retrieval · knowledge bases w/ access control and retrieval settings · admin file rules, retention, storage limits, plan access · **Image:** text-to-image, provider/model selection, credits, history, status, prompt history, regeneration, admin controls; architecture prepared for editing/background removal/upscaling · **Audio:** speech-to-text and text-to-speech w/ admin-controlled providers, quotas and credit costs |
| **Blueprint** | §16, §17, §18 |
| **Test gate** | Each supported file type extracts correctly · oversized and disallowed types rejected · RAG retrieval returns relevant chunks · knowledge base permissions enforced · image generation deducts correct credits · failed generation refunds · STT/TTS round-trips |
| **You provide** | Vector storage decision (D-03) · malware scanning decision (D-08) · image/voice provider keys |
| **You will see** | Upload a PDF and ask questions about it. Generate images. Speak to Aziv AI and hear it answer |
| **Size** | 4–5 sessions — the largest phase; may be split |

---

## Phase 9 — Security hardening, testing, backups, monitoring, production deployment

| | |
|---|---|
| **Goal** | Safe to put real customers and real money on |
| **Build** | Full security review vs §23 · optional MFA for admin accounts · rate limiting and API protection review · **permission audit: every admin action is gated and logged** · **hard-coded colour audit** · dependency vulnerability scan · backup + tested restore procedure (**including `APP_KEY`**) · monitoring, error tracking, uptime alerts · performance pass (N+1 queries, index verification, cache coverage) · staging environment · deployment documentation · environment configuration documentation · API/integration documentation · production launch |
| **Blueprint** | §23, §28, §29 |
| **Test gate** | Full suite green · **a restore from backup is actually performed and verified**, not merely scripted · no critical dependency vulnerabilities · load test at expected concurrency · every §23 item signed off |
| **You provide** | Production hosting, domain, SSL, and production provider/gateway accounts |
| **You will see** | Aziv AI live on your own domain |
| **Size** | 3–4 sessions |

---

## Timeline

| Phase | Sessions | Cumulative |
|---|---|---|
| 0 — Preparation | 1 | 1 |
| 1 — Foundation | 2–3 | 3–4 |
| 2 — Admin, branding, themes, content | 3–4 | 6–8 |
| 3 — AI gateway, credentials, catalog | 3–4 | 9–12 |
| 4 — OpenAI + Gemini + chat | 3–4 | 12–16 |
| 5 — Routing, health, cost | 2–3 | 14–19 |
| 6 — Subscriptions, credits, payments | 3–4 | 17–23 |
| 7 — More providers | 2–3 | 19–26 |
| 8 — Files, image, voice | 4–5 | 23–31 |
| 9 — Hardening, deployment | 3–4 | 26–35 |
| **Total** | **26–35 sessions** | |

**How to read this.** A "session" is one working conversation with me that ends in tested,
committed code. It is not a fixed number of hours or days — it depends how quickly you review
each phase and how much changes after you see it. Your own testing time between phases is real
calendar time and is not included above.

**A usable product arrives well before the end.** After Phase 4 (roughly 12–16 sessions) you have
a branded, working AI chat platform. Phases 5–9 make it profitable, resilient and safe to scale.

## Two natural launch points

| | **Early launch** — after Phase 6 | **Full launch** — after Phase 9 |
|---|---|---|
| Sessions | ~17–23 | ~26–35 |
| You get | Branded platform, chat with several providers, smart routing, subscriptions and payments | Everything, plus files/RAG, image, voice, full hardening |
| Missing | File analysis, image, voice | — |
| Sensible when | You want revenue and real user feedback sooner | You want the complete blueprint before any customer sees it |

Nothing is skipped in the early-launch route — Phases 7–9 simply happen after real customers are
already using the platform. This is how most SaaS products are actually launched, and the
feedback usually improves what gets built in the remaining phases.
