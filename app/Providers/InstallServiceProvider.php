<?php

namespace App\Providers;

use App\Support\Install\EnvWriter;
use App\Support\Installer;
use Illuminate\Support\ServiceProvider;

/**
 * The installer's two collaborators.
 *
 * `EnvWriter` is bound to the application's OWN `.env` here rather than taking
 * the path from a request. A writer whose target could be chosen by the caller
 * is a file-write primitive with a web route in front of it.
 */
class InstallServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Installer::class);

        $this->app->bind(EnvWriter::class, fn () => new EnvWriter(base_path('.env')));
    }
}
