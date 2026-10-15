<?php

namespace App\Providers;

use App\Http\Middleware\GroupApiSchemas;
use App\Http\Middleware\NormalizeApiTags;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Setting;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Observers\CacheInvalidationObserver;
use App\Support\ApiRateLimits;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }
    }

    public function boot(): void
    {
        // Listeners in app/Listeners are registered by Laravel's event discovery (handle() type
        // hints). Registering them here again ran every deploy twice (two Knative revisions).

        $this->registerCacheObservers();

        ApiRateLimits::register();

        if (class_exists(Scramble::class)) {
            // Route filter (a closure cannot be serialized in config, so it is defined here)
            Scramble::routes(
                fn (Route $route) => str_starts_with($route->uri, 'api/')
                    && ! str_starts_with($route->uri, 'api/internal/')
            );

            Scramble::registerUiRoute('api/docs')
                ->middleware(['api']);

            Scramble::registerJsonSpecificationRoute('api/docs.json')
                ->middleware(['api', GroupApiSchemas::class]);

            Scramble::afterOpenApiGenerated(function (
                OpenApi $openApi
            ) {
                $openApi->secure(
                    SecurityScheme::http('bearer', 'bearer')
                );

                $perOperationSecurity = $openApi->security;
                $openApi->security = [];

                foreach ($openApi->paths as $path) {
                    foreach ($path->operations as $operation) {
                        if ($operation->security === null) {
                            $operation->security = $perOperationSecurity;
                        }
                        NormalizeApiTags::normalizeOperationTags($operation);
                    }
                }
            });
        }
    }

    private function registerCacheObservers(): void
    {
        $models = [
            Project::class,
            Version::class,
            Deployment::class,
            KnativeRegion::class,
            Setting::class,
            ExecutorImage::class,
            XaasConfig::class,
            FaasConfig::class,
            ContainerConfig::class,
            ProjectDomain::class,
        ];

        foreach ($models as $model) {
            $model::observe(CacheInvalidationObserver::class);
        }
    }
}
