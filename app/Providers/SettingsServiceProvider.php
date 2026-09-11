<?php

namespace App\Providers;

use App\Domains\Settings\Services\SettingRegistry;
use App\Domains\Settings\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingRegistry::class);
        $this->app->singleton(SettingsService::class);
    }
}
