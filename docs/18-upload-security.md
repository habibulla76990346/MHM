# Aziv AI — File Upload Security

**Status: OWNER ADDENDUM H** — decision D-08 as modified by the owner. Tracked as `US-1 … US-12`
in the requirements register.

> The owner's instruction: *"Security for file uploads must be implemented from the beginning…
> Malware/virus scanning should be designed as an extensible security layer… do not weaken the
> basic upload security in Phase 1."*

**This moves upload security from Phase 8 to Phase 1, and the owner is right to insist.** File
upload is the single most commonly exploited feature in web applications, and security that arrives
after the feature does is security applied to code already written around insecure assumptions.
Building it into the foundation costs less and works better.

---

## 1. The nine Phase 1 controls

Each control, the attack it defeats, and how it is implemented.

### US-1 · File type validation — allowlist, never denylist

**Defeats:** uploading executable or script content by using an unanticipated extension.

Only explicitly permitted types are accepted. A denylist fails the moment someone finds a type
nobody thought to block — `.phtml`, `.phar`, `.htaccess`, `.svg` carrying script. The permitted set
is admin-configurable per feature (chat attachment, knowledge base, avatar) and starts narrow.

### US-2 · MIME validation from content, not from the request

**Defeats:** a PHP script sent with `Content-Type: image/png`.

The browser-supplied content type is **attacker-controlled and never trusted**. The real type is
detected from the file's actual bytes using `finfo` (hence the `fileinfo` extension being a
required, diagnostic-checked dependency).

### US-3 · Extension validation, cross-checked against detected type

**Defeats:** `invoice.pdf.php`, `photo.php.jpg`, and extension/content mismatches generally.

The final extension must be in the allowlist **and** must correspond to the MIME type detected in
US-2. Files with multiple extensions are rejected rather than normalised — normalising is where
bypasses live. Uploads are stored with a **generated** name, so the original extension never
reaches the filesystem.

### US-4 · Size limits, enforced in layers

**Defeats:** disk exhaustion and memory-exhaustion denial of service.

Per-type and per-plan limits, admin-configurable, validated **against the server's real
`upload_max_filesize` and `post_max_size`** — a configured cap higher than the server permits
produces a confusing silent failure, so the diagnostics system (Addendum G) reports the mismatch.
Storage quota per user is checked before accepting, not after writing.

### US-5 · Filename and path security

**Defeats:** path traversal (`../../.env`), null-byte injection, overlong names, and
filesystem-specific control characters.

**The client filename is treated as untrusted display text and nothing more.** The stored name is a
generated UUID plus a validated extension. The original is kept in the database column
`original_name` for display, sanitised on output. No user input ever participates in constructing a
filesystem path.

### US-6 · Storage isolation — outside the web root

**Defeats:** direct execution of an uploaded file, and direct URL access bypassing authorisation.

> **User uploads are never placed in a publicly served directory.** Not in `public/`, not behind
> `storage:link`. They live on a private disk outside the document root and are served only through
> an authorising controller.

This single control neutralises most upload attacks: even if a malicious file were somehow stored,
there is no URL that would cause the web server to execute it. On shared hosting where the
document root cannot be moved, defence in depth applies — a `.htaccess` denying execution, plus a
**diagnostic check that requests an uploaded file's path over HTTP and reports RED if it is
reachable.**

### US-7 · Dangerous file prevention

**Defeats:** stored XSS, polyglot files, and metadata-based exploits.

- Executable and script types rejected outright: `.php`, `.phtml`, `.phar`, `.exe`, `.sh`, `.bat`,
  `.jsp`, `.asp`, `.cgi`, `.htaccess`
- **SVG is treated as active content**, because it can carry JavaScript. Either rejected or
  sanitised through an XML allowlist before storage — never served inline unsanitised
- Image files re-encoded on upload where practical, which strips embedded payloads
- EXIF and metadata stripped — this also removes **GPS coordinates from user photos**, a privacy
  matter as much as a security one
- Archives (`.zip`) not expanded server-side in v1; zip-bomb and traversal handling is a Phase 8
  concern once file analysis needs it

### US-8 · Upload authorisation

**Defeats:** unauthenticated or over-quota uploads, and one user writing into another's storage.

Every upload passes a policy check **before any bytes are written**: is this user authenticated, do
they have the permission, does their plan allow this feature, are they within quota and rate limit.
Rate limiting is per user and per IP.

### US-9 · Secure download and access rules

**Defeats:** IDOR — changing an ID in a URL to read someone else's file.

- Files addressed by **UUID, never sequential ID**
- **Every download runs the ownership/permission policy** — a valid URL is not itself authorisation
- Where a shareable link is genuinely needed, it is a **signed, expiring URL**, not a guessable path
- Responses set `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`, so the
  browser downloads rather than renders — preventing stored HTML or SVG from executing in the
  application's origin
- Downloads are audit-logged for sensitive knowledge-base files

---

## 2. Scanning as an extensible layer (US-10)

The owner's requirement: scanning is designed as an extensible layer, supported as an optional
production capability where infrastructure allows — **without weakening the Phase 1 controls**.

```php
interface FileScanner
{
    public function key(): string;
    public function isAvailable(): bool;          // reported by diagnostics
    public function scan(File $file): ScanVerdict; // clean | infected | error | skipped
}
```

| Implementation | Requires | Available on |
|---|---|---|
| `NullScanner` (default) | Nothing | Everywhere — records `skipped`, and diagnostics report scanning as **GREY / not configured** rather than pretending it ran |
| `ClamAvScanner` | ClamAV daemon | VPS / cloud — not installable on shared hosting |
| `ApiScanner` | A third-party scanning API key | Anywhere with outbound HTTPS — **works on shared hosting** |

Selected by configuration, same adapter pattern as AI providers and payment gateways. Admin
configures the policy: quarantine on infection, quarantine on scanner error, or allow with a
warning. Verdicts are recorded in `file_scan_results` with the scanner that produced them.

**The honest position:** without a scanner configured, Aziv AI is not scanning for malware, and the
diagnostics screen says exactly that rather than showing a reassuring green tick. The nine controls
above are what actually carry the security, and they hold with or without a scanner.

---

## 3. Where this lands in the build

| Phase | Work |
|---|---|
| **1** | All nine controls, the `FileScanner` interface, `NullScanner`, the authorising download controller, and the diagnostic check that uploads are not web-reachable |
| **2** | Media library uses the same pipeline — branding uploads are not a separate, weaker path |
| **8** | File analysis and knowledge bases build on the existing pipeline; `ClamAvScanner` and `ApiScanner` adapters; archive handling |
| **9** | Adversarial review: attempt each defeated attack above and confirm it fails |

**One pipeline, no exceptions.** Every upload in the platform — chat attachment, avatar, brand
logo, knowledge-base document, imported file — passes through the same validated path. A second
upload route is how these controls get bypassed in practice, so there is not one.

## 4. Schema additions

| Table | Change |
|---|---|
| `files` | + `original_name` (display only), `stored_name` (generated), `disk`, `detected_mime`, `declared_mime`, `checksum`, `scan_status`, `scan_verdict`, `scanner_key`, `quarantined_at` |
| `file_scan_results` | scanner_key, verdict, details, scanned_at, duration_ms |
| `file_access_logs` | file_id, user_id, action, ip, occurred_at — for sensitive files |

## 5. Effort impact

| | |
|---|---|
| Phase 1 | **+1 session** — the nine controls, scanner interface, download controller |
| Phase 8 | **−0.5 session** — the pipeline already exists |
| **Net** | **+0.5 session** |

Cheap, and cheaper here than anywhere later: retrofitting upload security means revisiting every
feature that already writes files, under the assumption that some of them did it unsafely.
