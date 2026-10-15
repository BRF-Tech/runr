<?php

namespace Modules\Tenancy\Jobs;

use App\Models\AiModelService;
use App\Models\AiModelServiceUsage;
use App\Models\DeploymentSession;
use App\Models\Project;
use App\Services\Ai\Gpu\GpuRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\Bindings\BindingUsagePricing;
use Modules\Tenancy\Services\CostCalculationService;

/**
 * Aggregates deployment_sessions into daily usage_records with cost.
 * Runs every 5 minutes, iterates over all tenants.
 */
class UsageAggregationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Scheduled every 5 minutes and every run re-aggregates the whole day: an immediate retry
     * only repeats a failure, the next run catches up.
     */
    public int $tries = 1;

    public int $timeout = 240;

    public function handle(CostCalculationService $costService): void
    {
        // One tenant's failure must not stop the metering of the others
        Tenant::all()->each(function ($tenant) use ($costService) {
            try {
                $this->aggregateForTenant($tenant, $costService);
            } catch (\Throwable $e) {
                Log::error('Usage aggregation failed for tenant', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
                report($e);
            }
        });
    }

    private function aggregateForTenant(Tenant $tenant, CostCalculationService $costService): void
    {
        $tenant->run(function () use ($costService) {
            $today = now()->toDateString();
            $projects = Project::whereHas('activeDeployment')->with('activeDeployment')->get();

            foreach ($projects as $project) {
                $this->aggregateProject($project, $today, $costService);
            }

            // Model services (docs/huggingface.md): metered by models:meter
            $this->aggregateModelServices($today, $costService);
            // Bindings: storage GB-hours / instance hours of the day priced with the CostConfig
            app(BindingUsagePricing::class)->priceDate($today);
        });
    }

    private function aggregateProject(Project $project, string $today, CostCalculationService $costService): void
    {
        $deployment = $project->activeDeployment;
        if (! $deployment) {
            return;
        }

        $regionId = $project->knative_region_id;
        $config = $this->getProjectConfig($project);
        $scaleMode = ($config->scale_to_zero ?? true) ? 'scale_to_zero' : 'custom_scale';
        // usage_records.entry_point is http | schedule | queue: a `workflow` project runs a Knative
        // Service like http (its step executions are metered in workflow_usage)
        $entryPoint = in_array($config->trigger_type ?? 'http', ['http', 'schedule', 'queue'], true) ? $config->trigger_type : 'http';

        if ($scaleMode === 'scale_to_zero') {
            $this->aggregateScaleToZero($project, $deployment, $regionId, $today, $entryPoint, $costService);
        } else {
            $this->aggregateCustomScale($project, $deployment, $regionId, $today, $entryPoint, $config, $costService);
        }
    }

    private function aggregateScaleToZero($project, $deployment, $regionId, $today, $entryPoint, $costService): void
    {
        $sessions = DeploymentSession::where('project_id', $project->id)
            ->whereDate('started_at', $today)
            ->whereNotNull('ended_at')
            ->get();

        if ($sessions->isEmpty()) {
            return;
        }

        $totalCpuMs = $sessions->sum('cpu_used_ms');
        $totalMemMs = $sessions->sum('memory_used_mb_ms');
        $totalDuration = $sessions->sum('duration_ms');

        // GPU projects: every session's pods hold the project's GPUs for the session
        $gpuCount = $this->gpuCount($project);
        $gpuUnitMs = $gpuCount > 0
            ? (int) $sessions->sum(fn ($s) => (int) $s->duration_ms * max(1, (int) ($s->pod_count ?? 1))) * $gpuCount
            : 0;

        $cost = $costService->calculateSessionCost(
            (int) $totalCpuMs,
            (int) $totalMemMs,
            $entryPoint,
            $regionId,
            $gpuUnitMs,
        );

        $this->upsertRecord(
            $today,
            [
                'project_id' => $project->id,
                'deployment_id' => $deployment->id,
                'region_id' => $regionId,
                'scale_mode' => 'scale_to_zero',
                'entry_point' => $entryPoint,
            ],
            [
                'session_count' => $sessions->count(),
                'total_duration_ms' => $totalDuration,
                'total_cpu_mc_ms' => $totalCpuMs,
                'total_memory_mib_ms' => $totalMemMs,
                'cold_start_count' => $sessions->where('cold_start', true)->count(),
                'gpu_count' => $gpuCount,
                'total_gpu_unit_ms' => $gpuUnitMs,
                'raw_cost' => $cost['raw_cost'],
                'multiplier' => $cost['multiplier'],
                'final_cost' => $cost['final_cost'],
                'currency' => $cost['currency'],
            ],
        );
    }

    private function aggregateCustomScale($project, $deployment, $regionId, $today, $entryPoint, $config, $costService): void
    {
        $cpuMc = $this->cpuMillicores($config->cpu ?? null);
        $memoryMb = $config->memory_mb ?? 256;
        $minScale = $config->min_scale ?? 1;

        $gpuCount = $this->gpuCount($project);
        $cost = $costService->calculateDailyCost($minScale, $cpuMc, $memoryMb, $entryPoint, $regionId, $gpuCount);

        $this->upsertRecord(
            $today,
            [
                'project_id' => $project->id,
                'deployment_id' => $deployment->id,
                'region_id' => $regionId,
                'scale_mode' => 'custom_scale',
                'entry_point' => $entryPoint,
            ],
            [
                'reserved_cpu_m' => $cpuMc,
                'reserved_memory_mb' => $memoryMb,
                'min_scale' => $minScale,
                'gpu_count' => $gpuCount,
                'total_gpu_unit_ms' => $minScale * $gpuCount * 86_400_000,
                'raw_cost' => $cost['raw_cost'],
                'multiplier' => $cost['multiplier'],
                'final_cost' => $cost['final_cost'],
                'currency' => $cost['currency'],
            ],
        );
    }

    /**
     * K8s CPU quantity of the project config ("250m", "0.5", "1") in millicores; 250 when unset.
     */
    private function cpuMillicores(mixed $cpu): int
    {
        $cpu = trim((string) $cpu);
        if ($cpu === '') {
            return 250;
        }
        if (str_ends_with($cpu, 'm')) {
            return max(1, (int) $cpu);
        }

        return is_numeric($cpu) ? max(1, (int) round((float) $cpu * 1000)) : 250;
    }

    /** GPUs of one pod of the project (config.gpu, GPU runtime versions); 0 without. */
    private function gpuCount(Project $project): int
    {
        try {
            return GpuRequest::forProject($project)?->count ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * One usage record per model service and day (source "model"; project_id / deployment_id
     * carry the service id): metered replica, CPU, memory and GPU time priced with the region's
     * cost config. Services with min_scale ≥ 1 and scale-to-zero ones alike — the meter counts
     * the pods that actually ran.
     */
    private function aggregateModelServices(string $today, CostCalculationService $costService): void
    {
        try {
            $usages = AiModelServiceUsage::whereDate('record_date', $today)->get();
        } catch (QueryException) {
            return; // tenant schema without the AI tables (migrations pending)
        }

        $services = AiModelService::whereIn('id', $usages->pluck('ai_model_service_id'))->get()->keyBy('id');

        foreach ($usages as $usage) {
            $service = $services->get($usage->ai_model_service_id);
            $gpus = $service ? (GpuRequest::forService($service)?->count ?? 0) : 0;
            $cost = $costService->calculateSessionCost(
                (int) $usage->cpu_mc_ms,
                (int) $usage->memory_mib_ms,
                'http',
                $usage->knative_region_id,
                (int) $usage->gpu_unit_ms,
            );

            $this->upsertRecord(
                $today,
                [
                    'project_id' => $usage->ai_model_service_id,
                    'deployment_id' => $usage->ai_model_service_id,
                    'region_id' => $usage->knative_region_id,
                    'scale_mode' => $service && ! $service->scale_to_zero ? 'custom_scale' : 'scale_to_zero',
                    'entry_point' => 'http',
                ],
                [
                    'source' => 'model',
                    'session_count' => 0,
                    'total_duration_ms' => (int) $usage->replica_ms,
                    'total_cpu_mc_ms' => (int) $usage->cpu_mc_ms,
                    'total_memory_mib_ms' => (int) $usage->memory_mib_ms,
                    'min_scale' => (int) ($service->min_scale ?? 0),
                    'gpu_count' => $gpus,
                    'total_gpu_unit_ms' => (int) $usage->gpu_unit_ms,
                    'raw_cost' => $cost['raw_cost'],
                    'multiplier' => $cost['multiplier'],
                    'final_cost' => $cost['final_cost'],
                    'currency' => $cost['currency'],
                ],
            );
        }
    }

    /**
     * Updates the day's usage record of these keys, or creates it. whereDate: the date cast stores
     * "Y-m-d 00:00:00" where the column is text (SQLite), so an equality on the date string (as
     * updateOrCreate does) misses the row and every run would add another record of the day.
     *
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     */
    private function upsertRecord(string $date, array $keys, array $values): void
    {
        $record = UsageRecord::query()->where($keys)->whereDate('record_date', $date)->first()
            ?? new UsageRecord([...$keys, 'record_date' => $date]);

        $record->fill($values)->save();
    }

    private function getProjectConfig(Project $project): object
    {
        return match ($project->service_type) {
            'xaas' => $project->xaasConfig ?? (object) [],
            'faas' => $project->faasConfig ?? (object) [],
            'caas' => $project->containerConfig ?? (object) [],
            default => (object) [],
        };
    }
}
