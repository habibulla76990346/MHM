# 05 — Storage setup

## Permissions

Two directories must be writable by the web server user:

```sh
chmod -R 775 storage bootstrap/cache
```

On cPanel this is usually already correct because everything runs as your own user. On a VPS
where PHP-FPM runs as `www-data`:

```sh
chown -R www-data:www-data storage bootstrap/cache
```

If **Admin → System Health** reports the filesystem as red, this is what it means. It verifies by
actually writing a file, not by reading permission bits — those can look right and still fail.

## Where things live

| Path | What is in it | Backed up? |
|---|---|---|
| `storage/app/private` | Every upload, knowledge-base file, generated image and recording | **Yes** |
| `storage/app/public` | Files deliberately published | Yes |
| `storage/logs` | Application logs | No |
| `storage/framework` | Caches and sessions | No |
| `public/brand` | Derived logo images, regenerated on demand | No |

**Nothing a customer uploads is ever placed in a web-served directory.** Files are served through
a controller that checks permission on every request.

`php artisan aziv:backup:manifest` prints this list resolved against your own configuration.

## The storage link

```sh
php artisan storage:link
```

Or **Admin → Maintenance → Rebuild the storage link** if you have no shell.

### Where symlinks are unavailable

A few shared hosts disable them. Aziv AI does not depend on the link — uploads are served through
a controller, not through the link — so a failure here is not fatal. Only the small number of
deliberately-public files are affected.

## Disk space

**Admin → System Health** reports free space and how much Aziv AI is using, and warns at 85%.

A full disk breaks everything at once and explains nothing: uploads fail, images fail, sessions
fail, and the log cannot record why. Two settings control the growth:

- **Admin → Media → Images and voice** — how long generated images and recordings are kept.
- **Admin → Knowledge** — storage per customer.
