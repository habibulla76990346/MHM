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
