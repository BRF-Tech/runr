<?php

namespace App\Services\RegionMigrate;

use App\Models\EnvironmentSecret;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectSecret;
use App\Services\Environments\EnvironmentSecrets;
use App\Services\Secrets\SecretBackendException;
use App\Services\Secrets\SecretBackends;
use App\Services\Secrets\SecretScope;
use Illuminate\Support\Facades\Log;

/**
 * The values of the region's project secrets follow a region move (docs/security.md): RunR keeps
 * no copy of them, the cluster the region leaves has the only one — in the project's values
 * Secret (<slug>-secret-env) or in the cluster's OpenBao. A move copies them from the cluster it
 * leaves to the new one (its OpenBao is installed first, RegionMigrateService::
 * reapplyRegionFeatures()) before the projects are deployed there; a rollback copies them back —
 * values set while the region ran on the new cluster included — before that cluster's workloads
 * are removed (LeftClusterCleanup). In memory only: a value is read from one cluster and written to
 * the other through the same backend, never logged or stored.
 *
 * A project whose values cannot be copied (the cluster does not answer, its Secret or OpenBao is
 * gone) is named in the log; its deploy on the target cluster then fails with the names of the
 * missing values (SecretBackend::prepareDeploy()) — set them again and deploy.
 *
 * The environments that run in the region keep their own values in their workload's Secret
 * (<workload>-secret-env, docs/environments.md#secrets): those are copied the same way.
 *
 * References to secret stores (ExternalSecret) need no copy: the deploy applies them on the new
 * cluster, whose External Secrets Operator reads the store again. RunR's own keys (workflow
 * signing key, MCP gateway key) are written by the deploy.
 */
class RegionSecretValues
{
    /**
     * Copy the values of the region's projects (in the current database context) from one cluster
     * to the other.
     *
     * @return array{copied: list<string>, failed: list<string>} project slugs; failed with the reason
     */
    public function copy(KnativeRegion $region, string $fromKubeconfig, string $toKubeconfig): array
    {
        $copied = [];
        $failed = [];
        $backends = app(SecretBackends::class);
        $from = (clone $region)->forceFill(['kubeconfig' => $fromKubeconfig]);
        $to = (clone $region)->forceFill(['kubeconfig' => $toKubeconfig]);

        $projects = Project::query()
            ->where('knative_region_id', $region->getKey())
            ->whereIn('service_type', Project::SERVICE_TYPES)
            ->with('secrets')
            ->get();

        foreach ($projects as $project) {
            $rows = $project->secrets->filter(fn (ProjectSecret $s) => ! $s->isExternal() && ! $s->isHeldInDatabase());
            if ($rows->isEmpty()) {
                continue;
            }

            $source = SecretScope::forProject($project, $from);
            $target = SecretScope::forProject($project, $to);
            $missing = [];
            try {
                foreach ($backends->partition($rows) as $kind => $part) {
                    if ($part->isEmpty()) {
                        continue;
                    }
                    $names = $part->pluck('name')->map(fn ($n) => (string) $n)->all();
                    $values = $backends->for($kind)->readValues($source, $names);
                    array_push($missing, ...array_values(array_diff($names, array_keys($values))));
                    if ($values !== []) {
                        $backends->for($kind)->write($target, $values);
                    }
                    unset($values);
                }
                if ($missing !== []) {
                    $failed[] = "{$project->slug}: not on the cluster it leaves: ".implode(', ', $missing);
                } else {
                    $copied[] = (string) $project->slug;
                }
            } catch (SecretBackendException $e) {
                $failed[] = "{$project->slug}: {$e->getMessage()}";
            }
        }

        // The environments' own values (<workload>-secret-env) of the environments that run in the
        // region — their own region, or their project's
        $this->copyEnvironments($region, $from, $to, $copied, $failed);

        if ($failed !== []) {
            Log::warning("RegionMigrate: secret values of {$region->name} not copied for every project; their deploys name the missing values", ['failed' => $failed]);
        }
        if ($copied !== []) {
            Log::info("RegionMigrate: secret values of {$region->name} copied", ['projects' => $copied]);
        }

        return ['copied' => $copied, 'failed' => $failed];
    }

    /**
     * @param  list<string>  $copied
     * @param  list<string>  $failed
     */
    private function copyEnvironments(KnativeRegion $region, KnativeRegion $from, KnativeRegion $to, array &$copied, array &$failed): void
    {
        if (! ProjectEnvironment::tablesReady()) {
            return;
        }

        $withSecrets = EnvironmentSecret::query()->distinct()->pluck('environment_id')->all();
        if ($withSecrets === []) {
            return;
        }

        $environments = ProjectEnvironment::query()->whereKey($withSecrets)->where('is_primary', false)->with('project')->get();
        foreach ($environments as $environment) {
            $project = $environment->project;
            if ((string) ($environment->regionId() ?? '') !== (string) $region->getKey()) {
                continue;
            }
            try {
                app(EnvironmentSecrets::class)->copy($project, $environment, $from, $to);
                $copied[] = (string) $environment->workload_name;
            } catch (SecretBackendException $e) {
                $failed[] = "{$environment->workload_name}: {$e->getMessage()}";
            }
        }
    }
}
