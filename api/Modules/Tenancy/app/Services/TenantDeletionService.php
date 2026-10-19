<?php

namespace Modules\Tenancy\Services;

use App\Mcp\Hosting\HostedServerIndex;
use App\Models\Branding;
use App\Models\ImageUpload;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Sandbox;
use App\Models\Setting;
use App\Models\UploadedImage;
use App\Services\Bindings\BindingManager;
use App\Services\Concerns\ConfigDomainTrait;
use App\Services\Concerns\KnativeAgentTrait;
use App\Services\Images\ImageUploadService;
use App\Services\Images\UploadedImages;
use App\Services\KubernetesClient;
use App\Services\ProjectCleanupService;
use App\Services\RegistryImageLifecycleService;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\Sandboxes\SandboxService;
use App\Services\WhiteLabel\BrandingService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Services\BillArchive;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantCentralTables;
use Modules\Tenancy\Support\TenantTokens;
use Modules\Ticket\Support\TicketAttachments;

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
 *  4. what the organization holds in central tables: the pods of its sandboxes and warm pools
 *     (SandboxService::terminate), the receivers of uploads in progress, its uploaded images with
 *     their registry manifests (UploadedImages::delete), the registry images of its custom runtimes
 *  5. only when nothing was left behind — or $force: copy the organization's project and account
 *     bills into the central archive (BillArchive; when that fails nothing is dropped, $force or
 *     not: bills are financial records), then drop the tenant database, delete
 *     storage/git/<slug>, the support ticket attachments and the branding assets, the
 *     organization's central rows (TenantCentralTables::deleteTenantRows — sandboxes, pools,
 *     quota override, git installations / repository links, MCP servers / calls / sessions,
 *     uploads, status page routes, custom runtimes, the settings of its own regions, security
 *     policy, branding override, notifications) and the tenant row with the API tokens created in
 *     it. The audit trail and billing records stay (TenantCentralTables::RETAINED).
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
        $ownRegionIds = [];   // every region of the tenant's own (demo ones too): central settings keyed by them go

        $tenant->run(function () use (&$failures, &$regions, &$owned, &$projectRegion, &$ownBaseDomain, &$ownRegionIds) {
            [$regions, $owned] = $this->tenantRegions();
            $ownRegionIds = KnativeRegion::where('is_synced', false)->pluck('id')->map(fn ($id) => (string) $id)->all();
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

        // What the organization runs or stores through central tables (sandbox pods, upload
        // receivers, uploaded images, custom runtime images)
        array_push($failures, ...$this->teardownCentralResources($tenant));

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

        // The bills the organization made for its projects and users live in its schema: copied to
        // the central archive before the schema goes, or the tenant is kept
        if (($archiveFailure = $this->archiveBills($tenant)) !== null) {
            $failures[] = $archiveFailure;
            $tenant->forceFill([
                'status' => Tenant::STATUS_DELETE_FAILED,
                'status_message' => mb_substr(implode('; ', array_unique($failures)), 0, 2000),
            ])->save();

            return ['deleted' => false, 'failures' => $failures];
        }

        $this->endFixedAllocations($tenant);
        $tenant->database()->manager()->deleteDatabase($tenant);
        $this->deleteGitRepository($tenant);
        $this->deleteStoredFiles($tenant);
        $this->deleteCentralRows($tenant, $ownRegionIds);
        // With the API tokens created in the tenant; tenant_users / tenant_regions rows cascade on
        // tenant_id at the DB level
        TenantTokens::deleteTenant($tenant);

        return ['deleted' => true, 'failures' => $failures];
    }

    /**
     * The organization's bills into the central archive (Billing module, BillArchive). Returns the
     * failure, null when they are archived or the schema has none.
     */
    private function archiveBills(Tenant $tenant): ?string
    {
        try {
            app(BillArchive::class)->archiveTenant($tenant);
        } catch (\Throwable $e) {
            Log::error('Tenant deletion: the bills could not be archived — tenant kept', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);

            return 'bills not archived: '.$e->getMessage();
        }

        return null;
    }

    /**
     * Billing (docs/billing.md#fixed-resources): the tenant's fixed allocations end today — in its
     * schema and their central copies, which the platform bills the tenant for (billing_fixed_requests
     * stays, TenantCentralTables::RETAINED). Whether billing is on or not, with the tables only.
     */
    private function endFixedAllocations(Tenant $tenant): void
    {
        $today = now()->toDateString();
        $end = function (Builder $query) use ($today): void {
            $query->where('status', 'approved')->whereNull('active_until')
                ->update(['status' => 'ended', 'active_until' => $today, 'updated_at' => now()]);
        };

        try {
            $tenant->run(function () use ($end) {
                if (Schema::hasTable('billing_fixed_requests')) {
                    $end(DB::table('billing_fixed_requests'));
                    DB::table('billing_fixed_requests')->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => now(), 'updated_at' => now()]);
                }
            });
            if (TenantCentralTables::has('central', 'billing_fixed_requests', 'tenant_id')) {
                $end(DB::connection('central')->table('billing_fixed_requests')->where('tenant_id', (string) $tenant->getTenantKey()));
            }
        } catch (\Throwable $e) {
            // The close job ends allocations of tenants that are gone (FixedRequests::endOrphanedCopies)
            Log::warning('Tenant deletion: fixed allocations not ended', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
        }
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

    /**
     * What the organization runs or stores in the regions through central tables:
     *  - its sandboxes, warm pool pods included: pod, egress policy and env Secret
     *    (SandboxService::terminate resolves the region in the organization's context)
     *  - uploads in progress: their receiver Job and Service (ImageUploadService::abort)
     *  - its uploaded images: record and registry manifest (UploadedImages::delete)
     *  - the images of its custom runtimes in the registries of the regions they were built in
     * A pod or manifest that could not be deleted is a failure (the tenant is kept for a retry).
     *
     * @return list<string> failures
     */
    private function teardownCentralResources(Tenant $tenant): array
    {
        $tenantId = (string) $tenant->getTenantKey();
        $central = self::centralConnection();
        $failures = [];

        if (TenantCentralTables::has($central, 'sandboxes', 'tenant_id')) {
            $sandboxes = app(SandboxService::class);
            foreach (Sandbox::query()->where('tenant_id', $tenantId)->whereNotIn('status', Sandbox::ENDED_STATUSES)->get() as $sandbox) {
                try {
                    $sandbox = $sandboxes->terminate($sandbox);
                } catch (\Throwable $e) {
                    $failures[] = "sandbox {$sandbox->id}: {$e->getMessage()}";

                    continue;
                }
                if (! $sandbox->isEnded()) {
                    $failures[] = "sandbox {$sandbox->id}: pod {$sandbox->pod_name} could not be deleted";
                }
            }
        }

        // Central rows read here; their regions are resolved in the organization's context below
        $openUploads = TenantCentralTables::has($central, 'image_uploads', 'tenant_id')
            ? ImageUpload::query()->where('tenant_id', $tenantId)->whereIn('status', ImageUpload::OPEN)->get()->all()
            : [];
        $images = TenantCentralTables::has($central, 'uploaded_images', 'tenant_id')
            ? UploadedImage::query()->where('tenant_id', $tenantId)->get()->all()
            : [];
        $runtimeRegions = [];   // custom runtime id => regions it was built in
        if (TenantCentralTables::has($central, 'runtimes', 'tenant_id') && TenantCentralTables::has($central, 'runtime_builds', 'runtime_id')) {
            $db = DB::connection($central);
            foreach ($db->table('runtimes')->where('tenant_id', $tenantId)->pluck('id') as $runtimeId) {
                $regionIds = $db->table('runtime_builds')->where('runtime_id', $runtimeId)
                    ->whereNotNull('image')->distinct()->pluck('knative_region_id')->map(fn ($id) => (string) $id)->all();
                if ($regionIds !== []) {
                    $runtimeRegions[(string) $runtimeId] = $regionIds;
                }
            }
        }

        if ($openUploads === [] && $images === [] && $runtimeRegions === []) {
            return $failures;
        }

        // Regions as the organization sees them: its own regions exist in its schema only
        TenantSchema::inTenant($tenant, function () use ($openUploads, $images, $runtimeRegions, &$failures) {
            $uploads = app(ImageUploadService::class);
            foreach ($openUploads as $upload) {
                try {
                    $uploads->abort($upload);
                } catch (\Throwable $e) {
                    $failures[] = "image upload {$upload->id}: {$e->getMessage()}";
                }
            }

            $uploaded = app(UploadedImages::class);
            foreach ($images as $image) {
                $region = KnativeRegion::find($image->knative_region_id);
                try {
                    $removed = $uploaded->delete($image);
                } catch (\Throwable $e) {
                    $failures[] = "uploaded image {$image->repository}:{$image->tag}: {$e->getMessage()}";

                    continue;
                }
                // Only an internal registry holds the manifest (UploadedImages::removeManifest)
                if (! $removed && $region && $region->registry_mode === 'internal' && ! $region->is_demo) {
                    $failures[] = "uploaded image {$image->repository}:{$image->tag}: manifest not removed from the registry of {$region->name}";
                }
            }

            $registry = app(RegistryImageLifecycleService::class);
            foreach ($runtimeRegions as $runtimeId => $regionIds) {
                foreach (KnativeRegion::whereIn('id', $regionIds)->get() as $region) {
                    try {
                        $registry->deleteRuntimeImages($region, $runtimeId);
                    } catch (\Throwable $e) {
                        $failures[] = "runtime {$runtimeId}: images not removed from the registry of {$region->name}: {$e->getMessage()}";
                    }
                }
            }
        });

        return $failures;
    }

    /**
     * Files the organization stored outside its git repository: its branding assets
     * (BrandingService) and support ticket attachments (Ticket module).
     */
    private function deleteStoredFiles(Tenant $tenant): void
    {
        $tenantId = (string) $tenant->getTenantKey();

        try {
            $branding = app(BrandingService::class);
            foreach (array_keys(Branding::ASSETS) as $asset) {
                $branding->deleteAsset($asset, $tenantId);
            }
        } catch (\Throwable $e) {
            Log::warning('Tenant deletion: branding assets not removed', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
        }

        // support-attachments/<tenant id>/ (nothing there when the Ticket module is off)
        try {
            TicketAttachments::deleteTenantFiles($tenantId);
        } catch (\Throwable $e) {
            Log::warning('Tenant deletion: ticket attachments not removed', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The organization's rows in the central tables (TenantCentralTables::deleteTenantRows), then
     * the in-process caches that hold some of them.
     *
     * @param  list<string>  $ownRegionIds
     */
    private function deleteCentralRows(Tenant $tenant, array $ownRegionIds): void
    {
        $deleted = TenantCentralTables::deleteTenantRows(self::centralConnection(), (string) $tenant->getTenantKey(), $ownRegionIds);

        if (app()->resolved(HostedServerIndex::class)) {
            app(HostedServerIndex::class)->forget();
        }
        if (app()->resolved(RuntimeRegistry::class)) {
            app(RuntimeRegistry::class)->forget();
        }

        if ($deleted !== []) {
            Log::info('Tenant deletion: central rows removed', ['tenant' => $tenant->slug, 'rows' => $deleted]);
        }
    }

    /** The central connection (the default one where the Tenancy module registered none). */
    private static function centralConnection(): string
    {
        $central = (string) config('tenancy.database.central_connection', 'central');

        return config("database.connections.{$central}") ? $central : (string) config('database.default');
    }
}
