# 18 — Security checklist

Work through this before the first real customer, and again after any move or major upgrade.
**Admin → System Health** checks most of it automatically; the items it cannot check are marked
*by hand*.

## The five that actually lose you the company

- [ ] **`APP_DEBUG=false`.** With it on, any error prints your entire environment — every provider
      key, every gateway secret, the database password — to whoever triggered the error. Checked.
- [ ] **The document root points at `public/`.** Anything higher serves `.env` to anybody who asks
      for it. *Confirm by hand:* open `https://yourdomain.com/.env` from a browser that is not
      signed in. You want 404 or 403.
- [ ] **`APP_KEY` is backed up off this server.** Not for security — for survival. Every credential
      in the database is encrypted with it and nothing can recover them without it.
- [ ] **HTTPS everywhere, HTTP redirected at the server.** Checked.
- [ ] **A backup has been restored, not just taken.** An untested backup is a belief. See
      [19 — Backup and restore](../19-backup-and-restore.md); the restore procedure there has
      actually been performed.

## Access

- [ ] No shipped default administrator exists — Aziv AI seeds none. Confirm nobody created a
      "test" one during setup. *By hand.*
- [ ] Every administrator has a **distinct** account. No shared logins.
- [ ] **Two-factor is on** for every administrator, and each has saved their recovery codes.
- [ ] **Super Admin** is held by as few people as possible — it bypasses individual permission
      checks by design.
- [ ] Anybody who has left has been removed, not merely deactivated. *By hand.*
- [ ] Session idle timeout and the concurrent-session policy are set to what you actually want:
      Admin → Settings → Security.

## The installer

- [ ] `/install` returns **404**. It locks itself on completion, but check.
- [ ] Better: delete `routes/install.php`, `app/Http/Controllers/Install/` and
      `resources/views/install/`. The application runs fine without them.

## Files and permissions

- [ ] `storage/` and `bootstrap/cache/` are writable; **nothing else is**. Checked.
- [ ] `.env` is mode `600` and owned by the user PHP runs as. *By hand.*
- [ ] Nothing sensitive is inside `public/`. The only thing written there at runtime is a derived
      brand image — a raster, named by content hash, never an uploaded SVG. Checked by the build.
- [ ] Your object-storage bucket, if you use one, is **not public**. Aziv AI serves files through
      its own controller so ownership is checked on every request. *By hand.*

## Credentials

- [ ] Provider keys and gateway secrets were entered in the Admin Panel, **never in a file, never
      in chat, never in a support ticket**.
- [ ] Each is a key created for Aziv AI alone, so revoking it breaks nothing else.
- [ ] Provider budgets are set, in **block** mode where a runaway bill would matter.
- [ ] Nothing you have exported for support contains a credential. It does not — `aziv:diagnose
      --export` scrubs at construction, so no output path can leak one — but do not defeat that by
      pasting `.env` into the same ticket.

## Network

- [ ] Only 80 and 443 are open inbound. Database, Redis and anything else bind to localhost or a
      private network. *By hand.*
- [ ] `TRUSTED_PROXIES` is set if — and **only** if — something terminates TLS in front of you.
      Trusting a proxy that is not there lets a client forge its own IP address.
- [ ] Security headers are served. Checked.

## Payments

- [ ] Live and sandbox credentials are not mixed up.
- [ ] The **live** webhook URL is in the gateway's **live** dashboard, with its own signing secret.
- [ ] One real payment has been taken with a real card, and refunded.
- [ ] Reconciliation is running — that means the cron job exists.

## Data

- [ ] Retention for generated images and voice recordings is set to what you told customers:
      Admin → Media.
- [ ] You know where customer data lives — the database, `storage/app/`, and your object store —
      and all three are in the backup.
- [ ] Your privacy policy and terms are published and say what is actually true, including which
      AI providers customers' messages reach. Content → Pages. *By hand.*

## After launch

- [ ] Read the audit log occasionally. Every administrator action records who, what, when, and the
      before and after.
- [ ] Read System Health after every change; it emails administrators when a check **changes**, and
      stays quiet while a known problem stays known.
- [ ] Apply updates. See [19 — Upgrading](19-upgrading.md).
- [ ] Rotate any credential that has ever been in a screenshot, a chat, a ticket, or a repository.
