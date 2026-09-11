<?php

use App\Domains\Settings\Services\SettingsService;

if (! function_exists('settings')) {
    /**
     * Read a setting, or get the service when called with no arguments.
     *
     *   settings('branding.app_name');
     *   settings()->set('system.maintenance_mode', true, $actorId);
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $service = app(SettingsService::class);

        if ($key === null) {
            return $service;
        }

        return $service->get($key, $default);
    }
}

if (! function_exists('brand')) {
    /**
     * The web-root-relative path to a brand asset, or the branding service
     * when called with no arguments.
     *
     *   brand('logo_light');            // 'brand/logo-light-bg.png'
     *   brand()->url('favicon');        // full URL
     *
     * Always falls back to the artwork that ships with Aziv AI, so a template
     * can use this without guarding for an administrator who has not uploaded
     * anything — or for a published file that a deploy failed to copy.
     */
    function brand(?string $purpose = null): mixed
    {
        $service = app(\App\Domains\Branding\Services\BrandingService::class);

        return $purpose === null ? $service : $service->path($purpose);
    }
}
