<?php

namespace App\Services;

use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Services\Concerns\MetricsDownsampleTrait;
use App\Services\Concerns\MetricsSnapshotTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MetricsPersistenceService
{
    use MetricsDownsampleTrait, MetricsSnapshotTrait;

    public function __construct(private KnativeMetricsService $metricsService) {}

    /**
     * Get historical metrics for a project within a date range.
     */
    public function getHistoricalMetrics(
        Project $project,
        Carbon $from,
        Carbon $to,
        string $granularity = '5m',
    ): Collection {
        return MetricSnapshot::where('project_id', $project->id)
            ->where('granularity', $granularity)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'cpu_cores', 'memory_mb', 'rps', 'concurrency', 'pod_count', 'cold_starts']);
    }

    /**
     * Get a summary of metrics for a given period.
     */
    public function getSummary(Project $project, int $days = 7): array
    {
        $from = now()->subDays($days);

        $stats = MetricSnapshot::where('project_id', $project->id)
            ->where('recorded_at', '>=', $from)
            ->selectRaw('
                AVG(cpu_cores) as avg_cpu,
                MAX(cpu_cores) as max_cpu,
                AVG(memory_mb) as avg_memory,
                MAX(memory_mb) as max_memory,
                AVG(rps) as avg_rps,
                MAX(rps) as max_rps,
                SUM(cold_starts) as total_cold_starts,
                COUNT(*) as sample_count
            ')
            ->first();

        return [
            'period_days' => $days,
            'avg_cpu' => $stats->avg_cpu ? round((float) $stats->avg_cpu, 4) : null,
            'max_cpu' => $stats->max_cpu ? round((float) $stats->max_cpu, 4) : null,
            'avg_memory' => $stats->avg_memory ? round((float) $stats->avg_memory, 2) : null,
            'max_memory' => $stats->max_memory ? round((float) $stats->max_memory, 2) : null,
            'avg_rps' => $stats->avg_rps ? round((float) $stats->avg_rps, 3) : null,
            'max_rps' => $stats->max_rps ? round((float) $stats->max_rps, 3) : null,
            'total_cold_starts' => (int) ($stats->total_cold_starts ?? 0),
            'sample_count' => (int) ($stats->sample_count ?? 0),
        ];
    }

    /**
     * Delete metric snapshots older than retention period.
     */
    public function cleanup(int $retentionDays = 90): int
    {
        return MetricSnapshot::where('recorded_at', '<', now()->subDays($retentionDays))
            ->where('granularity', '!=', '1d')
            ->delete();
    }
}
