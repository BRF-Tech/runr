<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Setting;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Concerns\ResourceComputationTrait;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnativeRegionResource extends JsonResource
{
    use ResourceComputationTrait;

    public function toArray(Request $request): array
    {
        $isSynced = $this->is_synced ?? false;

        // Synced (assigned) regions: minimal data — name + demo status only
        if ($isSynced) {
            return [
                'id' => $this->id,
                'name' => $this->name,
                'is_active' => $this->is_active,
                'is_demo' => $this->is_demo,
                'is_synced' => true,
                'region_mode' => $this->region_mode ?? 'managed',
                'region_status' => $this->region_status ?? 'active',
                'region_demo_status' => $this->region_demo_status,
                // GPU products / capacity for the project GPU fields (docs/ai.md)
                'gpu' => app(GpuInventory::class)->publicSummary($this->resource),
            ];
        }

        return $this->buildFullResponse();
    }

    private function buildFullResponse(): array
    {
        $workers = is_array($this->selected_workers)
            ? $this->selected_workers
            : json_decode($this->selected_workers ?? '[]', true) ?? [];

        // projects, model services that are not stopped and sandboxes, by budget (RegionCapacity)
        $usage = $this->regionUsage($this->resource);
        $used = ResourceReservation::total($usage);
        $factors = RegionCapacity::factors($this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'server_url' => $this->resource->server_url,
            'total_cpu_m' => $this->total_cpu_m,
            'total_memory_mb' => $this->total_memory_mb,
            'is_active' => $this->is_active,
            'region_status' => $this->region_status ?? 'active',
            'is_demo' => $this->is_demo,
            'is_synced' => false,
            'region_mode' => $this->region_mode ?? 'managed',
            'has_kubeconfig' => ! empty($this->kubeconfig),
            // every admitted reservation: always-on + scale-to-zero (may exceed the total: FaaS overcommit)
            'used_cpu_m' => $used['cpu_m'],
            'used_memory_mb' => $used['memory_mb'],
            'faas_cpu_overcommit' => $factors['cpu_m'],
            'faas_memory_overcommit' => $factors['memory_mb'],
            // the always-on and FaaS overcommit budgets (docs/architecture.md → Region capacity and admission)
            'capacity' => RegionCapacity::summary($this->resource, $usage),
            'project_count' => Project::where('knative_region_id', $this->id)->count(),
            'knative_status' => $this->knative_status,
            'knative_status_message' => $this->knative_status_message,
            'knative_status_checked_at' => $this->knative_status_checked_at?->toISOString(),
            'cluster_status' => $this->cluster_status ?? 'unknown',
            'cluster_status_message' => $this->cluster_status_message,
            'project_namespace' => $this->project_namespace ?? $this->name,
            'selected_workers' => $workers,
            'worker_count' => count($workers),
            'knative_settings' => $this->resource->effective_settings,
            'redis_mode' => $this->redis_mode,
            'knative_redis_status' => $this->knative_redis_status,
            'redis_address' => $this->redis_address,
            'knative_redis_status_message' => $this->knative_redis_status_message,
            'has_redis' => $this->resource->has_redis,
            'external_ip' => $this->external_ip,
            'dns_instruction' => $this->buildDnsInstruction(),
            // Function-domain TLS (region's own Traefik, separate from the console's Ingress)
            'gpu' => app(GpuInventory::class)->publicSummary($this->resource),
            'function_tls_mode' => $this->function_tls_mode,
            'function_tls_expires_at' => $this->function_tls_expires_at?->toISOString(),
            // Component statuses
            'knative_operator_status' => $this->knative_operator_status ?? 'not_installed',
            'knative_operator_status_message' => $this->knative_operator_status_message,
            'knative_operator_namespace' => $this->knative_operator_namespace ?? 'knative-operator',
            'knative_serving_status' => $this->knative_serving_status ?? 'not_installed',
            'knative_serving_status_message' => $this->knative_serving_status_message,
            'knative_serving_namespace' => $this->knative_serving_namespace ?? 'knative-serving',
            'knative_serving_ingress_class' => 'traefik',
            'has_serving' => $this->resource->has_serving,
            // Registry
            'registry_mode' => $this->registry_mode,
            'registry_address' => $this->registry_address,
            'registry_storage_type' => $this->registry_storage_type,
            'registry_status' => $this->registry_status,
            'registry_status_message' => $this->registry_status_message,
            'registry_namespace' => $this->registry_namespace ?? 'runr-registry',
            'has_registry' => $this->resource->has_registry,
            'region_demo_status' => $this->region_demo_status,
            'dns_resolved' => $this->dns_resolved,
            // Sandbox (Runtime Isolation)
            'sandbox_kata_status' => $this->sandbox_kata_status ?? 'not_installed',
            'sandbox_kata_status_message' => $this->sandbox_kata_status_message,
            'sandbox_kata_hypervisor' => $this->sandbox_kata_hypervisor,
            'sandbox_gvisor_status' => $this->sandbox_gvisor_status ?? 'not_installed',
            'sandbox_gvisor_status_message' => $this->sandbox_gvisor_status_message,
            'sandbox_xaas_runtime' => $this->sandbox_xaas_runtime ?? 'runc',
            'sandbox_faas_runtime' => $this->sandbox_faas_runtime ?? 'runc',
            'sandbox_caas_runtime' => $this->sandbox_caas_runtime ?? 'runc',
            'sandbox_build_runtime' => $this->sandbox_build_runtime ?? 'runc',
            'has_sandbox_kata' => $this->resource->has_sandbox_kata ?? false,
            'has_sandbox_gvisor' => $this->resource->has_sandbox_gvisor ?? false,
            // WebAssembly support (runwasi shims; the runtime picker offers Wasm runtimes where it is ready)
            'sandbox_wasm_status' => $this->resource->sandbox_wasm_status ?? 'not_installed',
            'sandbox_wasm_status_message' => $this->resource->sandbox_wasm_status_message,
            'sandbox_wasm_shims' => $this->resource->sandbox_wasm_shims ?? [],
            'sandbox_wasm_nodes' => $this->resource->sandbox_wasm_nodes,
            'has_sandbox_wasm' => ($this->resource->sandbox_wasm_status ?? null) === 'ready',
            // Migration
            'migration_status' => $this->migration_status,
            'migration_started_at' => $this->migration_started_at?->toISOString(),
            'migration_old_ip' => $this->migration_old_ip,
            'is_migrating' => $this->resource->is_migrating,
            'domain_migration_status' => $this->domain_migration_status,
            'domain_migration_started_at' => $this->domain_migration_started_at?->toISOString(),
            'is_domain_migrating' => $this->resource->is_domain_migrating,
        ];
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
