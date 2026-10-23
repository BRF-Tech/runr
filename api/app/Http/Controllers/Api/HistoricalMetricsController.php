<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Services\MetricsPersistenceService;
use App\Services\SessionTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HistoricalMetricsController extends Controller
{
    public function __construct(
        private MetricsPersistenceService $metricsService,
        private SessionTrackingService $sessionService,
    ) {}

    /**
     * GET /api/projects/{project}/metrics/history
     *
     * @queryParam from string required ISO 8601 start date
     * @queryParam to string required ISO 8601 end date
     * @queryParam granularity string 1m|5m|1h|1d (default: 5m)
     */
    public function history(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after:from',
            'granularity' => 'in:1m,5m,1h,1d',
        ]);

        $from = Carbon::parse($request->from);
        $to = Carbon::parse($request->to);
        $granularity = $request->granularity ?? '5m';

        $metrics = $this->metricsService->getHistoricalMetrics($project, $from, $to, $granularity);

        return response()->json([
            'data' => $metrics,
            'granularity' => $granularity,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ]);
    }

    /**
     * GET /api/projects/{project}/metrics/summary
     *
     * sessions: the sessions that started in the period and ended. Their total_cpu_ms (CPU
     * milliseconds, 1 vCPU for 1 s = 1000) and total_memory_mb_ms (MiB × ms) are what metering
     * counts for them: the deployment's size × the sessions' pod time (SessionTrackingService::getStats).
     *
     * @queryParam period string 7d|30d|90d (default: 7d)
     */
    public function summary(Request $request, Project $project): JsonResponse
    {
        $period = $request->period ?? '7d';
        $days = (int) rtrim($period, 'd') ?: 7;

        $metricsSummary = $this->metricsService->getSummary($project, $days);

        $from = now()->subDays($days);
        $sessionStats = $this->sessionService->getStats($project, $from, now());

        return response()->json([
            'metrics' => $metricsSummary,
            'sessions' => $sessionStats,
        ]);
    }
}
