<?php

namespace App\Providers;

use App\Http\Middleware\GroupApiSchemas;
use App\Http\Middleware\NormalizeApiTags;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\ImageSecurityReport;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\Setting;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Observers\CacheInvalidationObserver;
use App\Services\GitDeploy\GitTables;
use App\Services\Projects\QueuedDeploys;
use App\Support\ApiRateLimits;
use App\Support\Auth\LoginTokens;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        $this->registerQueuedDeployHooks();

        ApiRateLimits::register();

        // A sign-in session ends after its lifetime, also one signed in before it had an expiry
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid && ! LoginTokens::outlived($token)
        );

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

    /**
     * Deploys that wait for their version (QueuedDeploys) go on when its build ends and when its
     * supply-chain checks are final. A schema without the table (a tenant schema not migrated
     * yet) is left alone; a failing hook never fails the build or the scan that triggered it.
     */
    private function registerQueuedDeployHooks(): void
    {
        $quietly = function (\Closure $callback): void {
            if (! GitTables::present('queued_deploys')) {
                return;
            }
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::warning('Queued deploy hook failed', ['error' => $e->getMessage()]);
            }
        };

        Version::updated(function (Version $version) use ($quietly) {
            if ($version->wasChanged('build_status')) {
                $quietly(fn () => app(QueuedDeploys::class)->versionBuilt($version));
            }
        });

        ImageSecurityReport::updated(function (ImageSecurityReport $report) use ($quietly) {
            if ($report->wasChanged('status') && in_array($report->status, ['completed', 'failed'], true) && $report->version_id) {
                $quietly(fn () => app(QueuedDeploys::class)->scanFinished((string) $report->version_id));
            }
        });
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
