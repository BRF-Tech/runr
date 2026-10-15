<?php

namespace Modules\AuthProvider\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Tag;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\AuthProvider\Console\AuthProviderE2eCommand;
use Modules\AuthProvider\Services\ProviderConfigService;
use SocialiteProviders\GitLab\GitLabExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

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

        $this->registerRoutes();
        $this->registerCommands();
        $this->registerSocialiteProviders();
        $this->registerSocialiteConfigResolver();
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

    protected function registerSocialiteProviders(): void
    {
        $event = SocialiteWasCalled::class;

        if (class_exists(MicrosoftExtendSocialite::class)) {
            Event::listen($event, MicrosoftExtendSocialite::class);
        }

        if (class_exists(GitLabExtendSocialite::class)) {
            Event::listen($event, GitLabExtendSocialite::class);
        }
    }

    protected function registerSocialiteConfigResolver(): void
    {
        // Dynamically resolve Socialite config from database
        $configService = $this->app->make(ProviderConfigService::class);

        foreach (config('authprovider.drivers', []) as $driver) {
            $this->configureSocialiteDriver($driver, $configService);
        }
    }

    protected function configureSocialiteDriver(string $driver, ProviderConfigService $configService): void
    {
        // Set config dynamically so Socialite can resolve it
        $this->app->resolving('Laravel\Socialite\SocialiteManager', function ($socialite) use ($driver, $configService) {
            $config = $configService->resolveConfig($driver);
            if ($config) {
                config(["services.{$driver}" => $config]);
            }
        });
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
