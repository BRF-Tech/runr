<?php

namespace Modules\Tenancy\Models;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\GatewayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Services\TenantSchema;
use Stancl\Tenancy\Contracts\Syncable;

class TenantLocalRegion extends KnativeRegion implements Syncable
{
    protected $table = 'knative_regions';

    /**
     * Default is_synced = true so sync-created records are marked automatically.
     * Tenant-owned regions explicitly set is_synced = false on creation.
     */
    protected $attributes = [
        'is_synced' => true,
        'prometheus_managed' => true,
        'loki_managed' => true,
    ];

    /**
     * Kubeconfig excluded from fillable — prevents sync mass-assignment.
     * Tenant-owned regions set kubeconfig explicitly: $region->kubeconfig = $kc; $region->save();
     */
    protected $fillable = [
        'id',
        'name',
        'total_cpu_m',
        'total_memory_mb',
        // FaaS overcommit factors (TenantMigrations/0052): admission on the copy uses the platform's
        'faas_cpu_overcommit',
        'faas_memory_overcommit',
        'is_active',
        // active | inactive | maintenance (TenantMigrations/0008): tenant deploys and new projects
        // are refused on a region in maintenance or inactive — read from this copy
        'region_status',
        'is_demo',
        'is_synced',
        'region_mode',
        // Cluster / domain migration of the platform region (TenantMigrations/0005), shown on the
        // tenant's region view. migration_old_kubeconfig is a credential and stays central.
        'migration_status',
        'migration_started_at',
        'migration_old_ip',
        'domain_migration_status',
        'domain_migration_started_at',
        'domain_migration_old_domain',
        'knative_status',
        'knative_status_message',
        'knative_status_checked_at',
        'external_ip',
        'cluster_status',
        'cluster_status_message',
        'selected_workers',
        'knative_settings',
        'project_namespace',
        'redis_mode',
        'redis_address',
        'redis_password',
        'knative_redis_status',
        'knative_redis_status_message',
        'knative_operator_status',
        'knative_operator_status_message',
        'knative_operator_namespace',
        'knative_serving_status',
        'knative_serving_status_message',
        'knative_serving_namespace',
        'knative_serving_ingress_class',
        'knative_serving_version',
        'knative_eventing_status',
        'knative_eventing_status_message',
        'knative_eventing_namespace',
        'knative_eventing_version',
        'registry_mode',
        'registry_address',
        'registry_username',
        'registry_password',
        'registry_storage_type',
        'registry_storage_config',
        'registry_status',
        'registry_status_message',
        'registry_namespace',
        'region_demo_status',
        'dns_resolved',
        'prometheus_url',
        'loki_url',
        'loki_status',
        // Observability ownership + Loki tenant ID (TenantMigrations/0023): without them a tenant
        // copy of a platform region reads a stale/missing flag and queries the wrong Loki tenant
        'prometheus_managed',
        'loki_managed',
        'loki_org_id',
        // Function-domain TLS status (TenantMigrations/0024), shown on the tenant's region view
        'function_tls_mode',
        'function_tls_secret_name',
        'function_tls_expires_at',
        // Sandbox install status + runtime per project type: tenant deploys read the RuntimeClass
        // from this copy (without them tenant projects never ran sandboxed)
        'sandbox_kata_status',
        'sandbox_kata_status_message',
        'sandbox_kata_hypervisor',
        'sandbox_gvisor_status',
        'sandbox_gvisor_status_message',
        'sandbox_xaas_runtime',
        'sandbox_faas_runtime',
        'sandbox_caas_runtime',
        'sandbox_build_runtime',
        // WebAssembly support (TenantMigrations/0035): tenant deploys check the region's shims here
        'sandbox_wasm_status',
        'sandbox_wasm_status_message',
        'sandbox_wasm_shims',
        'sandbox_wasm_nodes',
        // GPU inventory / support and the model volume (TenantMigrations/0045): tenant deploys read
        // the GPU RuntimeClass, gVisor nvproxy and the model PVC settings from this copy
        'gpu_mode',
        'gpu_available',
        'gpu_allocated',
        'gpu_status',
        'gpu_status_message',
        'gpu_settings',
        'model_storage',
    ];

    public function getGlobalIdentifierKeyName(): string
    {
        return 'id';
    }

    public function getGlobalIdentifierKey()
    {
        return $this->getKey();
    }

    public function getCentralModelName(): string
    {
        return CentralSyncableRegion::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return array_values(
            array_diff($this->getFillable(), ['kubeconfig', 'is_synced'])
        );
    }

    /**
     * NO-OP: one-way sync only (central → tenant).
     * Tenant-side changes do NOT propagate back to central.
     */
    public function triggerSyncEvent(): void
    {
        // Intentionally empty
    }

    /**
     * Removes the current tenant's copy of platform region $regionId (call in tenant context) the
     * way the central schema drops a region: projects on it stay, without a region
     * (knative_region_id null); its gateways go with their routes; metric snapshots and deployment
     * sessions cascade on region_id.
     *
     * $detached: the assignment ended and the region keeps serving other organizations — the
     * gateways' IngressRoutes / Middlewares are deleted in the region first (GatewayService) and
     * bindings keep their region (their data stays where it is). Otherwise the region itself was
     * deleted: bindings lose it like central ones (nullOnDelete).
     *
     * @return bool whether a copy was removed
     */
    public static function removeCopy(string $regionId, bool $detached = false): bool
    {
        $copy = static::whereKey($regionId)->where('is_synced', true)->first();
        if (! $copy) {
            return false;
        }

        $schema = $copy->getConnection()->getSchemaBuilder();

        if ($schema->hasTable('gateways')) {
            $gateways = Gateway::query()->where('knative_region_id', $regionId)->get();
            foreach ($gateways as $gateway) {
                if ($detached) {
                    app(GatewayService::class)->teardown($gateway);
                }
                GatewayRoute::query()->where('gateway_id', $gateway->id)->delete();
                $gateway->delete();
            }
        }

        Project::query()->where('knative_region_id', $regionId)->update(['knative_region_id' => null]);

        if (! $detached && $schema->hasTable('bindings') && $schema->hasColumn('bindings', 'knative_region_id')) {
            $copy->getConnection()->table('bindings')->where('knative_region_id', $regionId)->update(['knative_region_id' => null]);
        }

        static::withoutEvents(fn () => $copy->delete());

        return true;
    }

    /**
     * Copies of platform region $regionId in every tenant it is assigned to (tenant_regions) — call
     * before the central row and with it the assignments are deleted. A tenant that fails is
     * logged and skipped.
     *
     * @return int copies removed
     */
    public static function removeCopiesOf(string $regionId): int
    {
        $central = (string) config('tenancy.database.central_connection', 'central');
        $tenantIds = DB::connection($central)->table('tenant_regions')
            ->where('knative_region_id', $regionId)
            ->pluck('tenant_id');

        $removed = 0;
        foreach (Tenant::query()->whereIn('id', $tenantIds)->get() as $tenant) {
            /** @var Tenant $tenant */
            try {
                $removed += TenantSchema::inTenant($tenant, fn () => self::removeCopy($regionId)) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::warning('Copy of a deleted platform region not removed', ['tenant' => $tenant->slug, 'region' => $regionId, 'error' => $e->getMessage()]);
            }
        }

        return $removed;
    }
}
