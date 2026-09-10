# Aziv AI — Database Architecture

Blueprint §26 names six groups of tables. This document expands them into the full working
schema: **62 tables in 10 groups**. Every table named in §26 appears here; the additions are
marked and justified.

Target: **MySQL 8+** (blueprint §2), InnoDB, `utf8mb4_0900_ai_ci`. PostgreSQL remains viable for
larger deployments — no MySQL-only feature is used except where noted for vectors.

Conventions used throughout:
- `id` — big integer, auto-increment primary key
- `uuid` — public-facing identifier, so internal record counts are never exposed in URLs
- `created_at` / `updated_at` on every table; `deleted_at` where recovery matters
- Money as `DECIMAL(12,6)` — never floating point, which loses fractions of a cent
- Provider costs stored at 6 decimal places because per-token prices are that small

---

## Group 1 — Identity & Access (11 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `users` | Core account | uuid, name, email, email_verified_at, password, status (active/suspended/restricted/pending), locale, timezone, last_login_at, last_login_ip |
| `user_profiles` | Everything not needed on every query | user_id, avatar_media_id, phone, company, country, bio, preferences (json) |
| `oauth_accounts` | Google/OAuth links (§8) | user_id, provider, provider_user_id, avatar, unique(provider, provider_user_id) |
| `user_sessions` | Session limits + idle timeout (§8) | user_id, session_id, ip, user_agent, last_activity_at, revoked_at |
| `two_factor_secrets` | Optional admin MFA (§23) | user_id, secret (encrypted), recovery_codes (encrypted), confirmed_at |
| `roles` | Spatie; custom roles allowed (§9) | name, guard_name, is_system, description |
| `permissions` | Spatie; granular across 11 domains | name, guard_name, group |
| `model_has_roles` | Spatie pivot | — |
| `model_has_permissions` | Spatie pivot | — |
| `role_has_permissions` | Spatie pivot | — |
| `activity_logs` | Audit trail (§9, §23) | actor_id, actor_type, action, subject_type, subject_id, before (json), after (json), ip, user_agent, context |

`activity_logs` deliberately stores before/after snapshots. When a price or a provider setting
changes, "who changed it, when, from what, to what" must all be answerable.

---

## Group 2 — Billing, Plans & Credits (14 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `subscription_plans` | FREE/PRO/PREMIUM, fully configurable (§19) | uuid, name, slug, description, price, currency, billing_cycle, trial_days, is_public, sort_order, status |
| `plan_features` | Per-plan limits without schema changes | plan_id, key, value, limit_type (hard/soft/unlimited) |
| `plan_model_access` | Enable models per plan (§11) | plan_id, ai_model_id, is_allowed |
| `plan_provider_access` | Enable providers per plan | plan_id, ai_provider_id, is_allowed |
| `subscriptions` | Active user subscriptions | uuid, user_id, plan_id, status, current_period_start/end, cancel_at, gateway_subscription_id |
| `payments` | Payment intent/record (§20) | uuid, user_id, subscription_id, gateway, gateway_payment_id, amount, currency, status, paid_at |
| `payment_transactions` | Every gateway state change | payment_id, type, amount, status, gateway_reference, raw_payload (json) |
| `payment_webhook_events` | **Idempotency guard (§19)** | gateway, event_id (**unique**), event_type, payload, processed_at, result |
| `invoices` | Invoice records (§20) | uuid, user_id, number (sequential), subtotal, tax_total, total, currency, status, issued_at, pdf_media_id, **place_of_supply, supplier_gstin, customer_gstin, sac_code, is_export, tax_breakdown (json)** |
| `tax_rates` | Configurable tax rules (D-12) | uuid, name, jurisdiction, rate_percent, tax_type (cgst/sgst/igst/vat/none), applies_from, applies_until, is_active |
| `exchange_rates` | **USD provider cost → INR revenue (D-01)** | from_currency, to_currency, rate, effective_date, source, unique(from,to,effective_date) |
| `coupons` | Promotions (§20) | code (unique), type, value, max_redemptions, redeemed_count, valid_from, valid_until, plan_restrictions (json) |
| `coupon_redemptions` | Prevent reuse | coupon_id, user_id, payment_id, redeemed_at |
| `credit_ledger` | **Append-only ledger (§19)** | uuid, user_id, entry_type, amount, balance_after, reason, reference_type, reference_id, expires_at, actor_id |
| `credit_balances` | Fast current balance | user_id (unique), confirmed_balance, held_balance, updated_at |
| `credit_holds` | Pre-authorisation | uuid, user_id, amount, status (held/settled/released), reference, expires_at |

### Why `exchange_rates` exists — a direct consequence of decision D-01

Aziv AI's costs and its revenue are in **different currencies**. AI providers bill in **USD**, at
fractions of a cent per token. Customers in India are charged in **INR**.

Blueprint §21 requires cost-versus-revenue and margin reporting. Comparing a USD cost to an INR
price is meaningless without a rate, and using *today's* rate to evaluate *last quarter's* margin
silently rewrites history every time the rupee moves.

So: `api_usage_logs.provider_cost` is stored in its **native currency with the currency recorded
alongside it**, and `exchange_rates` holds a dated rate. Margin for any period is computed at the
rate effective on each usage date. The result is a margin figure that does not change retroactively.

Rates are refreshed by a scheduled job from a configurable source, with the last known rate used
if a refresh fails — a stale rate is far better than a missing one.

### How the ledger stays correct

`credit_ledger` is **append-only**: rows are inserted, never updated or deleted. Corrections are
new rows of type `adjustment` with a reason. This means the full financial history of every
account is permanently reconstructible — which is what you want when a customer disputes a
charge.

`credit_balances` is a cached running total. It is updated inside the same database transaction
as the ledger insert, with a row-level lock (`SELECT … FOR UPDATE`), so two simultaneous requests
cannot both read the same starting balance and both spend it. A scheduled job re-verifies the
cached balance against the ledger sum nightly and alerts on any drift.

Spendable balance = `confirmed_balance − held_balance`.

---

## Group 3 — AI Providers & Credentials (7 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `ai_providers` | Provider registry (§10) | uuid, name, slug (unique), adapter_type, api_base_url, api_format, auth_method, status, priority, region, account_class (free/paid/enterprise/unknown), maintenance_mode, timeout_seconds, max_retries |
| `ai_provider_credentials` | **Encrypted keys (§10, Rule 6)** | uuid, provider_id, label, credential (**encrypted**), extra_config (encrypted json), status, priority, last_used_at, last_verified_at, quota_note |
| `credential_usage_counters` | Per-key quota awareness (Rule 7) | credential_id, window_start, request_count, token_count |
| `ai_provider_budgets` | Spend caps (§10, §21) | provider_id, period (daily/monthly), budget_amount, currency, spent_amount, threshold_percent, action_on_breach |
| `ai_provider_budget_alerts` | Alert history | budget_id, threshold_hit, notified_at, action_taken |
| `provider_health_logs` | Health samples (§14) | provider_id, model_id, checked_at, success, latency_ms, error_class, http_status |
| `provider_circuit_state` | Circuit breaker (§14) | provider_id (unique), state (closed/open/half_open), failure_count, opened_at, next_probe_at |

### On credentials and quotas — the honest position

Blueprint §10 allows multiple credentials "for legitimate rotation/redundancy, subject to
provider terms." Rule 7 forbids using multiple keys to circumvent quotas, billing or rate limits.

These are reconciled as follows: multiple credentials are supported for genuine operational
reasons — separate billing accounts for separate business units, a standby key for when one is
revoked, regional accounts. `credential_usage_counters` tracks usage per key so limits are
respected rather than evaded, and the admin UI states this expectation plainly at the point
where a second key is added. The system will **not** implement automatic key-cycling on
quota-exhaustion errors, because that behaviour has no purpose other than the one Rule 7
prohibits.

---

## Group 4 — AI Model Catalog (5 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `ai_models` | The catalog (§11) | uuid, provider_id, model_identifier, display_name, description, status (stable/preview/experimental/deprecated/disabled), modality, context_window, max_output_tokens, is_enabled, sort_order, discovered_at, source (synced/manual) |
| `ai_model_capabilities` | Capability flags (§11) | model_id, capability, is_supported, metadata (json) |
| `ai_model_prices` | **Cost vs price (§13)** | model_id, unit (per_1k_input/per_1k_output/per_image/per_second/per_request), provider_cost, currency, credit_cost, effective_from, effective_until |
| `ai_model_sync_logs` | Sync audit (§11) | provider_id, started_at, finished_at, status, models_added, models_updated, models_deprecated, error_message, raw_response_digest |
| `custom_provider_mappings` | Admin-defined APIs (§10) | provider_id, capability, http_method, endpoint_path, request_template (json), response_mapping (json), stream_format, headers_template (json) |

### Why `ai_model_prices` is a separate, dated table

Two requirements force this shape:

1. **§13 requires provider cost and customer price to be tracked separately.** `provider_cost` is
   what the AI company charges Aziv AI. `credit_cost` is what Aziv AI charges the customer. The
   gap between them is the margin that §21's analytics report.
2. **Prices change.** `effective_from` / `effective_until` mean a usage record from March is
   costed at March's price, not today's. Without this, changing a price would silently rewrite
   the profitability of your entire history.

### Why models are never hard-coded (Rule 5)

No PHP file will ever contain a string like `'gpt-4o'` or `'gemini-2.0-flash'` in business logic.
The router asks the database "which enabled models support vision, are allowed on this user's
plan, and belong to a healthy provider?" `ModelSyncService` populates that catalog from each
provider's model-discovery endpoint on a schedule and on demand. When a provider offers no
discovery endpoint, an admin adds the model by hand and it is marked `source = manual`.

The practical result: when a provider releases a new model, it appears in your panel after a
sync — with **no code change and no developer**.

---

## Group 5 — Routing & Usage (3 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `routing_logs` | Every decision (§14) | uuid, user_id, request_type, routing_mode, capability_required, candidates (json), selected_provider_id, selected_model_id, fallback_depth, decision_reason, decided_at |
| `api_usage_logs` | Every provider call (§13, §21) | uuid, user_id, provider_id, model_id, credential_id, capability, input_tokens, output_tokens, total_tokens, latency_ms, http_status, error_class, provider_cost, credit_cost, occurred_at |
| `message_usage` | Usage tied to a chat message (§26) | message_id, usage_log_id, input_tokens, output_tokens, credit_cost |

`routing_logs.candidates` stores the models that were considered and why each was rejected. When
you ask "why did this request go to the expensive model?", the answer is a database row, not
guesswork.

`api_usage_logs` is the highest-volume table in the system and is partition-ready by month, with
an admin-configurable retention window.

---

## Group 6 — Chat (6 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `chat_conversations` | (§15) | uuid, user_id, title, persona_id, pinned_model_id, routing_mode, is_archived, last_message_at |
| `chat_messages` | (§15) | uuid, conversation_id, role (user/assistant/system/tool), content (longtext), status, provider_id, model_id, parent_message_id, regenerated_from_id, error_class, finished_at |
| `chat_message_attachments` | Files in chat | message_id, file_id, kind |
| `message_feedback` | Thumbs up/down (§15) | message_id, user_id, rating, comment |
| `personas` | Admin system prompts (§15) | uuid, name, system_prompt, is_default, plan_restrictions (json), status |
| `conversation_shares` | Optional share links | conversation_id, token, expires_at, is_public |

`parent_message_id` and `regenerated_from_id` support branching and regeneration without
destroying the original answer — required by §15's "regenerate" while preserving history.

---

## Group 7 — Files & Knowledge Base (6 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `files` | (§17) | uuid, user_id, disk, path, original_name, mime_type, size_bytes, checksum, extraction_status, scan_status, expires_at |
| `file_chunks` | RAG chunks (§17) | file_id, chunk_index, content, token_count, metadata (json) |
| `file_scan_results` | Security scan (§17) | file_id, scanner, verdict, details, scanned_at |
| `knowledge_bases` | (§17) | uuid, name, owner_id, visibility, retrieval_settings (json), status |
| `knowledge_base_files` | Membership pivot | knowledge_base_id, file_id, added_at |
| `embeddings` | Vector references (§17, §26) | chunk_id, model_id, dimensions, vector (see note), created_at |

**The vector-storage question is genuinely open** and is decision **D-03**. MySQL 8 has no native
vector type, so the options are: store vectors as JSON and compute similarity in PHP (fine for
small knowledge bases, slow beyond a few thousand chunks); use MySQL 9's `VECTOR` type; use
PostgreSQL with `pgvector` (the strongest option); or use a dedicated service. The `embeddings`
table is designed so the storage backend can be swapped without touching the rest of the schema.

---

## Group 8 — Image & Audio (2 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `image_generations` | (§16) | uuid, user_id, provider_id, model_id, prompt, negative_prompt, parameters (json), status, media_id, credit_cost, error_class, parent_generation_id |
| `audio_jobs` | (§18) | uuid, user_id, direction (stt/tts), provider_id, model_id, input_file_id, output_media_id, duration_seconds, status, credit_cost |

`parent_generation_id` supports §16's regeneration and prompt history. Both tables carry
`status`, so long-running jobs report progress rather than appearing frozen.

---

## Group 9 — Branding, Theming & Content (11 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `themes` | (§5) | uuid, name, slug, description, is_builtin, is_active, base_theme_id, supports_dark, custom_css, version, published_at |
| `theme_tokens` | Every colour and dimension (§5) | theme_id, mode (light/dark), token_group, token_key, token_value |
| `media_assets` | Media library (§4) | uuid, disk, path, original_name, mime_type, size_bytes, width, height, alt_text, purpose, uploaded_by |
| `content_pages` | CMS pages (§7) | uuid, slug, title, status, seo (json), published_at |
| `content_sections` | Page building blocks (§7) | page_id, type, sort_order, payload (json), is_visible |
| `banners` | Announcements (§7) | uuid, title, body, variant, cta_label, cta_url, priority, starts_at, ends_at, audience, is_active |
| `faqs` | (§7) | question, answer, category, sort_order, is_published |
| `navigation_menus` | One per location (§6, D-11) | key, name, location (`customer_bottom_nav`, `customer_drawer`, `customer_sidebar`, `admin_sidebar`, `footer`), max_items |
| `navigation_items` | Menu entries (§6, D-11) | menu_id, parent_id, label, icon, destination_type (route/page/external), route_name, url, sort_order, is_visible, device_visibility (json), permission, plan_restrictions (json), feature_flag_key, badge_source |
| `feature_flags` | Staged releases (§6, §24) | key (unique), name, description, is_enabled, rollout_strategy, conditions (json) |
| `system_settings` | **The configuration backbone (§24)** | key (unique), value (longtext), type, group, is_encrypted, is_public, updated_by |

`navigation_items` is what makes the owner's D-11 requirement work: labels, icons, ordering,
visibility and destinations are rows, so navigation changes need no code. `device_visibility`
lets one item appear in the mobile bottom bar and the desktop sidebar simultaneously, while
`navigation_menus.max_items` enforces the 4-item bottom-bar cap that the 44px touch-target rule
imposes.

`theme_tokens` is the heart of §5. Instead of colours living in stylesheets, each theme owns a
set of named tokens (`color.primary`, `color.surface`, `radius.md`, `shadow.lg`) in both light
and dark modes. Full detail in `08-theme-branding-system.md`.

---

## Group 10 — Notifications & Operations (7 tables)

| Table | Purpose | Key columns |
|---|---|---|
| `notifications` | Laravel in-app notifications (§22) | id (uuid), type, notifiable, data (json), read_at |
| `notification_templates` | Editable templates (§22) | key, channel, subject, body, variables (json), is_active |
| `announcements` | Broadcast messages (§22) | uuid, title, body, audience, starts_at, ends_at, is_active |
| `notification_deliveries` | Delivery audit (§22) | template_key, user_id, channel, status, sent_at, error |
| `jobs`, `job_batches`, `failed_jobs` | Queue infrastructure | Laravel standard |
| `cache`, `cache_locks` | Cache fallback for basic hosting (§2) | Laravel standard |

---

## Indexing and performance plan

The tables that will grow fastest are `api_usage_logs`, `routing_logs`, `chat_messages` and
`credit_ledger`. Each gets composite indexes matched to its actual query patterns:

| Table | Index | Serves |
|---|---|---|
| `api_usage_logs` | `(user_id, occurred_at)` | User usage screens |
| `api_usage_logs` | `(provider_id, occurred_at)` | Provider cost analytics |
| `api_usage_logs` | `(model_id, occurred_at)` | Per-model margin reports |
| `credit_ledger` | `(user_id, created_at)` | Statement view |
| `chat_messages` | `(conversation_id, created_at)` | Loading a conversation |
| `chat_conversations` | `(user_id, last_message_at)` | Chat list ordering |
| `routing_logs` | `(selected_provider_id, decided_at)` | Routing diagnostics |
| `ai_models` | `(provider_id, is_enabled, status)` | The router's hot path |
| `provider_health_logs` | `(provider_id, checked_at)` | Health dashboards |

Analytics (§21) will **not** be computed by scanning raw logs on every page load. Nightly
aggregation jobs roll usage into daily summary tables, so a dashboard covering a year of data
reads a few hundred rows rather than millions. This is the difference between a dashboard that
loads instantly at scale and one that times out.

## Migration discipline (Rule 10)

- Migrations are **additive**. Once a table ships, columns are added, not renamed or dropped.
- Every migration is reversible.
- Reference data (permissions, built-in themes, default settings) ships via **idempotent
  seeders** that can be re-run safely — they update existing rows rather than duplicating them.
- No migration ever destroys user data as part of a feature change.
