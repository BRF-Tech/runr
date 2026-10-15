<?php

namespace App\Providers;

use App\Services\Ai\Hub\HubToken;
use App\Services\Ai\Models\ModelStorage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * AI: GPU worker nodes / GPU workloads and Hugging Face models (docs/ai.md, docs/huggingface.md).
 * Registers the API routes (routes/ai.php) and the per-request services. The abilities
 * models.view / models.deploy / models.manage / regions.gpu.manage are catalog permissions
 * (config/permissions.php, docs/permissions.md); config/route_permissions.php maps the routes.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The token is read once per request / job (Octane-safe)
        $this->app->scoped(HubToken::class);
        $this->app->singleton(ModelStorage::class);
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(base_path('routes/ai.php'));
    }
}
