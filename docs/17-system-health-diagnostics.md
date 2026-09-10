# Aziv AI — System Health & Diagnostics

**Status: OWNER ADDENDUM G** — a binding cross-cutting requirement. Tracked as `HD-1 … HD-22` in
the requirements register.

> The owner's governing constraint: *"Do not show only generic messages such as 'Something went
> wrong.'"*

This addendum also **resolves blocker E-2**. See §9.

---

## 1. What this is for

Aziv AI will run on servers nobody has inspected in advance, chosen after the software was written,
by an owner who is not a developer. When something breaks on such a server, the default outcome is
a white page or a generic error, and no way to tell whether the fault is the application, the
server, the hosting plan, a provider, or a setting.

**The diagnostics system exists to make that never happen.** Its job is to answer three questions,
always:

1. What exactly is wrong?
2. Whose problem is it — the application, the hosting, a provider, or a setting?
3. What do I do about it, in language I can act on?

A design consequence follows immediately: since the answer is often *"ask your hosting provider
about X"*, the diagnostics output must be **safe to send to a hosting provider**. That shapes the
whole secret-handling design in §6.

---

## 2. Two deployment modes, one codebase

The owner has not chosen a hosting provider and will not be asked to at this stage. The same
package must run in either mode:

| | **Shared / cPanel mode** | **Cloud / VPS mode** |
|---|---|---|
| Setting | `AZIV_DEPLOYMENT_MODE=shared` | `=cloud` |
| Default | `=auto` — detected on first run and confirmable by the admin |
| Queue | Cron-driven batch worker | Persistent supervised worker |
| Cache / session | Database | Redis |
| Storage | Local disk | S3-compatible |
| Streaming | Detected, falls back cleanly | Enabled |

### Why the mode changes what diagnostics *say*

This is the detail that makes the screen usable rather than alarming.

**Redis being absent is not a problem on shared hosting — it is expected.** Reporting it as RED
would fill the screen with alarms about things that are working as designed, and an admin who sees
permanent red learns to ignore red. That is worse than no diagnostics at all.

So each check reports **relative to the active mode**:

| Condition | Shared mode | Cloud mode |
|---|---|---|
| Redis unavailable | **GREY** — not applicable, database driver in use | **YELLOW** — recommended for production |
| No persistent queue worker | **GREY** — cron mode active and healthy | **RED** — worker should be running |
| Streaming buffered | **YELLOW** — expected; fallback active | **RED** — should work here |
| `exec()` disabled | **GREY** — not used in any core path | **GREY** — same |
| Outbound HTTPS blocked | **RED / Critical** | **RED / Critical** |

The last row is the point: **some failures are fatal in any mode**, and those are never softened.

---

## 3. Status and severity are two different axes

The owner specified both, and conflating them loses information.

**Status** — the current state of this check:

| | Meaning |
|---|---|
| 🟢 **GREEN** | Working |
| 🟡 **YELLOW** | Working, but limited or degraded |
| 🔴 **RED** | Broken |
| ⚪ **GREY** | Not configured, or not applicable in this mode |

**Severity** — how much it matters:

| | Meaning | Example |
|---|---|---|
| **Critical** | Aziv AI cannot function | Outbound HTTPS blocked; database unreachable; `APP_KEY` missing |
| **High** | A major feature is broken | Mail failing; a payment gateway unreachable; cron not running |
| **Medium** | Degraded or risky | Streaming buffered; queue backlog growing; low disk |
| **Low** | Worth improving | OPcache off; config not cached |
| **Informational** | Context, not a problem | Deployment mode; PHP version; web server |

A GREY item can still carry High severity (a payment gateway with no credentials, when you intend
to charge customers). A YELLOW can be Low. The screen sorts by severity, then status.

---

## 4. What every finding must contain

The owner listed eleven fields. Each is a column on the result, not a formatting suggestion.

| # | Field | Notes |
|---|---|---|
| 1 | **Problem title** | Plain language, names the actual thing |
| 2 | **Category** | Environment · PHP · Filesystem · Database · Cache/Session · Queue/Cron · Network · Mail · Security · AI Providers · Payments · Application |
| 3 | **Severity** | Critical / High / Medium / Low / Informational |
| 4 | **Exact technical reason** | The real error, sanitised — cURL code, SQLSTATE, missing extension name, ini value vs required |
| 5 | **Responsibility** | Hosting · Application · Configuration · API/Provider · Database · Payment · Security · Network |
| 6 | **Recommended solution** | Plain language, actionable by a non-developer |
| 7 | **What the administrator must change** | The specific setting, file or panel screen |
| 8 | **Requires hosting-provider support?** | Boolean — and when true, **the exact wording to send them** |
| 9 | **Last checked** | Timestamp, plus whether cached or freshly run |
| 10 | **Re-test button** | Per check, rate-limited |
| 11 | **Diagnostic/log reference** | An ID resolving to a redacted log entry |

### The difference this makes

> ❌ **"Something went wrong."**

> ✅ **OpenAI API connection failed**
> **Severity:** Critical · **Category:** Network · **Responsibility:** Hosting
> **Reason:** cURL error 7 — could not connect to `api.openai.com:443`. No response after 10s.
> **What this means:** This server could not open an outbound HTTPS connection. Aziv AI needs this
> for every AI request.
> **What to do:** Contact your hosting provider and ask whether outgoing HTTPS/cURL requests to
> external APIs are permitted on your plan.
> **Send them this:** *"Does my hosting plan allow outgoing HTTPS (port 443) cURL requests to
> external APIs? Requests to api.openai.com are currently failing with cURL error 7."*
> **Requires hosting support:** Yes · **Last checked:** 2 minutes ago · **Log:** `DGN-4471`

> ✅ **PHP extension missing: fileinfo**
> **Severity:** High · **Category:** PHP · **Responsibility:** Hosting
> **Reason:** `extension_loaded('fileinfo')` returned false. Required for validating uploaded file
> types.
> **What this means:** File uploads cannot be safely validated, so file analysis is unavailable.
> **What to do:** Enable it in cPanel → Select PHP Version → Extensions, tick `fileinfo`. If it is
> not listed, ask your hosting provider to enable it.
> **Requires hosting support:** Only if not available in cPanel · **Last checked:** just now

---

## 5. The check registry — built to be extended

Every check is a class implementing one interface, discovered from a registry. **A new module or
provider registers its own checks**, which is the extensibility the owner asked for.

```php
interface DiagnosticCheck
{
    public function key(): string;             // 'network.outbound_https'
    public function title(): string;
    public function category(): Category;
    public function isApplicable(): bool;      // false → GREY, with a reason
    public function run(): CheckResult;

    public function isSafeToRunAutomatically(): bool;  // scheduled runs
    public function hasSideEffects(): bool;            // sends mail, creates data
    public function costsMoney(): bool;                // live provider call
}
```

`AiProvider` and `PaymentGateway` adapters each contribute their own checks, so adding a provider
in Phase 7 or a gateway in Phase 6 automatically adds its diagnostics — no separate work, and no
gap where a new integration has no health coverage.

### The full check catalogue

Every item the owner listed, plus the ones needed to make them meaningful.

**Environment & PHP** — deployment mode · PHP version and compatibility range · required extensions
(each pass/fail individually) · missing extensions · relevant disabled functions
(`disable_functions`) · `memory_limit` · `max_execution_time` · `upload_max_filesize` ·
`post_max_size` · `max_input_time` · OPcache · web server software and SAPI

**Filesystem & storage** — `storage/` writable · `bootstrap/cache/` writable · storage symlink
present (and `symlink()` available) · actual write test · disk space available · upload capability
verified by writing a real temp file

**Database** — connection · server version and compatibility · required privileges (verified by
attempting a temporary table) · charset and collation · **pending migrations** · connection latency

**Cache, session, queue, cron** — cache driver write/read/delete round trip · session driver ·
queue driver · **queue backlog and oldest pending job age** · failed job count · **cron liveness via
a scheduler heartbeat** · per-task last-run times · background job throughput

**Network** — **outbound HTTPS (the critical one)** · DNS resolution · TLS/CA bundle validity ·
site HTTPS and certificate validity · `APP_URL` scheme matches reality · proxy detection

**Mail** — configuration present · SMTP connection · authentication · **send test email** (manual
only — it has a side effect)

**AI providers** — per provider: credential present · authentication valid · connectivity and
latency · model catalog freshness · enabled models available · circuit-breaker state · budget status

**Payments** — per gateway: credentials present for the active mode · connectivity · authentication
· **webhook URL configured and reachable** · webhook secret set · last webhook received · signature
failure count · **sandbox/live mismatch detection**

**Security** — `APP_DEBUG` off · `APP_KEY` present · **encrypted credentials actually decryptable**
· **`.env` not reachable over HTTP** · `vendor/`, `storage/`, `.git/` not web-reachable · installer
locked or removed · HTTPS enforced · secure session cookie flags · directory listing disabled ·
default admin credentials changed

**Application** — pending migrations · config cached in production · required settings present ·
feature flags referencing missing features · retention jobs running · log file size

---

## 6. Never exposing secrets — by construction, not by filtering

The owner requires that diagnostics never expose API keys, passwords, webhook secrets or other
credentials. Filtering output afterwards is the wrong approach — it fails the first time an
unexpected format appears.

| Rule | Implementation |
|---|---|
| **Checks never receive secret values** | A check asks `CredentialResolver` *"is this valid?"* and receives a boolean plus an error class. The value never enters the diagnostic layer |
| **Provider responses are never echoed** | Only the HTTP status and a normalised error class are recorded. Response bodies can contain echoed credentials |
| **Exception messages are sanitised** | Passed through a redactor before storage, matching key-shaped patterns, connection strings, bearer tokens and `.env`-style assignments |
| **No masked values either** | Not even last-4. A masked key is still information, and the report is designed to be shared |
| **Log references, not log contents** | A finding carries an ID; the log entry it resolves to is redacted at write time |
| **Export is secret-free by construction** | Built from the same sanitised results — there is no code path that could place a credential into it |

### The shareable report

Since many findings resolve to *"contact your hosting provider"*, the panel produces an
**exportable diagnostic report** the owner can send to support without reading it for secrets
first. It contains environment facts, check results, error classes and timestamps — and by
construction cannot contain a credential.

**Permissions:** diagnostics reveal infrastructure detail, so the screen is permission-gated. The
security section requires a higher permission than the rest, and every export is audit-logged.

---

## 7. When checks run

| Trigger | What runs |
|---|---|
| **Installation** | Requirements check before writing anything — the installer refuses to proceed on a Critical failure |
| **First admin login after deploy** | Full run, surfaced as a banner if anything is Critical or High |
| **Manual "Run Diagnostics"** | Everything, including money-costing and side-effect checks, with confirmation |
| **Per-check re-test** | One check, rate-limited |
| **Scheduled (safe subset only)** | Free, side-effect-free checks only. Results cached; admins notified on a *transition* into Critical or High — not repeatedly |
| **On relevant events** | Saving provider credentials runs that provider's check; saving mail settings tests mail |

Three rules keep this from becoming a problem in itself:

1. **Diagnostics never run during a normal page load.** Slow checks are queued jobs; the screen
   reads cached results and shows their age.
2. **Scheduled runs never cost money and never have side effects.** A live AI call costs real
   money; a test email sends real mail. Both are manual-only, flagged in the UI.
3. **Alerts fire on state transitions**, so a persistent known issue does not generate a daily
   email that trains the owner to ignore alerts.

---

## 8. Database

| Table | Purpose | Key columns |
|---|---|---|
| `diagnostic_runs` | One execution | uuid, trigger (install/manual/scheduled/event), started_at, finished_at, deployment_mode, overall_status, counts by severity, run_by |
| `diagnostic_results` | One check outcome | run_id, check_key, title, category, status, severity, technical_reason (**sanitised**), responsibility, recommended_action, admin_action, requires_hosting_support, log_reference, duration_ms, checked_at |
| `diagnostic_baselines` | Detect drift | check_key, last_status, last_severity, changed_at, consecutive_failures |

History matters: `diagnostic_baselines` answers *"when did this start failing?"* — often the fastest
route to *"what changed?"*

---

## 9. This resolves blocker E-2

E-2 asked the owner to confirm, before Phase 0, that their host permits outbound HTTPS.

**That is no longer a blocker**, for a reason this addendum makes plain: the owner has not chosen a
host, will not be asked to, and the application must run on servers nobody inspected in advance.
Pre-verifying one specific server is the wrong shape of answer. **The right answer is a system that
tests it on every server it is ever deployed to and reports the result unmistakably** — which is
exactly what `network.outbound_https` does, as a Critical check running at installation, at first
admin login, on a schedule, and on demand.

**What has changed, honestly:** the *risk* has not disappeared — a host that blocks outbound HTTPS
still cannot run Aziv AI. What has changed is that this is now **detected in minutes with a clear
message and the exact wording to send to support**, rather than being discovered as a mysterious
failure after the platform is built. The installer will refuse to complete on that failure, so it
cannot be missed.

**Consequence: no blockers remain. Phase 0 can begin on the owner's approval.**

E-3 … E-8 (MySQL version, cron availability, SSH access, document root, upload and memory limits)
also stop being questions the owner must answer — **every one of them is now a diagnostic check**
that reports itself on whatever server is eventually used.

---

## 10. Build order

The framework lands early so it is useful throughout development, and each phase contributes its
own checks as its modules arrive.

| Phase | Diagnostics work |
|---|---|
| **0** | Requirements check used by the installer; environment, PHP, filesystem and database checks |
| **1** | Check registry, severity model, result storage, the Admin screen, security checks |
| **2** | Storage, mail, cache/session checks; deployment-mode detection and confirmation |
| **3** | AI provider connectivity, authentication, model catalog freshness |
| **5** | Circuit-breaker state, provider budget status, routing health |
| **6** | Payment gateway connectivity, webhook configuration, sandbox/live mismatch, tax configuration completeness |
| **8** | File processing capability, upload limits against configured caps, image and audio pipeline |
| **9** | Full catalogue review, shareable export, scheduled runs, documentation, and the troubleshooting guide written **around this screen** |

---

## 11. Effort impact

| Item | Cost |
|---|---|
| Framework, severity model, storage, Admin screen | **+1 to +1.5 sessions** (Phases 0–1) |
| Checks across all categories | **+1 to +1.5 sessions** spread across phases |
| Shareable export, scheduling, alert transitions | **+0.5 session** (Phase 9) |

**Total: +2 to +3 sessions. Project total: 40–56 sessions.**

For a non-developer running on hosting they have not yet chosen, this is among the highest-value
work in the plan. It converts every infrastructure failure from *"the site is broken and I do not
know why"* into a named problem, a responsible party, and a sentence to send to whoever can fix it.
It is also what makes the two-deployment-mode promise verifiable rather than merely asserted.
