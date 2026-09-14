# 04 — Web root and public directory

**This is the single most damaging thing to get wrong.**

The application folder contains `.env` — every provider key, your payment credentials, your
database password and `APP_KEY`. If your web server serves that folder, all of it is a URL that
anybody can fetch. The site works perfectly the whole time, which is why nobody notices.

## The correct layout

```
/home/you/aziv/            ← the application. NOT served.
    app/  config/  vendor/  .env  artisan
    public/                ← THIS is the document root
        index.php
```

Your domain must point at `/home/you/aziv/public`.

## cPanel

**Domains** → your domain → **Manage** → set the document root to the `public` subdirectory.
On older panels: **Addon Domains** or **Subdomains** → edit the document root.

## nginx

```nginx
root /home/you/aziv/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
}

# Nothing outside public/ is served, so there is nothing to deny here.
```

## Apache

```apache
DocumentRoot /home/you/aziv/public
<Directory /home/you/aziv/public>
    AllowOverride All
    Require all granted
</Directory>
```

## When you genuinely cannot change it

Some shared hosting fixes the document root at `public_html`. Then:

1. Put the application **outside** `public_html`, e.g. `/home/you/aziv`.
2. Copy the contents of `aziv/public` into `public_html`.
3. Edit `public_html/index.php` so the two `require` paths point up into `/home/you/aziv`.

This is the documented fallback and it works. Putting the whole application inside `public_html`
is not a fallback — it is the failure this page is about.

## Proving it is right

After installing, **Admin → System Health** makes real HTTP requests to your own site for `.env`,
`composer.json`, `artisan` and `.git/config`. If any of them answer, it reports **RED** with the
exact words to send your host.

You can check yourself right now:

```
https://your-domain/.env
```

That must return "not found". If it returns text, stop and fix the document root before doing
anything else — then rotate every credential, because they have been public.
