<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Services\Bindings\DynamicDbCredentials;
use App\Services\GitDeploy\TenantContext;
use App\Services\Secrets\External\ExternalSecretsInstallService;
use App\Services\Secrets\External\ExternalSecretStatusSync;
use Illuminate\Console\Command;

/**
 * Scheduled (every two minutes, routes/console.php): what External Secrets Operator reports in the
 * regions, onto the project secrets that are references and onto the organizations' secret stores
 * (ExternalSecretStatusSync) — in the central schema and in every tenant schema. Also moves an
 * operator install from "installing" to "ready" once it runs (ExternalSecretsInstallService), and
 * rolls workloads onto renewed dynamic Postgres users (DynamicDbCredentials::renewAll()).
 */
class SyncSecretStatusCommand extends Command
{
    protected $signature = 'secrets:sync-status';

    protected $description = 'Read the sync status of external secret references and secret stores from the regions';

    public function handle(ExternalSecretStatusSync $sync, ExternalSecretsInstallService $installer): int
    {
        foreach (RegionSecretSetting::query()->where('eso_status', RegionSecretSetting::ESO_INSTALLING)->get() as $settings) {
            try {
                // A tenant-owned region lives in its tenant's schema
                TenantContext::run($settings->owner_tenant_id, function () use ($settings, $installer) {
                    $region = KnativeRegion::query()->find($settings->knative_region_id);
                    if ($region) {
                        $installer->refreshStatus($region);
                    }
                });
            } catch (\Throwable $e) {
                $this->warn("region {$settings->knative_region_id}: {$e->getMessage()}");
            }
        }

        $failed = [];
        $dynamic = app(DynamicDbCredentials::class);
        $run = function () use ($sync, $dynamic, &$failed) {
            array_push($failed, ...$sync->syncAll()['failed']);
            // Renewed dynamic Postgres users reach the running workloads (a new revision)
            array_push($failed, ...$dynamic->renewAll());
        };

        $run();
        if (TenantContext::enabled()) {
            TenantContext::each(fn () => $run());
        }

        foreach ($failed as $line) {
            $this->warn($line);
        }

        return self::SUCCESS;
    }
}
