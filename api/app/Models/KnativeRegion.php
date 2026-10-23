<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Models\Concerns\KnativeRegionAccessorsTrait;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Tenancy\Models\Tenant;

/**
 * @property string $id
 * @property string $name
 * @property string|null $kubeconfig
 * @property int $total_cpu_m
 * @property int $total_memory_mb
 * @property float $faas_cpu_overcommit
 * @property float $faas_memory_overcommit
 * @property bool $is_active
 * @property bool $is_demo
 * @property bool $is_synced
 * @property string|null $owner_tenant_id
 * @property string $region_mode
 * @property string $knative_status
 * @property string|null $knative_status_message
 * @property Carbon|null $knative_status_checked_at
 * @property array<array-key, mixed>|null $selected_workers
 * @property array<array-key, mixed>|null $knative_settings
 * @property string $cluster_status
 * @property string|null $cluster_status_message
 * @property string|null $external_ip
 * @property string $knative_operator_status
 * @property string|null $knative_operator_status_message
 * @property string $knative_operator_namespace
 * @property string $knative_serving_status
 * @property string|null $knative_serving_status_message
 * @property string $knative_serving_namespace
 * @property string $knative_serving_ingress_class
 * @property string|null $knative_serving_version
 * @property string $knative_eventing_status
 * @property string|null $knative_eventing_status_message
 * @property string $knative_eventing_namespace
 * @property string|null $knative_eventing_version
 * @property string|null $redis_mode
 * @property string|null $redis_address
 * @property string|null $redis_password
 * @property string|null $knative_redis_status
 * @property string|null $knative_redis_status_message
 * @property string|null $registry_mode
 * @property string|null $registry_address
 * @property string|null $registry_username
 * @property string|null $registry_password
 * @property string|null $registry_storage_type
 * @property array<array-key, mixed>|null $registry_storage_config
 * @property string|null $registry_status
 * @property string|null $registry_status_message
 * @property string $registry_namespace
 * @property string $project_namespace
 * @property string|null $prometheus_url
 * @property bool $prometheus_managed
 * @property string|null $loki_url
 * @property string|null $loki_status
 * @property bool $loki_managed
 * @property string|null $loki_org_id
 * @property string|null $agent_token_hash
 * @property string|null $region_demo_status
 * @property bool|null $dns_resolved
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $migration_status
 * @property Carbon|null $migration_started_at
 * @property string|null $migration_old_ip
 * @property string|null $migration_old_kubeconfig
 * @property array<string, mixed>|null $migration_old_state
 * @property string|null $domain_migration_status
 * @property Carbon|null $domain_migration_started_at
 * @property string|null $domain_migration_old_domain
 * @property string|null $function_tls_mode
 * @property string|null $function_tls_secret_name
 * @property Carbon|null $function_tls_expires_at
 * @property string $sandbox_kata_status
 * @property string|null $sandbox_kata_status_message
 * @property array<array-key, mixed>|null $sandbox_kata_hypervisor
 * @property int|null $sandbox_kata_devmapper_pool_gb Firecracker's devmapper thin-pool size in GiB, as set up on the nodes
 * @property string $sandbox_gvisor_status
 * @property string|null $sandbox_gvisor_status_message
 * @property string $sandbox_xaas_runtime
 * @property string $sandbox_faas_runtime
 * @property string $sandbox_caas_runtime
 * @property string $sandbox_build_runtime
 * @property string $sandbox_wasm_status
 * @property string|null $sandbox_wasm_status_message
 * @property array<array-key, mixed>|null $sandbox_wasm_shims
 * @property array<array-key, mixed>|null $sandbox_wasm_nodes
 * @property string $cert_manager_status
 * @property string|null $cert_manager_status_message
 * @property bool|null $cert_manager_managed
 * @property string|null $cert_manager_version
 * @property string|null $cert_manager_namespace
 * @property string|null $cert_manager_issuer
 * @property string $region_status
 * @property-read array $effective_settings
 * @property-read bool $has_redis
 * @property-read bool $has_registry
 * @property-read bool $has_sandbox_gvisor
 * @property-read bool $has_sandbox_kata
 * @property-read bool $has_serving
 * @property-read bool $is_domain_migrating
 * @property-read bool $is_inactive
 * @property-read bool $is_maintenance
 * @property-read bool $is_migrating
 * @property-read string|null $server_url
 * @property-read Tenant|null $ownerTenant
 * @property-read Collection<int, Project> $projects
 * @property-read int|null $projects_count
 *
 * @method static Builder<static>|KnativeRegion demo()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion query()
 * @method static Builder<static>|KnativeRegion real()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereClusterStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereClusterStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereDnsResolved($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereDomainMigrationOldDomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereDomainMigrationStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereDomainMigrationStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereExternalIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereIsDemo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereIsSynced($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeEventingNamespace($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeEventingStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeEventingStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeEventingVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeOperatorNamespace($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeOperatorStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeOperatorStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeRedisStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeRedisStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeServingIngressClass($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeServingNamespace($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeServingStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeServingStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeServingVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeStatusCheckedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKnativeStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereKubeconfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereLokiManaged($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereLokiStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereLokiUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereMigrationOldIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereMigrationOldKubeconfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereMigrationStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereMigrationStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereOwnerTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereProjectNamespace($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion wherePrometheusManaged($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion wherePrometheusUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRedisAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRedisMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRedisPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegionDemoStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegionMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegionStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryNamespace($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryStorageConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryStorageType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereRegistryUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxBuildRuntime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxCaasRuntime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxFaasRuntime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxGvisorStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxGvisorStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxKataHypervisor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxKataStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxKataStatusMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSandboxXaasRuntime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereSelectedWorkers($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereTotalCpuM($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereTotalMemoryMb($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|KnativeRegion whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class KnativeRegion extends Model
{
    use HasUlids, KnativeRegionAccessorsTrait;

    private const DEFAULT_SETTINGS = [
        'request_timeout' => 300,
    ];

    /**
     * RunR installs and manages a region's Prometheus/Loki unless the operator supplied their
     * own at creation. Same default as the DB columns, so a model that was never read back (or a
     * row from a schema without the columns) still counts as managed.
     */
    protected $attributes = [
        'prometheus_managed' => true,
        'loki_managed' => true,
    ];

    protected $fillable = [
        'name',
        'kubeconfig',
        'total_cpu_m',
        'total_memory_mb',
        // FaaS overcommit factors of the region capacity (App\Services\Helpers\RegionCapacity)
        'faas_cpu_overcommit',
        'faas_memory_overcommit',
        'is_active',
        'region_status',
        'is_demo',
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
        // Component statuses
        'knative_operator_status',
        'knative_operator_status_message',
        'knative_operator_namespace',
        'knative_serving_status',
        'knative_serving_status_message',
        'knative_serving_namespace',
        'knative_serving_ingress_class',
        'knative_serving_version',
        'knative_eventing_status',         // legacy — kept for DB compat
        'knative_eventing_status_message', // legacy
        'knative_eventing_namespace',      // legacy
        'knative_eventing_version',        // legacy
        // Registry
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
        'prometheus_managed',
        'loki_url',
        'loki_status',
        'loki_managed',
        'loki_org_id',
        // Sandbox (Runtime Isolation)
        'sandbox_kata_status',
        'sandbox_kata_status_message',
        'sandbox_kata_hypervisor',
        // Firecracker's devmapper thin-pool size (GiB), as SandboxInstallService set it up
        'sandbox_kata_devmapper_pool_gb',
        'sandbox_gvisor_status',
        'sandbox_gvisor_status_message',
        'sandbox_xaas_runtime',
        'sandbox_faas_runtime',
        'sandbox_caas_runtime',
        'sandbox_build_runtime',
        // WebAssembly support (runwasi shims, App\Services\Wasm\WasmInstallService)
        'sandbox_wasm_status',
        'sandbox_wasm_status_message',
        'sandbox_wasm_shims',
        'sandbox_wasm_nodes',
        // cert-manager and the ClusterIssuer of custom domains (App\Services\CertManager\CertManagerService)
        'cert_manager_status',
        'cert_manager_status_message',
        'cert_manager_managed',
        'cert_manager_version',
        'cert_manager_namespace',
        'cert_manager_issuer',
        // GPU
        'gpu_mode',
        'gpu_available',
        'gpu_allocated',
        'gpu_status',
        'gpu_status_message',
        'gpu_settings',
        // Model volume (PVC) of the Hugging Face model cache and offline snapshots
        'model_storage',
        // Migration
        'migration_status',
        'migration_started_at',
        'migration_old_ip',
        'migration_old_kubeconfig',
        'domain_migration_status',
        'domain_migration_started_at',
        'domain_migration_old_domain',
        // Function-domain TLS (region's own Traefik, separate from the console's Ingress)
        'function_tls_mode',
        'function_tls_secret_name',
        'function_tls_expires_at',
    ];

    // Secrets never appear in API responses (registry_storage_config holds S3 credentials)
    // agent_token_hash is not fillable: only the agent deploy writes it (forceFill); neither is
    // migration_old_state, the pre-move state only RegionMigrateService writes (forceFill)
    protected $hidden = ['kubeconfig', 'redis_password', 'registry_password', 'registry_storage_config', 'migration_old_kubeconfig', 'migration_old_state', 'agent_token_hash'];

    // Secret columns are encrypted at rest (App\Casts\Encrypted, docs/security.md)
    protected $casts = [
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'total_cpu_m' => 'integer',
        'total_memory_mb' => 'integer',
        'faas_cpu_overcommit' => 'float',
        'faas_memory_overcommit' => 'float',
        'selected_workers' => 'array',
        'knative_settings' => 'array',
        'knative_status_checked_at' => 'datetime',
        'kubeconfig' => Encrypted::class,
        'migration_old_kubeconfig' => Encrypted::class,
        'redis_password' => Encrypted::class,
        'registry_password' => Encrypted::class,
        'registry_storage_config' => Encrypted::class.':array',
        'dns_resolved' => 'boolean',
        'prometheus_managed' => 'boolean',
        'loki_managed' => 'boolean',
        'sandbox_kata_hypervisor' => 'array',
        'sandbox_kata_devmapper_pool_gb' => 'integer',
        'sandbox_wasm_shims' => 'array',
        'sandbox_wasm_nodes' => 'array',
        'cert_manager_managed' => 'boolean',
        'gpu_available' => 'array',
        'gpu_allocated' => 'array',
        'gpu_settings' => 'array',
        'model_storage' => 'array',
        'migration_started_at' => 'datetime',
        'migration_old_state' => 'array',
        'domain_migration_started_at' => 'datetime',
        'function_tls_expires_at' => 'datetime',
    ];

    /**
     * Query on the container-bound region model. With the Tenancy module active that is
     * TenantAwareKnativeRegion, whose saved() hook pushes every change of a platform region to
     * the tenants' copies (central → tenant sync). Console commands and jobs that write region
     * fields tenants read (prometheus_url, loki_url, …) load regions through this —
     * KnativeRegion::where() would update the central row only and leave the copies stale.
     */
    public static function syncedQuery(): Builder
    {
        return app(self::class)->newQuery();
    }

    /**
     * The table of the container-bound region model has a region_mode column — the Tenancy
     * module adds it (central and tenant schemas); a single-tenant install has none.
     */
    public static function hasRegionMode(): bool
    {
        $model = app(self::class);

        try {
            return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'region_mode');
        } catch (\Throwable) {
            return false;
        }
    }

    public function scopeReal($query)
    {
        return $query->where('is_demo', false);
    }

    public function scopeDemo($query)
    {
        return $query->where('is_demo', true);
    }

    public function projects(): HasMany
    {
        // Explicit key: the tenant copy (TenantAwareKnativeRegion) would otherwise look for tenant_aware_knative_region_id
        return $this->hasMany(Project::class, 'knative_region_id');
    }
}
