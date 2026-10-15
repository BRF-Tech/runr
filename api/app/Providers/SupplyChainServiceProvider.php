<?php

namespace App\Providers;

use App\Jobs\RescanDeployedImagesJob;
use App\Models\AiModelService;
use App\Models\ImageSecurityReport;
use App\Models\Project;
use App\Models\RuntimeBuild;
use App\Models\SecurityPolicyOverride;
use App\Models\Version;
use App\Services\SupplyChain\ScannerDbMirrorService;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Software supply chain + data residency (docs/supply-chain.md):
 *  - abilities: security.view, security.override_policy, regions.supply_chain.manage and
 *    tenants.residency.manage are catalog permissions (config/permissions.php, RbacServiceProvider)
 *  - build-ready hooks: a version / runtime image that became ready gets its supply-chain run
 *    (SBOM, scan, signature) — model events, the build services stay untouched
 *  - routes (routes/supply-chain.php) and the schedule (re-scans, scanner DB mirror)
 */
class SupplyChainServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerBuildHooks();
        $this->registerRoutes();
        $this->registerSchedule();
    }

    /**
     * Build ready → supply-chain run. Never lets a supply-chain problem fail a build or a save.
     * Off with supply_chain.enabled = false (RUNR_SUPPLY_CHAIN_ENABLED).
     */
    private function registerBuildHooks(): void
    {
        $guard = function (string $what, callable $fn) {
            if (! config('supply_chain.enabled', true)) {
                return;
            }
            try {
                $fn();
            } catch (\Throwable $e) {
                Log::warning("Supply-chain hook failed: {$what}", ['error' => $e->getMessage()]);
            }
        };

        // Function version built into the region registry
        Version::updated(function (Version $version) use ($guard) {
            if ($version->build_status !== 'ready' || ! $version->build_image
                || ! ($version->wasChanged('build_status') || $version->wasChanged('build_image'))) {
                return;
            }
            $guard('version ready', fn () => app(SupplyChainPipeline::class)->queueForVersion($version));
        });

        // CaaS version: the external image (when the region scans external images)
        Version::created(function (Version $version) use ($guard) {
            $guard('caas version', function () use ($version) {
                if ($version->project?->service_type === 'caas') {
                    app(SupplyChainPipeline::class)->queueForVersion($version);
                }
            });
        });

        // Runtime image built from source in a region
        RuntimeBuild::updated(function (RuntimeBuild $build) use ($guard) {
            if ($build->status === RuntimeBuild::STATUS_READY && $build->wasChanged('status')) {
                $guard('runtime image ready', fn () => app(SupplyChainPipeline::class)->queueForRuntimeBuild($build));
            }
        });

        // Reports follow their subject
        Version::deleted(function (Version $version) {
            self::deleteReports(ImageSecurityReport::query()->where('subject_type', ImageSecurityReport::SUBJECT_VERSION)->where('subject_id', $version->getKey()));
        });
        Project::deleted(function (Project $project) {
            self::deleteReports(ImageSecurityReport::query()->where('project_id', $project->getKey()));
            SecurityPolicyOverride::query()->where('project_id', $project->getKey())->delete();
        });
        AiModelService::deleted(function (AiModelService $service) {
            self::deleteReports(ImageSecurityReport::query()->where('subject_type', ImageSecurityReport::SUBJECT_MODEL_SERVICE)->where('subject_id', $service->getKey()));
        });
        RuntimeBuild::deleted(function (RuntimeBuild $build) {
            self::deleteReports(ImageSecurityReport::onCentral()->where('subject_type', ImageSecurityReport::SUBJECT_RUNTIME_BUILD)->where('subject_id', $build->getKey()));
        });
    }

    private static function deleteReports($query): void
    {
        try {
            $reports = $query->get();
            foreach ($reports as $report) {
                $report->sbomDocuments()->delete();
                $report->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Supply-chain reports not deleted', ['error' => $e->getMessage()]);
        }
    }

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api')
            ->group(base_path('routes/supply-chain.php'));
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new RescanDeployedImagesJob)
                ->hourly()
                ->name('supply-chain-rescan')
                ->withoutOverlapping(60);

            $schedule->call(fn () => app(ScannerDbMirrorService::class)->refreshDue())
                ->hourly()
                ->name('supply-chain-scanner-db-refresh')
                ->withoutOverlapping(60);
        });
    }
}
