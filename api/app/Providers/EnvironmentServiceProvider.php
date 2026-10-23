<?php

namespace App\Providers;

use App\Models\Deployment;
use App\Models\Version;
use App\Services\Environments\EnvironmentReservations;
use App\Services\Environments\Promotions;
use App\Services\Environments\RegionImages;
use App\Services\RegionMigrateService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * Project environments and promotion (docs/environments.md): the API routes
 * (routes/environments.php), promotions following their deployment, and the environments' share
 * of the organization quota (Tenancy module). Deploying, naming and config layering live in
 * App\Services\Environments; the deploy path picks an environment up from the deployment
 * (DeployTarget).
 */
class EnvironmentServiceProvider extends ServiceProvider
{
    private static bool $quotaHooked = false;

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/environments.php'));

        // A promotion follows its deployment: deployed when it is active, failed when it is not
        Deployment::updated(function (Deployment $deployment) {
            if (! $deployment->wasChanged('status') || ! in_array($deployment->status, ['active', 'failed', 'shutting_down', 'shut_down'], true)) {
                return;
            }
            try {
                app(Promotions::class)->deploymentUpdated($deployment);
            } catch (\Throwable $e) {
                Log::warning('Promotion status not updated', ['deployment' => $deployment->id, 'error' => $e->getMessage()]);
            }
            // An environment the region move deployed again whose new deployment failed: the one
            // still running on the cluster the region left ends too
            if ($deployment->status === 'failed' && ! $deployment->isPrimary()) {
                try {
                    RegionMigrateService::environmentDeploymentFailed($deployment);
                } catch (\Throwable $e) {
                    Log::warning('Environment move failure not recorded', ['deployment' => $deployment->id, 'error' => $e->getMessage()]);
                }
            }
        });

        // Environment deployments that wait for their version's own build (built again into their
        // region's registry) go on when it ends; a pruned version's builds in other regions go
        Version::updated(function (Version $version) {
            if (! $version->wasChanged('build_status')) {
                return;
            }
            try {
                $images = app(RegionImages::class);
                $version->build_status === Version::STATUS_PRUNED ? $images->versionPruned($version) : $images->versionBuilt($version);
            } catch (\Throwable $e) {
                Log::warning('Environment deploys of the version not resumed', ['version' => $version->id, 'error' => $e->getMessage()]);
            }
        });

        $this->registerQuotaContribution();
    }

    /**
     * Environments count against the organization quota of the region they run in (Tenancy
     * module): each one reserves its size like a project, from its creation on.
     */
    private function registerQuotaContribution(): void
    {
        if (self::$quotaHooked || ! class_exists(TenantQuotaService::class)) {
            return;
        }
        self::$quotaHooked = true; // a static registry: once per process (Octane workers, test runs)

        TenantQuotaService::addUsageContributor(fn ($region, ?string $excludeProjectId) => EnvironmentReservations::demandIn((string) $region->id));
    }
}
