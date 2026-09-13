<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment mode
    |--------------------------------------------------------------------------
    | shared | cloud | auto
    |
    | Aziv AI supports two deployment modes from one codebase (Owner Addendum
    | B/G). The mode changes how diagnostics GRADE a finding, not what the
    | application can do: an absent Redis is expected on shared hosting and
    | reported GREY, but is a warning on cloud. Failures that are fatal in any
    | mode stay RED in both.
    */
    'deployment_mode' => env('AZIV_DEPLOYMENT_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Streaming mode
    |--------------------------------------------------------------------------
    | on | off | auto
    |
    | Many shared hosts buffer output, which breaks server-sent events. In
    | 'auto' the application probes once, caches the result, and falls back to
    | complete-answer delivery rather than appearing frozen.
    */
    'streaming_mode' => env('AZIV_STREAMING_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Required PHP extensions
    |--------------------------------------------------------------------------
    | Checked by the installer and by ADMIN -> System Health (Addendum G).
    | Documented in docs/15-delivery-and-handover.md §6.
    */
    'required_extensions' => [
        'pdo_mysql', 'mbstring', 'openssl', 'tokenizer', 'xml', 'dom',
        'ctype', 'json', 'fileinfo', 'filter', 'hash', 'session', 'pcre',
        'curl', 'gd', 'zip', 'intl', 'iconv',
    ],

    'optional_extensions' => [
        'redis'   => 'Production cache and queue driver',
        'exif'    => 'Image upload validation',
        'opcache' => 'Significant PHP performance gain',
        'sodium'  => 'Modern encryption primitives',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP security headers
    |--------------------------------------------------------------------------
    | Applied by App\Http\Middleware\SecurityHeaders. See that class for why
    | this list is short and what is deliberately NOT in it.
    */
    'security' => [

        /*
        | Strict-Transport-Security, in seconds. Sent only on requests that
        | actually arrived over TLS — announcing it over plain HTTP is both
        | ignored and meaningless.
        |
        | SIX MONTHS, NOT TWO YEARS. This header is a PROMISE a browser
        | remembers and cannot be told to forget: until it expires, that
        | browser will refuse to reach the site over HTTP at all. An owner
        | whose certificate lapses has locked out every returning visitor for
        | the remainder of the window. Six months is long enough to be worth
        | having and short enough to recover from.
        |
        | 0 disables the header, which is the right setting while a
        | certificate is still being sorted out.
        */
        'hsts_max_age' => (int) env('HSTS_MAX_AGE', 15552000),

        /*
        | Only turn this on once every subdomain is on HTTPS — it applies the
        | promise above to all of them, including ones that do not exist yet.
        */
        'hsts_include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
    ],

    /*
    | Minimum supported PHP. The app targets 8.4 but runs on 8.3-8.5 so it
    | stays portable across hosts (decision E-1).
    */
    'min_php_version' => '8.3.0',
];
