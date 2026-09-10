# Aziv AI — Module Structure

15 modules. Each owns its data, its rules and its screens. This table is the map you use to ask
"where does X live?"

| # | Module | Owns | Key services | Tables | Phase |
|---|---|---|---|---|---|
| 1 | **Settings** | Every application-level setting | `SettingsService`, `SettingRegistry`, `FeatureFlagService` | `system_settings`, `feature_flags` | 1–2 |
| 2 | **Identity** | Accounts, profiles, OAuth, sessions, MFA | `RegistrationService`, `SessionGuard`, `TwoFactorService` | `users`, `user_profiles`, `oauth_accounts`, `user_sessions`, `two_factor_secrets` | 1 |
| 3 | **Security** | Roles, permissions, audit, rate limiting | `PermissionRegistry`, `ActivityLogger`, `RateLimitResolver` | `roles`, `permissions`, 3 pivots, `activity_logs` | 1, 9 |
| 4 | **Theming** | Themes, tokens, CSS compilation, preview | `ThemeService`, `TokenCompiler`, `CssSanitiser`, `ContrastChecker` | `themes`, `theme_tokens` | 2 |
| 5 | **Branding** | Logos, icons, media library | `MediaLibraryService`, `ImageVariantGenerator` | `media_assets` | 2 |
| 6 | **Content** | Pages, sections, banners, FAQ, menus, SEO | `PageRenderer`, `BannerScheduler`, `SeoResolver` | `content_pages`, `content_sections`, `banners`, `faqs`, `navigation_menus`, `navigation_items` | 2 |
| 7 | **AI** | Providers, credentials, models, routing, health, sync | `ProviderRegistry`, `AiRouter`, `CircuitBreaker`, `ModelSyncService`, `UsageRecorder`, `CredentialResolver` | 15 tables (groups 3–5) | 3–5, 7 |
| 8 | **Chat** | Conversations, messages, streaming, personas | `ConversationService`, `StreamController`, `ContextBuilder` | `chat_conversations`, `chat_messages`, `chat_message_attachments`, `message_feedback`, `personas`, `conversation_shares` | 4 |
| 9 | **Billing** | Plans, subscriptions, payments, credits, coupons, **payment gateways** | `PlanService`, `SubscriptionManager`, `CreditService`, **`PaymentGatewayRegistry`**, **`GatewaySelector`**, **`ReconciliationService`**, `WebhookProcessor`, `EntitlementService` | 19 tables (group 2) | 6 |
| 10 | **Files** | Upload, extraction, chunking, knowledge bases, retrieval | `UploadValidator`, `TextExtractor`, `Chunker`, `EmbeddingService`, `RetrievalService` | `files`, `file_chunks`, `file_scan_results`, `knowledge_bases`, `knowledge_base_files`, `embeddings` | 8 |
| 11 | **MediaAi** | Image generation | `ImageGenerationService` | `image_generations` | 8 |
| 12 | **Audio** | Speech-to-text, text-to-speech | `TranscriptionService`, `SpeechService` | `audio_jobs` | 8 |
| 13 | **Notifications** | In-app, email, announcements, templates | `NotificationDispatcher`, `TemplateRenderer` | `notifications`, `notification_templates`, `announcements`, `notification_deliveries` | 6 |
| 14 | **Analytics** | Cost, revenue, margin, usage aggregation | `CostAggregator`, `RevenueReporter`, `MarginCalculator`, `BudgetMonitor` | Reads logs; writes daily summary tables | 5–6 |
| 15 | **Admin** | Filament panel, resources, widgets | Filament resources per module | — | 2–9 |

## Dependency direction

```
                    ┌───────────────────────┐
                    │  Settings · Security  │   depended on by everything
                    └───────────────────────┘
                              ▲
              ┌───────────────┼────────────────┐
              │               │                │
       ┌──────────┐    ┌────────────┐   ┌────────────┐
       │ Identity │    │  Theming   │   │  Content   │
       └──────────┘    │  Branding  │   └────────────┘
              ▲        └────────────┘
              │
       ┌──────────────┐
       │   Billing    │   plans, credits, entitlements
       └──────────────┘
              ▲
       ┌──────────────┐
       │      AI      │   router, adapters, models, health
       └──────────────┘
              ▲
    ┌─────────┼──────────┬──────────┐
    │         │          │          │
 ┌──────┐ ┌───────┐ ┌─────────┐ ┌───────┐
 │ Chat │ │ Files │ │ MediaAi │ │ Audio │   the customer-facing features
 └──────┘ └───────┘ └─────────┘ └───────┘
              ▲
       ┌──────────────┐
       │  Analytics   │   reads everything, is depended on by nothing
       └──────────────┘
```

**Arrows point toward dependencies, and there are no cycles.** Chat depends on AI; AI does not
know Chat exists. This is what allows Chat to be rebuilt, or Image Generation to be extracted into
its own service, without disturbing anything beneath it.

## Anatomy of a module

Every module folder follows the same shape, so finding your way around one means you can find your
way around all of them:

```
app/Domains/{Module}/
├── Models/          Eloquent models — data only
├── Services/        Business rules — the module's actual behaviour
├── Actions/         Single-purpose operations (CreateConversation, GrantCredits)
├── DTO/             Typed data carriers passed between layers
├── Contracts/       Interfaces this module exposes or requires
├── Events/          Things that happened (CreditsDeducted, ProviderFailed)
├── Listeners/       Reactions to events from this or other modules
├── Jobs/            Queued background work
├── Policies/        Authorisation rules
└── Exceptions/      Typed failures this module can raise
```

## Cross-module communication

Modules talk through **events**, not direct calls, wherever the relationship is a reaction rather
than a requirement:

| Event | Raised by | Listened to by | Result |
|---|---|---|---|
| `AiRequestCompleted` | AI | Billing, Analytics | Settle credits; record usage |
| `AiRequestFailed` | AI | Billing, Notifications | Release hold; alert if systemic |
| `ProviderCircuitOpened` | AI | Notifications | Alert admins |
| `BudgetThresholdReached` | Analytics | AI, Notifications | Apply threshold action; alert |
| `SubscriptionActivated` | Billing | Identity, Notifications | Update entitlements; welcome email |
| `PaymentSucceeded` | Billing | Billing | Grant credits (idempotently) |
| `PaymentReconciled` | Billing | Billing, Notifications | Settle a payment whose webhook was lost |
| `GatewayWebhookFailedVerification` | Billing | Security, Notifications | Alert admins — a signature failure is a security signal |
| `ThemePublished` | Theming | — | Invalidate CSS cache |
| `SettingUpdated` | Settings | — | Invalidate settings cache |
| `ModelSyncCompleted` | AI | Notifications | Report new/deprecated models |

The benefit for you: when analytics needs a new metric, a listener is added. Nothing in the AI or
billing code is touched, so nothing that currently works can break.

## Testing strategy per module (Rule 3, Rule 9)

| Test type | What it covers | Runs |
|---|---|---|
| **Unit** | Pure logic — routing scores, credit maths, token compilation, contrast ratios | Every phase |
| **Feature** | Full HTTP request through middleware to database | Every phase |
| **Adapter contract** | Every provider adapter satisfies the same interface, against recorded fixture responses | Phases 4, 7 |
| **Permission** | Each role can reach exactly what the §9 matrix says, and no more | Phases 1, 9 |
| **Idempotency** | A webhook replayed 5× credits once | Phase 6 |
| **Concurrency** | Parallel spends cannot drive a balance negative | Phase 6 |

**Provider adapters are tested against recorded fixtures, not live APIs.** Tests must be free,
fast, deterministic and runnable offline. A live-API test suite costs money on every run and fails
when a provider has an outage — which teaches you to ignore failing tests, the worst possible
habit.

The end of every phase produces a report in the exact shape Rule 9 requires: **what was
completed, what failed, what remains.**
