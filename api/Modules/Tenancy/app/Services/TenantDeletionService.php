<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Bindings\BindingManager;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Concerns\KnativeAgentTrait;
use App\Services\KubernetesClient;
use App\Services\ProjectCleanupService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;

/**
 * Tears a tenant down (DeleteTenantJob), in this order:
 *
 *  1. every project: Knative Service, CronJob, queue consumer, code ConfigMaps, git/build
 *     directories, registry images (ProjectCleanupService) — in tenant context, each project's
 *     region resolved explicitly: synced platform regions from central (kubeconfig), the
 *     tenant's own regions from its database
 *  2. per region (attached platform regions and tenant-owned ones): the tenant's config-domain
 *     entries, then a sweep of whatever is still labelled runr/tenant=<slug> in the project
 *     namespace (Knative Services, CronJobs, Deployments, ConfigMaps, Jobs, Secrets) plus the projects'
 *     Kaniko build Jobs (labelled runr/project-id)
 *  3. tenant-owned regions only: the runr-agent (it would reconnect forever otherwise)
 *  4. only when nothing was left behind — or $force: drop the tenant database, delete
 *     storage/git/<slug>, delete the tenant row
 *
 * Anything left behind without $force → status delete_failed with the reasons; row and database
 * stay so the delete can be retried.
 */
class TenantDeletionService
{
    use ConfigDomainTrait, KnativeAgentTrait;

    /** Swept by runr/tenant=<slug> in each region's project namespace */
    private const SWEPT_KINDS = [
        ['serving.knative.dev/v1', 'Service'],
        ['batch/v1', 'CronJob'],
        ['apps/v1', 'Deployment'],
        ['v1', 'ConfigMap'],
        ['batch/v1', 'Job'],
        // Secret env vars of the tenant's projects (<slug>-secret-env, KnativeSecretEnvTrait)
        ['v1', 'Secret'],
    ];

    public function __construct(
        private ProjectCleanupService $cleanup,
        private TenantDomainService $domains,
    ) {}

    /**
     * @return array{deleted: bool, failures: list<string>}
     */
    public function delete(Tenant $tenant, bool $force = false): array
    {
        $tenant->forceFill(['status' => Tenant::STATUS_DELETING, 'status_message' => null])->save();

        $failures = [];
        $regions = [];   // id => region with a usable kubeconfig (central model for platform regions)
        $owned = [];   // ids of tenant-owned regions
        $projectRegion = [];   // project id => region id
        $ownBaseDomain = false;

        $tenant->run(function () use (&$failures, &$regions, &$owned, &$projectRegion, &$ownBaseDomain) {
            [$regions, $owned] = $this->tenantRegions();
            // Tenant's own base_domain only (Setting::get() would fall back to the central one)
            $ownBaseDomain = (bool) Setting::query()->whereKey('base_domain')->value('value');

            foreach (Project::query()->get() as $project) {
                $regionId = $project->knative_region_id;
                if ($regionId && isset($regions[$regionId])) {
                    $project->setRelation('knativeRegion', $regions[$regionId]);
                    $projectRegion[$project->id] = $regionId;
                }

                array_push($failures, ...$this->cleanup->cleanup($project));
            }

            // Bindings of the tenant: data and backend resources go now (no retention)
            array_push($failures, ...app(BindingManager::class)->purgeAllNow());
        });

        foreach ($this->attachedRegions($tenant, $regions) as $id => $region) {
            $regions[$id] = $region;
        }

        foreach ($regions as $id => $region) {
            $this->removeTenantDomains($tenant, $region, $ownBaseDomain);

            $projectIds = array_keys(array_filter($projectRegion, fn ($r) => $r === $id));
            array_push($failures, ...$this->sweep($tenant, $region, $projectIds));

            if (in_array($id, $owned, true)) {
                $this->uninstallAgent($region);
            }
        }

        if ($failures && ! $force) {
            $tenant->forceFill([
                'status' => Tenant::STATUS_DELETE_FAILED,
                'status_message' => mb_substr(implode('; ', array_unique($failures)), 0, 2000),
            ])->save();

            Log::error('Tenant deletion incomplete — tenant kept', ['tenant' => $tenant->slug, 'failures' => $failures]);

            return ['deleted' => false, 'failures' => $failures];
        }

        if ($failures) {
            Log::warning('Tenant force-deleted with leftovers', ['tenant' => $tenant->slug, 'failures' => $failures]);
        }

        $tenant->database()->manager()->deleteDatabase($tenant);
        $this->deleteGitRepository($tenant);
        // tenant_users / tenant_regions rows cascade on tenant_id at the DB level
        $tenant->delete();

        return ['deleted' => true, 'failures' => $failures];
    }

    /**
     * Regions in the tenant's database (call in tenant context): synced copies of platform
     * regions resolve to their central row (the copy has no kubeconfig of its own), tenant-owned
     * regions are used as they are. Demo regions run nothing.
     *
     * @return array{0: array<string, KnativeRegion>, 1: list<string>}
     */
    private function tenantRegions(): array
    {
        $regions = [];
        $owned = [];

        foreach (KnativeRegion::query()->get() as $copy) {
            if ($copy->is_demo) {
                continue;
            }

            if ((bool) ($copy->getAttributes()['is_synced'] ?? false)) {
                $central = KnativeRegion::on('central')->find($copy->getKey());
                if ($central && ! $central->is_demo) {
                    $regions[$copy->getKey()] = $central;
                }

                continue;
            }

            $regions[$copy->getKey()] = $copy;
            $owned[] = (string) $copy->getKey();
        }

        return [$regions, $owned];
    }

    /**
     * Platform regions assigned to the tenant (tenant_regions) whose copy is missing from the
     * tenant database — domain entries or labelled leftovers may still be there.
     *
     * @param  array<string, KnativeRegion>  $known
     * @return array<string, KnativeRegion>
     */
    private function attachedRegions(Tenant $tenant, array $known): array
    {
        $extra = [];

        foreach ($tenant->regions()->pluck('knative_regions.id') as $id) {
            if (isset($known[$id])) {
                continue;
            }

            $central = KnativeRegion::on('central')->find($id);
            if ($central && ! $central->is_demo) {
                $extra[$id] = $central;
            }
        }

        return $extra;
    }

    /**
     * The tenant's config-domain entries: its own base domain (TenantDomainService, same as a
     * region detach) and any other entry whose selector targets this tenant (e.g. a previous
     * base domain). Leftover entries select a label nothing carries any more → never blocking.
     */
    private function removeTenantDomains(Tenant $tenant, KnativeRegion $region, bool $ownBaseDomain): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        if ($ownBaseDomain) {
            $this->domains->removeDomainFromRegion($tenant, $region);
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $selector = trim(TenantDomainService::buildTenantSelector($tenant->slug));

            foreach ($this->getConfigDomains($k8s, $servingNs) as $domain => $value) {
                if (trim((string) $value) === $selector) {
                    $this->removeConfigDomain($k8s, $servingNs, (string) $domain);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Tenant deletion: config-domain cleanup failed', [
                'tenant' => $tenant->slug, 'region' => $region->name, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete what is still labelled runr/tenant=<slug> in the region's project namespace, plus
     * the Kaniko build Jobs of the tenant's projects there (labelled runr/project-id only).
     *
     * @param  list<string>  $projectIds  projects of this tenant that ran in this region
     * @return list<string> failures
     */
    private function sweep(Tenant $tenant, KnativeRegion $region, array $projectIds): array
    {
        if (! $region->kubeconfig) {
            return [];
        }

        $ns = $region->project_namespace ?? $region->name;
        $failures = [];
        $targets = [];

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
        } catch (\Throwable $e) {
            return ["{$region->name}: {$e->getMessage()}"];
        }

        $selectors = [[self::SWEPT_KINDS, ['runr/tenant' => $tenant->slug]]];
        foreach ($projectIds as $projectId) {
            $selectors[] = [[['batch/v1', 'Job']], ['runr/project-id' => $projectId]];
        }

        foreach ($selectors as [$kinds, $labels]) {
            foreach ($kinds as [$apiVersion, $kind]) {
                try {
                    foreach ($k8s->listResourcesOrFail($apiVersion, $kind, $ns, $labels) as $item) {
                        if ($name = $item['metadata']['name'] ?? null) {
                            $targets["{$apiVersion}|{$kind}|{$name}"] = [$apiVersion, $kind, $name];
                        }
                    }
                } catch (\Throwable $e) {
                    $failures[] = "{$region->name}: {$e->getMessage()}";
                }
            }
        }

        foreach ($targets as [$apiVersion, $kind, $name]) {
            try {
                // Background: a Job's pods / a CronJob's Jobs go too (plain DELETE orphans them)
                if (! $k8s->deleteResource($apiVersion, $kind, $name, $ns, 'Background')) {
                    $failures[] = "{$region->name}: {$kind}/{$name} could not be deleted";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$region->name}: {$kind}/{$name}: {$e->getMessage()}";
            }
        }

        return $failures;
    }

    /**
     * runr-agent of a tenant-owned region — skipped when a platform region points at the same
     * API server (the agent's ClusterRole/Binding are cluster-wide and shared there).
     */
    private function uninstallAgent(KnativeRegion $region): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        $server = $region->server_url;
        if ($server && KnativeRegion::on('central')->get()->contains(fn (KnativeRegion $r) => $r->server_url === $server)) {
            Log::warning('Tenant deletion: runr-agent kept, a platform region uses the same cluster', ['region' => $region->name]);

            return;
        }

        try {
            $this->removeAgent(new KubernetesClient($region->kubeconfig));
        } catch (\Throwable $e) {
            Log::warning('Tenant deletion: runr-agent removal failed', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * storage/git/<slug> — the tenant's git repository (TenantAwareGitService::repoPath()).
     */
    private function deleteGitRepository(Tenant $tenant): void
    {
        $slug = (string) $tenant->slug;
        if (preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
            return;
        }

        $base = realpath(storage_path('git'));
        $path = $base ? realpath($base.DIRECTORY_SEPARATOR.$slug) : false;

        if ($path && is_dir($path) && dirname($path) === $base) {
            File::deleteDirectory($path);
        }
    }
}
