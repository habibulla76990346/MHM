# 01 — Server requirements

Send this page to your hosting provider if you are not sure whether your plan is suitable.

## The minimum

| | Requirement |
|---|---|
| PHP | **8.3, 8.4 or 8.5**. 8.4 is what this was built and tested on |
| Database | **MySQL 8.0+** or **MariaDB 10.6+** |
| Disk | 2 GB for the application, plus whatever your customers upload and generate |
| Memory | 256 MB PHP memory limit; 512 MB is comfortable |
| Cron | The ability to run a scheduled task **once a minute** |
| Outbound HTTPS | **Required.** Every AI reply and every payment depends on it |
| HTTPS | A certificate on your domain. Free ones are fine |

## Required PHP extensions

`pdo_mysql` · `mbstring` · `openssl` · `tokenizer` · `xml` · `dom` · `ctype` · `json` ·
`fileinfo` · `filter` · `hash` · `session` · `pcre` · `curl` · `gd` · `zip` · `intl` · `iconv`

All of these are standard. If your host does not have `fileinfo` or `gd`, ask them to enable
them — the first is how uploads are checked for safety and the second is how images are handled.

## Nice to have, not needed

| Extension | What it improves |
|---|---|
| `redis` | Faster cache, sessions and queue on a busy site |
| `opcache` | Significantly faster PHP. Ask for it |
| `exif` | Extra checks on uploaded photos |
| `sodium` | Modern encryption primitives |

## What you do NOT need

- **SSH.** There is a browser installer and an in-panel maintenance screen.
- **Composer or Node.** The release package ships the dependencies and the compiled assets.
- **Redis, Supervisor, or a persistent worker.** They make it faster; nothing requires them.
- **Root access.**

## Checking your own server

After installing, `Admin → System Health` reports every item above against **your** server, says
which ones are your host's responsibility, and gives you the exact sentence to send them.

Before installing, the first page of the browser installer runs the same checks.

## The one that stops everything

**Outbound HTTPS.** A small number of shared hosts block outgoing connections by default. Aziv AI
cannot function without them — every AI reply, every payment verification and every exchange-rate
update is an outgoing HTTPS request. Ask your host explicitly:

> "Does my hosting plan allow outbound HTTPS connections from PHP to third-party APIs? If it is
> restricted, please enable it."
