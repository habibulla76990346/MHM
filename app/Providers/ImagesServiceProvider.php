<?php

namespace App\Providers;

use App\Domains\Images\Models\ImageGeneration;
use App\Domains\Images\Policies\ImageGenerationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ImagesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(ImageGeneration::class, ImageGenerationPolicy::class);
    }
}
