<?php

namespace App\Providers;

use App\Domains\Files\Contracts\FileScanner;
use App\Domains\Files\Models\File;
use App\Domains\Files\Policies\FilePolicy;
use App\Domains\Files\Scanners\NullScanner;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class FilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Selected by configuration, same adapter pattern as AI providers and
        // payment gateways. ClamAV and API scanners arrive in Phase 8.
        $this->app->bind(FileScanner::class, function () {
            return match (settings('uploads.scanner')) {
                default => new NullScanner(),
            };
        });
    }

    public function boot(): void
    {
        Gate::policy(File::class, FilePolicy::class);
    }
}
