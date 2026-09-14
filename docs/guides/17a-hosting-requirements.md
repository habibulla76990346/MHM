# 17a–e — Hosting requirements

**Sections 17c and 17d below are written to be sent to a hosting provider as they stand.** Copy
the whole section into a support ticket — it asks precise questions rather than describing a
product.

---

## 17a — Shared hosting / cPanel: the minimum plan

| Requirement | Minimum | Note |
|---|---|---|
| PHP | 8.3, 8.4 or 8.5, selectable | 8.4 preferred |
| PHP extensions | `pdo_mysql` `mbstring` `openssl` `tokenizer` `xml` `dom` `ctype` `json` `fileinfo` `filter` `hash` `session` `pcre` `curl` `bcmath` `gd` (or `imagick`) `zip` `intl` `iconv` `sodium` | `intl` is the one budget plans most often omit |
| `memory_limit` | 512M | 256M works until a large PDF is indexed |
| `max_execution_time` | 120s | Only affects web requests; long work is queued |
| `upload_max_filesize` / `post_max_size` | 32M | Raise if customers upload large documents |
| Database | MySQL 8.0+ or MariaDB 10.6+ | |
| Disk | 2 GB to start | Generated images and recordings grow it |
| Document root | **Settable per domain** | Or `.htaccess` and the layout in [04](04-web-root.md) |
| Cron | **Yes, every minute** | Non-negotiable |
| Outbound HTTPS | **Yes, port 443, unrestricted** | See 17c — the usual reason a plan will not work |
| SSL certificate | Yes | Let's Encrypt is fine |

**Not required:** SSH, Composer, Node.js, root, Supervisor, Redis. The release package ships
`vendor/` and the compiled assets so the server never builds anything.

---

## 17b — Cloud / VPS: production requirements

| | Small | Growing |
|---|---|---|
| CPU | 2 vCPU | 4+ |
| RAM | 4 GB | 8 GB+ |
| Disk | 40 GB SSD | 80 GB+, or object storage |
| Database | Local MySQL 8 | Managed instance |
| Cache / queue | Redis | Redis, separate instance |
| Workers | Supervisor, 2 processes | Scaled to the queue depth |
| Backups | Daily, off the machine, **restore tested** | Same, plus point-in-time |

Plus everything in 17a. Details in [13](13-cloud-vps.md).

---

## 17c — Outbound API access

> **To our hosting provider:**
>
> Our application makes **outbound HTTPS requests on port 443** from the web server and from
> scheduled/queued PHP processes, to third-party APIs. Please confirm:
>
> 1. Are outbound connections on port 443 permitted from PHP (via cURL and PHP streams), or are
>    they blocked or restricted to an allowlist?
> 2. If there is an allowlist, please add the hosts below.
> 3. Is there an outbound proxy we must configure? If so, please provide its address.
> 4. Are outbound connections permitted from **cron jobs**, not only from web requests?
>
> **Hosts we need to reach**, all HTTPS/443, all outbound only:
>
> | Purpose | Host |
> |---|---|
> | AI provider (OpenAI) | `api.openai.com` |
> | AI provider (Anthropic) | `api.anthropic.com` |
> | AI provider (Google) | `generativelanguage.googleapis.com` |
> | AI provider (others, as configured) | `api.deepseek.com`, `api.mistral.ai`, `api.groq.com`, `openrouter.ai`, `api-inference.huggingface.co` |
> | Payment gateway | `api.razorpay.com` *(and any other gateway we enable)* |
> | Currency exchange rates | The rate feed configured in the application |
> | Outbound email | Our SMTP provider's host, on 587 or 465 |
>
> We need **no inbound access** other than normal HTTPS to the website, and **no outbound access on
> any other port**.

We only reach the providers we have configured. The list above is the full set the product knows
how to talk to, so an allowlist can be built from it in advance.

---

## 17d — SSL / HTTPS

> **To our hosting provider:**
>
> 1. Please confirm a valid TLS certificate is installed for our domain **and** any subdomain the
>    site is served from, and that it renews automatically.
> 2. Please confirm HTTP requests are redirected to HTTPS at the server, not only in application
>    code.
> 3. If TLS is terminated at a proxy, load balancer or CDN in front of our PHP server, please
>    confirm the proxy sends the `X-Forwarded-Proto` header, and tell us the proxy's IP range.

That last question is not a formality. Without it, every password reset, email verification and
payment link we send returns 403 — a signed link's signature covers the address including its
scheme, and behind an untrusted proxy the server sees plain HTTP. Set `TRUSTED_PROXIES` in `.env`
once you have the answer.

Also required for HTTPS to be worth having:

- Secure cookies are on in production, so the site **cannot** be used over plain HTTP.
- No page may load a script, stylesheet, image or font over `http://`. Browsers block it, and the
  page looks broken rather than insecure.

---

## 17e — What to aim for once you have outgrown shared hosting

In rough order of what you notice first:

1. **A persistent queue worker.** Jobs start in a second rather than at the next minute. The
   biggest single improvement to how the product feels.
2. **Redis** for cache and sessions.
3. **`opcache` enabled**, with a sensible `memory_consumption`.
4. **Object storage** for uploads, generated images and recordings — the thing that fills a disk.
5. **A managed database** with automated backups and point-in-time recovery.
6. **A separate staging copy** so an upgrade is rehearsed before it is applied.
7. **Off-machine backups**, restored on a schedule rather than merely taken.
