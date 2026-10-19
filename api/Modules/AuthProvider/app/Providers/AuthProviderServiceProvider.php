<?php

namespace Modules\AuthProvider\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Tag;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\AuthProvider\Console\AuthProviderE2eCommand;
use Modules\AuthProvider\Services\ProviderConfigService;

class AuthProviderServiceProvider extends ServiceProvider
{
    protected string $name = 'AuthProvider';

    public function register(): void
    {
        $this->mergeConfigFrom(
            module_path($this->name, 'Config/config.php'),
            'authprovider'
        );

        $this->app->singleton(ProviderConfigService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->name, 'Database/Migrations'));

        if (! config('authprovider.enabled')) {
            return;
        }

        // OAuth / OIDC drivers are built per provider row (SocialiteDriverFactory): no
        // application-wide `services.<driver>` configuration is involved
        $this->registerRoutes();
        $this->registerCommands();
        $this->registerApiTags();
    }

    protected function registerRoutes(): void
    {
        // Protected API routes (auth required)
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(module_path($this->name, 'routes/api.php'));

        // Public API routes (no auth)
        Route::middleware('api')
            ->prefix('api')
            ->group(module_path($this->name, 'routes/public.php'));

        // Web routes (OAuth callback — GET redirect)
        Route::middleware('web')
            ->group(module_path($this->name, 'routes/web.php'));
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AuthProviderE2eCommand::class]);
        }
    }

    protected function registerApiTags(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        Scramble::afterOpenApiGenerated(function ($openApi) {
            $openApi->tags[] = new Tag('Auth Providers', 'OAuth/SSO provider management');
        });
    }
}
