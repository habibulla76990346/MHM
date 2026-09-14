<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChatServiceProvider;
use App\Providers\DiagnosticsServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FilesServiceProvider;
use App\Providers\ImagesServiceProvider;
use App\Providers\InstallServiceProvider;
use App\Providers\KnowledgeServiceProvider;
use App\Providers\SecurityServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,
    ChatServiceProvider::class,
    AdminPanelProvider::class,
    SettingsServiceProvider::class,
    SecurityServiceProvider::class,
    FilesServiceProvider::class,
    ImagesServiceProvider::class,
    InstallServiceProvider::class,
    KnowledgeServiceProvider::class,
    DiagnosticsServiceProvider::class,
];
