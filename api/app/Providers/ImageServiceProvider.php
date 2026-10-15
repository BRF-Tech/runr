<?php

namespace App\Providers;

use App\Http\Middleware\NoStoreResponse;
use App\Jobs\Images\ExpireImageUploadsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Uploaded images (docs/images.md): the /api/images routes (routes/images.php) and the hourly
 * expiry of abandoned uploads. The abilities images.view / images.upload / images.delete are
 * catalog permissions (config/permissions.php, route rules in config/route_permissions.php).
 */
class ImageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->routesAreCached()) {
            // no-store: upload progress and registry state are live
            Route::middleware(['api', 'auth:sanctum', NoStoreResponse::class])
                ->prefix('api')
                ->group(base_path('routes/images.php'));
        }

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->job(new ExpireImageUploadsJob)
                ->hourly()
                ->name('image-uploads-expire')
                ->withoutOverlapping(30);
        });
    }
}
