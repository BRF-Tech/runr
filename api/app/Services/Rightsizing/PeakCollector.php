<?php

namespace App\Services\Rightsizing;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ResourcePeak;
use App\Services\Helpers\WorkloadSizing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The daily peaks of the current organization context's running projects (resource_peaks), read
 * from their region's Prometheus (ResourcePeakQuery) once a day has ended — the 1-minute metric
 * snapshots are sums over all pods and only averages after a week, so they cannot tell what one
 * pod needed.
 *
 * Every run fills the days of the window (config rightsizing.window_days, up to yesterday) a
 * project has no row for: the first run after an upgrade reads back as far as the region's
 * Prometheus keeps (15 days), a missed night is caught up. A day is read with the size of the
 * deployment that ran then (deployments.sizing); a day no deployment of the project ran is
 * recorded without reading. Projects with GPUs, stopped projects, projects of demo regions or of
 * regions without cluster access are skipped. A region whose Prometheus does not answer is left
 * for the next run (no row is written, so the day is read again).
 */
class PeakCollector
{
    public function __construct(private ResourcePeakQuery $query) {}

    /** @return int days read or recorded */
    public function collect(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $windowStart = $today->copy()->subDays(max(1, (int) config('rightsizing.window_days', 14)));
        $yesterday = $today->copy()->subDay();
        $written = 0;
        /** @var array<string, bool> region id => Prometheus did not answer this run */
        $unreachable = [];

        $projects = Project::query()
            ->whereNull('stopped_at')
            ->whereHas('activeDeployment')
            ->with(['xaasConfig', 'faasConfig', 'containerConfig', 'knativeRegion', 'activeDeployment'])
            ->get();

        foreach ($projects as $project) {
            $region = $project->knativeRegion;
            if (! $project->isSupportedType() || ! self::readable($region) || isset($unreachable[(string) $region->id])) {
                continue;
            }
            if (WorkloadSizing::of($project)['gpu_count'] > 0) {
                continue;
            }

            $from = $windowStart->copy();
            if ($project->created_at !== null && $project->created_at->copy()->startOfDay()->gt($from)) {
                $from = $project->created_at->copy()->startOfDay();
            }

            $known = ResourcePeak::query()
                ->where('project_id', $project->id)
                ->whereDate('day', '>=', $from->toDateString())
                ->whereDate('day', '<=', $yesterday->toDateString())
                ->pluck('day')
                ->map(fn ($day) => Carbon::parse($day)->toDateString())
                ->flip();

            for ($day = $from->copy(); $day->lte($yesterday); $day->addDay()) {
                if ($known->has($day->toDateString())) {
                    continue;
                }

                $result = $this->collectDay($project, $region, $day->copy());
                if ($result === null) {
                    $unreachable[(string) $region->id] = true;
                    Log::warning('Rightsizing: the region\'s Prometheus did not answer', ['region' => $region->id, 'project' => $project->slug]);
                    break;
                }
                $written++;
            }
        }

        return $written;
    }

    /**
     * Read and store one day of a project; null when the region's Prometheus did not answer.
     */
    public function collectDay(Project $project, KnativeRegion $region, Carbon $day): ?ResourcePeak
    {
        $start = $day->copy()->startOfDay();
        $end = $start->copy()->addDay();
        $deployment = self::deploymentOn($project, $start, $end);

        $attributes = ['project_id' => $project->id, 'day' => $start->toDateString()];
        if ($deployment === null) {
            // Nothing of the project ran that day: recorded without asking Prometheus
            return ResourcePeak::query()->updateOrCreate($attributes, ['region_id' => $region->id]);
        }

        $sizing = WorkloadSizing::ofDeployment($deployment, $project);
        $stats = $this->query->day($region, $project, $sizing['trigger_type'], $end->lte(now()) ? $end : now());
        if ($stats === null) {
            return null;
        }

        return ResourcePeak::query()->updateOrCreate($attributes, [
            'region_id' => $sizing['region_id'] ?? $region->id,
            'trigger_type' => $sizing['trigger_type'],
            'cpu_m' => $sizing['cpu_m'],
            'memory_mb' => $sizing['memory_mb'],
            'memory_max_mb' => self::rounded($stats['memory_max'], 2),
            'memory_p95_mb' => self::rounded($stats['memory_p95'], 2),
            'cpu_max_m' => self::whole($stats['cpu_max']),
            'cpu_p95_m' => self::whole($stats['cpu_p95']),
            'throttled_ratio' => $stats['throttled'] === null ? null : round(min(1.0, max(0.0, $stats['throttled'])), 4),
            'oom_kills' => (int) round(max($stats['oom_events'] ?? 0.0, $stats['oom_restarts'] ?? 0.0)),
            'samples' => (int) round($stats['samples'] ?? 0.0),
            'pods' => (int) round($stats['pods'] ?? 0.0),
        ]);
    }

    /** Peaks older than the retention go (config rightsizing.retention_days, at least the window). */
    public function prune(?Carbon $today = null): int
    {
        $days = max((int) config('rightsizing.retention_days', 30), (int) config('rightsizing.window_days', 14));

        return ResourcePeak::query()->whereDate('day', '<', ($today ?? now())->copy()->subDays($days)->toDateString())->delete();
    }

    /** A region RunR can read metrics of: active, real (not a demo), with cluster access. */
    public static function readable(?KnativeRegion $region): bool
    {
        return $region !== null && $region->is_active && ! $region->is_demo && (bool) $region->kubeconfig;
    }

    /**
     * The deployment of the project that ran on the day [$start, $end) — the one that became ready
     * last before the day ended and had not stopped before it began; as in metering, a deployment
     * without ready_at ran only when it is active (from its creation), one that never became ready
     * ran nothing.
     */
    private static function deploymentOn(Project $project, Carbon $start, Carbon $end): ?Deployment
    {
        return Deployment::query()
            ->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('ready_at', '<', $end)
                ->orWhere(fn ($q) => $q->whereNull('ready_at')->where('status', 'active')->where('created_at', '<', $end)))
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->orderByRaw('COALESCE(ready_at, created_at) DESC')
            ->first();
    }

    private static function rounded(?float $value, int $precision): ?float
    {
        return $value === null ? null : round(max(0.0, $value), $precision);
    }

    private static function whole(?float $value): ?int
    {
        return $value === null ? null : (int) ceil(max(0.0, $value));
    }
}
