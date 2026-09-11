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
    | Minimum supported PHP. The app targets 8.4 but runs on 8.3-8.5 so it
    | stays portable across hosts (decision E-1).
    */
    'min_php_version' => '8.3.0',
];
