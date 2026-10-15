<?php

namespace Modules\Tenancy\Models;

use App\Models\KnativeRegion;
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
        'is_active',
        'is_demo',
        'is_synced',
        'region_mode',
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
}
