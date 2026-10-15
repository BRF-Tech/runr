<?php

namespace App\Services\Concerns;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\MetricSnapshot;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

trait MetricsSnapshotTrait
{
    /**
     * Take a snapshot of current metrics for a project deployment and persist it.
     */
    public function snapshotProjectMetrics(Project $project, Deployment $deployment, KnativeRegion $region): void
    {
        try {
            $metrics = $this->metricsService->getDeploymentMetrics($project, $deployment, true);

            if ($metrics['cpu'] === null && $metrics['memory'] === null && $metrics['rps'] === null) {
                return;
            }

            MetricSnapshot::create([
                'project_id' => $project->id,
                'deployment_id' => $deployment->id,
                'region_id' => $region->id,
                'recorded_at' => now(),
                'granularity' => '1m',
                'cpu_cores' => $metrics['cpu'],
                'memory_mb' => $metrics['memory'],
                'rps' => $metrics['rps'],
                'concurrency' => $metrics['concurrency'] ?? null,
                'pod_count' => $metrics['pod_count'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to snapshot metrics', [
                'project' => $project->slug,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Snapshot metrics for all active deployments in a region.
     */
    public function snapshotAllActive(KnativeRegion $region): int
    {
        $count = 0;

        $projects = Project::where('knative_region_id', $region->id)
            ->whereHas('activeDeployment')
            ->with('activeDeployment')
            ->get();

        foreach ($projects as $project) {
            $this->snapshotProjectMetrics($project, $project->activeDeployment, $region);
            $count++;
        }

        return $count;
    }
}
