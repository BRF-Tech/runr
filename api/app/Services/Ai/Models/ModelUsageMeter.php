<?php

namespace App\Services\Ai\Models;

use App\Models\AiModelService;
use App\Models\AiModelServiceUsage;
use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Helpers\KubeQuantity;
use App\Services\Helpers\ResourceReservation;
use App\Services\KubernetesClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Meters model services (models:meter, every minute): for every running pod of a service the
 * time since the previous sample (at most two intervals, so a missed run does not over-count) is
 * added to the service's day — replica ms, GPU-unit ms (GPUs × ms), CPU millicore ms and memory
 * MiB ms of the engine container's requests. A pod that started since the last sample counts
 * from its start. The usage aggregation adds the day to usage_records (quantities); the Billing
 * module prices it (docs/billing.md).
 *
 * This implementation meters the current schema (single organization); the Tenancy module runs
 * it in every tenant (TenantModelUsageMeter).
 */
class ModelUsageMeter
{
    public const INTERVAL_MS = 60_000;

    /** Meter every organization (the pods of a region are listed once per run). */
    public function meterAll(): int
    {
        $count = 0;
        $podsByRegion = [];

        $this->eachOrganization(function () use (&$count, &$podsByRegion) {
            $count += $this->meterCurrent($podsByRegion);
        });

        return $count;
    }

    /**
     * Run $fn for every organization: here once in the current schema; the Tenancy module's
     * TenantModelUsageMeter runs it in every tenant.
     */
    public function eachOrganization(callable $fn): void
    {
        $fn();
    }

    /**
     * Meter the services of the current schema.
     *
     * @param  array<string, ?array>  $podsByRegion  region id → active pods (shared across tenants), null = list them
     */
    public function meterCurrent(?array &$podsByRegion = null): int
    {
        $podsByRegion ??= [];
        $count = 0;

        $services = AiModelService::whereIn('status', [AiModelService::STATUS_DEPLOYING, AiModelService::STATUS_READY])
            ->with('region')
            ->get();

        foreach ($services->groupBy('knative_region_id') as $regionId => $regionServices) {
            $region = $regionServices->first()->region;
            if (! $region || $region->is_demo || ! $region->kubeconfig) {
                continue;
            }

            if (! array_key_exists($regionId, $podsByRegion)) {
                $podsByRegion[$regionId] = $this->listPods($region);
            }
            $pods = $podsByRegion[$regionId];
            if ($pods === null) {
                continue;
            }

            foreach ($regionServices as $service) {
                $this->meterService($service, $pods, now());
                $count++;
            }
        }

        return $count;
    }

    /**
     * Add one sample of a service.
     *
     * @param  list<array>  $pods  active pods of the region
     */
    public function meterService(AiModelService $service, array $pods, Carbon $now): void
    {
        // whereDate: the date cast stores "Y-m-d 00:00:00" where the column is text (SQLite), so an
        // equality on the date string would miss the day's row (BindingUsageMeter does the same)
        $date = $now->toDateString();
        $usage = AiModelServiceUsage::where('ai_model_service_id', $service->id)
            ->whereDate('record_date', $date)
            ->first()
            ?? new AiModelServiceUsage(['ai_model_service_id' => $service->id, 'record_date' => $date]);
        $usage->knative_region_id ??= $service->knative_region_id;

        $previous = $usage->last_sampled_at
            ?? AiModelServiceUsage::where('ai_model_service_id', $service->id)->orderByDesc('record_date')->value('last_sampled_at');
        $previous = $previous ? Carbon::parse($previous) : null;

        $delta = self::sample($service, $pods, $now, $previous);

        $usage->replica_ms = (int) $usage->replica_ms + $delta['replica_ms'];
        $usage->gpu_unit_ms = (int) $usage->gpu_unit_ms + $delta['gpu_unit_ms'];
        $usage->cpu_mc_ms = (int) $usage->cpu_mc_ms + $delta['cpu_mc_ms'];
        $usage->memory_mib_ms = (int) $usage->memory_mib_ms + $delta['memory_mib_ms'];
        $usage->last_sampled_at = $now;
        $usage->save();
    }

    /**
     * Usage of a service's running pods between $previous and $now.
     *
     * @return array{replica_ms: int, gpu_unit_ms: int, cpu_mc_ms: int, memory_mib_ms: int}
     */
    public static function sample(AiModelService $service, array $pods, Carbon $now, ?Carbon $previous): array
    {
        $delta = ['replica_ms' => 0, 'gpu_unit_ms' => 0, 'cpu_mc_ms' => 0, 'memory_mib_ms' => 0];
        $max = 2 * self::INTERVAL_MS;

        foreach ($pods as $pod) {
            if (($pod['metadata']['labels'][AiModelService::LABEL] ?? null) !== $service->id) {
                continue;
            }
            if (($pod['status']['phase'] ?? '') !== 'Running') {
                continue;
            }

            $started = isset($pod['status']['startTime']) ? Carbon::parse($pod['status']['startTime']) : $now;
            $from = $previous && $previous->greaterThan($started) ? $previous : $started;
            $ms = (int) max(0, min($max, $from->diffInMilliseconds($now, true)));
            if ($from->greaterThan($now)) {
                $ms = 0;
            }

            $container = collect($pod['spec']['containers'] ?? [])->firstWhere('name', 'model') ?? ($pod['spec']['containers'][0] ?? []);
            $cpuM = ResourceReservation::parseCpu($container['resources']['requests']['cpu'] ?? $service->cpu);
            $memMb = (int) round(KubeQuantity::toMib(KubeQuantity::bytes($container['resources']['requests']['memory'] ?? ($service->memory_mb.'Mi'))));

            $delta['replica_ms'] += $ms;
            $delta['gpu_unit_ms'] += $ms * GpuInventory::podGpus($pod);
            $delta['cpu_mc_ms'] += $ms * $cpuM;
            $delta['memory_mib_ms'] += $ms * $memMb;
        }

        return $delta;
    }

    protected function listPods(KnativeRegion $region): ?array
    {
        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            return $k8s->listResources('v1', 'Pod', $region->project_namespace ?: $region->name, ['app.kubernetes.io/component' => 'model-service'])['items'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Model usage metering: pods could not be listed', ['region' => $region->name, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
