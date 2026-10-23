<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\RegionGlobalDns;
use App\Models\Setting;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\CertManager\CertManagerService;
use App\Services\Concerns\ResourceComputationTrait;
use App\Services\GlobalDns\GlobalDnsZone;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\RegionDomains;
use App\Services\Helpers\ResourceReservation;
use App\Services\RegionReconcile\ReconcileRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnativeRegionResource extends JsonResource
{
    use ResourceComputationTrait;

    /**
     * One shape (the API document types it as one object): the fields every region has, then the
     * rest for a region of this context. A synced copy of a platform region (is_synced: a tenant's
     * assigned region) gets the minimal fields only — name, status, demo status, its own projects.
     */
    public function toArray(Request $request): array
    {
        $synced = (bool) ($this->is_synced ?? false);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'is_demo' => $this->is_demo,
            'is_synced' => $synced,
            'region_mode' => $this->region_mode ?? 'managed',
            'region_status' => $this->region_status ?? 'active',
            'region_demo_status' => $this->region_demo_status,
            // the projects of this context there (tenant context: the tenant's projects table)
            'project_count' => $this->projectCount(),
            // GPU products / capacity for the project GPU fields (docs/ai.md)
            /** @var array{mode: string, status: string, total: int, allocatable: int, available: int, products: list<array{product: string, memory_mb: int|null, count: int}>} */
            'gpu' => app(GpuInventory::class)->publicSummary($this->resource),
            // can take part in global projects (docs/global-projects.md)
            'global_dns_ready' => GlobalProjectService::globalDnsReady((string) $this->resource->getKey()),
            $this->mergeUnless($synced, fn () => $this->fullFields()),
        ];
    }

    /**
     * The fields of a region of this context, not of a synced copy.
     */
    private function fullFields(): array
    {
        $workers = is_array($this->selected_workers)
            ? $this->selected_workers
            : json_decode($this->selected_workers ?? '[]', true) ?? [];

        // projects, model services that are not stopped and sandboxes, by budget (RegionCapacity)
        $usage = $this->regionUsage($this->resource);
        $used = ResourceReservation::total($usage);
        $factors = RegionCapacity::factors($this->resource);

        return [
            'server_url' => $this->resource->server_url,
            'total_cpu_m' => $this->total_cpu_m,
            'total_memory_mb' => $this->total_memory_mb,
            'has_kubeconfig' => ! empty($this->kubeconfig),
            // every admitted reservation: always-on + scale-to-zero (may exceed the total: FaaS overcommit)
            'used_cpu_m' => (int) $used['cpu_m'],
            'used_memory_mb' => (int) $used['memory_mb'],
            'faas_cpu_overcommit' => (float) $factors['cpu_m'],
            'faas_memory_overcommit' => (float) $factors['memory_mb'],
            // the always-on and FaaS overcommit budgets (docs/architecture.md → Region capacity and admission)
            /** @var array<string, array{capacity: int, always_on_used: int, always_on_available: int, faas_overcommit: float, faas_budget: int, faas_used: int, faas_available: int}> */
            'capacity' => RegionCapacity::summary($this->resource, $usage),
            'knative_status' => $this->knative_status,
            'knative_status_message' => $this->knative_status_message,
            /** @var string|null */
            'knative_status_checked_at' => $this->knative_status_checked_at?->toISOString(),
            'cluster_status' => $this->cluster_status ?? 'unknown',
            'cluster_status_message' => $this->cluster_status_message,
            'project_namespace' => $this->project_namespace ?? $this->name,
            /** @var list<string> */
            'selected_workers' => array_values($workers),
            'worker_count' => (int) count($workers),
            /** @var array<string, mixed> */
            'knative_settings' => (object) $this->resource->effective_settings,
            'redis_mode' => $this->redis_mode,
            'knative_redis_status' => $this->knative_redis_status,
            'redis_address' => $this->redis_address,
            'knative_redis_status_message' => $this->knative_redis_status_message,
            'has_redis' => (bool) $this->resource->has_redis,
            'external_ip' => $this->external_ip,
            /** @var array{wildcard: string, type: string|null, target: string|null}|null */
            'dns_instruction' => $this->buildDnsInstruction(),
            // Prometheus and Loki: RunR's own (managed) or an existing in-cluster one (PUT …/monitoring)
            'monitoring' => [
                'prometheus_url' => $this->resource->prometheus_url,
                'prometheus_managed' => $this->resource->isPrometheusManaged(),
                'loki_url' => $this->resource->loki_url,
                'loki_managed' => $this->resource->isLokiManaged(),
                'loki_org_id' => $this->resource->loki_org_id,
            ],
            // Function-domain TLS (region's own Traefik, separate from the console's Ingress)
            'function_tls_mode' => $this->function_tls_mode,
            /** @var string|null */
            'function_tls_expires_at' => $this->function_tls_expires_at?->toISOString(),
            // Component statuses
            'knative_operator_status' => $this->knative_operator_status ?? 'not_installed',
            'knative_operator_status_message' => $this->knative_operator_status_message,
            'knative_operator_namespace' => $this->knative_operator_namespace ?? 'knative-operator',
            'knative_serving_status' => $this->knative_serving_status ?? 'not_installed',
            'knative_serving_status_message' => $this->knative_serving_status_message,
            'knative_serving_namespace' => $this->knative_serving_namespace ?? 'knative-serving',
            'knative_serving_ingress_class' => 'traefik',
            'has_serving' => (bool) $this->resource->has_serving,
            // Registry
            'registry_mode' => $this->registry_mode,
            'registry_address' => $this->registry_address,
            'registry_storage_type' => $this->registry_storage_type,
            'registry_status' => $this->registry_status,
            'registry_status_message' => $this->registry_status_message,
            'registry_namespace' => $this->registry_namespace ?? 'runr-registry',
            'has_registry' => (bool) $this->resource->has_registry,
            'dns_resolved' => $this->dns_resolved,
            // Sandbox (Runtime Isolation)
            'sandbox_kata_status' => $this->sandbox_kata_status ?? 'not_installed',
            'sandbox_kata_status_message' => $this->sandbox_kata_status_message,
            /** @var list<string>|null */
            'sandbox_kata_hypervisor' => $this->sandbox_kata_hypervisor,
            // Firecracker's devmapper thin-pool size in GiB, as set up on the nodes (null: none)
            /** @var int|null */
            'sandbox_kata_devmapper_pool_gb' => $this->resource->getAttribute('sandbox_kata_devmapper_pool_gb'),
            'sandbox_gvisor_status' => $this->sandbox_gvisor_status ?? 'not_installed',
            'sandbox_gvisor_status_message' => $this->sandbox_gvisor_status_message,
            'sandbox_xaas_runtime' => $this->sandbox_xaas_runtime ?? 'runc',
            'sandbox_faas_runtime' => $this->sandbox_faas_runtime ?? 'runc',
            'sandbox_caas_runtime' => $this->sandbox_caas_runtime ?? 'runc',
            'sandbox_build_runtime' => $this->sandbox_build_runtime ?? 'runc',
            'has_sandbox_kata' => (bool) ($this->resource->has_sandbox_kata ?? false),
            'has_sandbox_gvisor' => (bool) ($this->resource->has_sandbox_gvisor ?? false),
            // WebAssembly support (runwasi shims; the runtime picker offers Wasm runtimes where it is ready)
            'sandbox_wasm_status' => $this->resource->sandbox_wasm_status ?? 'not_installed',
            'sandbox_wasm_status_message' => $this->resource->sandbox_wasm_status_message,
            /** @var list<string> */
            'sandbox_wasm_shims' => $this->resource->sandbox_wasm_shims ?? [],
            /** @var list<string>|null */
            'sandbox_wasm_nodes' => $this->resource->sandbox_wasm_nodes,
            'has_sandbox_wasm' => ($this->resource->sandbox_wasm_status ?? null) === 'ready',
            // cert-manager and the ClusterIssuer of custom domains (GET …/cert-manager has the details)
            'cert_manager_status' => $this->resource->cert_manager_status ?? 'not_installed',
            'cert_manager_managed' => $this->resource->cert_manager_managed,
            'cert_manager_version' => $this->resource->cert_manager_version,
            /** @var string|null */
            'cert_manager_issuer' => app(CertManagerService::class)->effectiveIssuer($this->resource),
            // Migration
            'migration_status' => $this->migration_status,
            /** @var string|null */
            'migration_started_at' => $this->migration_started_at?->toISOString(),
            'migration_old_ip' => $this->migration_old_ip,
            'is_migrating' => (bool) $this->resource->is_migrating,
            'domain_migration_status' => $this->domain_migration_status,
            /** @var string|null */
            'domain_migration_started_at' => $this->domain_migration_started_at?->toISOString(),
            'is_domain_migrating' => (bool) $this->resource->is_domain_migrating,
            // The function domain a base-domain migration moves the region to (served next to the
            // current one until DNS of *.<target> answers the external address)
            'domain_migration_target' => RegionDomains::migrationTarget($this->resource),
            // The region's PowerDNS server of the global zone (docs/global-projects.md)
            /** @var array{status: string, address: string|null, ns_name: string|null}|null */
            'global_dns' => $this->globalDns(),
            // The last region:reconcile (the upgrade's Job runr-region-reconcile-<revision>): running,
            // done, unfinished (failed / waiting steps, steps the deadline left) or unreachable, and
            // what this region was left with; null before the first run (ReconcileRecord)
            /** @var array{status: string, version: string|null, run: string|null, started_at: string|null, finished_at: string|null, problems: list<string>}|null */
            'reconcile' => app(ReconcileRecord::class)->forRegion((string) $this->resource->getKey()),
        ];
    }

    /** @return array{status: string, address: ?string, ns_name: ?string}|null */
    private function globalDns(): ?array
    {
        $server = RegionGlobalDns::forRegion((string) $this->resource->getKey());
        if (! $server) {
            return null;
        }

        return [
            'status' => $server->status,
            'address' => $server->address,
            'ns_name' => app(GlobalDnsZone::class)->nsName($this->resource),
        ];
    }

    /**
     * Projects in the region, from the projects table of the current context: the platform's
     * (central) or, for a tenant's request, the tenant's — its copy of a platform region included.
     */
    private function projectCount(): int
    {
        return Project::where('knative_region_id', $this->id)->count();
    }

    private function buildDnsInstruction(): ?array
    {
        $settings = $this->resource->effective_settings;
        $knativeDomain = $settings['knative_domain'] ?? null;

        if (! $knativeDomain && $this->resource->has_serving) {
            $baseDomain = Setting::get('base_domain');
            if ($baseDomain) {
                $knativeDomain = $this->resource->name.'.'.$baseDomain;
            }
        }

        if (! $knativeDomain) {
            return null;
        }

        $wildcard = '*.'.$knativeDomain;
        $ip = $this->external_ip;

        if (! $ip) {
            return ['wildcard' => $wildcard, 'type' => null, 'target' => null];
        }

        $isIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;

        return [
            'wildcard' => $wildcard,
            'type' => $isIp ? 'A' : 'CNAME',
            'target' => $ip,
        ];
    }
}
