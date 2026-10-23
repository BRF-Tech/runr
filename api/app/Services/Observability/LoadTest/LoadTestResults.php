<?php

namespace App\Services\Observability\LoadTest;

use App\Models\DeploymentSession;
use App\Models\Project;
use App\Services\KnativeMetricsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * What a finished load test reports (docs/load-test.md#results):
 *
 *  - from k6 (the SUMMARY_MARKER line its handleSummary prints): requests, requests per second,
 *    error rate (responses that are not 2xx / 3xx, timeouts, connection errors), latency
 *    percentiles, bytes
 *  - from RunR's metrics in the test's window: cold starts (the function sessions the region agent
 *    reported as cold — what usage_records.cold_start_count adds up per day) and the pods the
 *    project scaled to (peak of the cAdvisor series of its pods in the region's Prometheus; without
 *    Prometheus, the pods with a session in the window)
 */
class LoadTestResults
{
    public function __construct(private KnativeMetricsService $metrics) {}

    /**
     * The results line of a k6 log (the last one, should the log hold several), null when the
     * log has none (k6 did not get to the end).
     *
     * @return array<string, mixed>|null
     */
    public static function parseSummary(string $log): ?array
    {
        $found = null;
        foreach (preg_split('/\r?\n/', $log) ?: [] as $line) {
            $at = strpos($line, LoadTestManifests::SUMMARY_MARKER);
            if ($at === false) {
                continue;
            }
            $data = json_decode(substr($line, $at + strlen(LoadTestManifests::SUMMARY_MARKER)), true);
            if (is_array($data)) {
                $found = $data;
            }
        }

        return $found === null ? null : self::normalize($found);
    }

    /**
     * Rounded numbers of the fields RunR stores; anything else of the line is dropped.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $num = fn (mixed $v, int $precision = 2): ?float => is_numeric($v) && is_finite((float) $v) ? round((float) $v, $precision) : null;
        $latency = is_array($raw['latency_ms'] ?? null) ? $raw['latency_ms'] : [];

        return [
            'requests' => (int) ($raw['requests'] ?? 0),
            'failed_requests' => (int) ($raw['failed_requests'] ?? 0),
            'rps' => $num($raw['rps'] ?? null) ?? 0.0,
            'error_rate' => $num($raw['error_rate'] ?? null, 4) ?? 0.0,
            'latency_ms' => [
                'avg' => $num($latency['avg'] ?? null),
                'min' => $num($latency['min'] ?? null),
                'p50' => $num($latency['p50'] ?? $latency['med'] ?? null),
                'p95' => $num($latency['p95'] ?? null),
                'p99' => $num($latency['p99'] ?? null),
                'max' => $num($latency['max'] ?? null),
            ],
            'data_received' => (int) ($raw['data_received'] ?? 0),
            'data_sent' => (int) ($raw['data_sent'] ?? 0),
            'vus_max' => (int) ($raw['vus_max'] ?? 0),
            'test_run_duration_ms' => isset($raw['test_run_duration_ms']) && is_numeric($raw['test_run_duration_ms']) ? (int) $raw['test_run_duration_ms'] : null,
        ];
    }

    /**
     * Cold starts and the pods the project scaled to between $from and $to.
     *
     * @return array{cold_starts: int, pods_peak: int|null, pods_source: string|null}
     */
    public function scaling(Project $project, Carbon $from, Carbon $to): array
    {
        // a session the agent reports a few seconds late still counts
        $until = $to->copy()->addSeconds(15);

        $coldStarts = DeploymentSession::query()
            ->where('project_id', $project->id)
            ->where('cold_start', true)
            ->whereBetween('started_at', [$from, $until])
            ->count();

        $peak = $this->prometheusPeak($project, $from);
        $source = $peak !== null ? 'prometheus' : null;
        if ($peak === null) {
            $peak = $this->sessionPods($project, $from, $until);
            $source = $peak !== null ? 'sessions' : null;
        }

        return ['cold_starts' => $coldStarts, 'pods_peak' => $peak, 'pods_source' => $source];
    }

    /**
     * Peak count of the project's function pods since $from (cAdvisor's working-set series, one
     * per running container), read right after the test: the window reaches back to its start.
     */
    private function prometheusPeak(Project $project, Carbon $from): ?int
    {
        $region = $project->knativeRegion;
        if (! $region) {
            return null;
        }

        try {
            $value = $this->metrics->instant($region, ['pods' => self::podsPeakQuery($project, $from)])['pods'] ?? null;
        } catch (\Throwable $e) {
            Log::info('Load test: pod peak not read from Prometheus', ['project' => $project->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $value !== null ? (int) round($value) : null;
    }

    /** PromQL of the pod peak: max over the window of the number of the revision pods' containers. */
    public static function podsPeakQuery(Project $project, Carbon $from, ?Carbon $now = null): string
    {
        $region = $project->knativeRegion;
        $namespace = $region ? ($region->project_namespace ?: $region->name) : '';
        $container = $project->service_type === 'caas' ? 'app' : 'handler';
        $range = max(30, (int) ceil(abs(($now ?? now())->diffInSeconds($from))) + 15);
        // Knative's pods: <service>-<revision number>-deployment-<hash> (a slug has no regex
        // metacharacters: Project::SLUG_PATTERN)
        $labels = sprintf('namespace="%s",pod=~"%s-[0-9]+-deployment-.*",container="%s"', $namespace, $project->slug, $container);

        return "max_over_time(count(container_memory_working_set_bytes{{$labels}})[{$range}s:5s])";
    }

    /** Distinct pods with a function session overlapping the window (the agent's reports). */
    private function sessionPods(Project $project, Carbon $from, Carbon $until): ?int
    {
        $pods = DeploymentSession::query()
            ->where('project_id', $project->id)
            ->where('started_at', '<=', $until)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $from))
            ->limit(5000)
            ->pluck('metadata')
            ->map(fn ($metadata) => is_array($metadata) ? ($metadata['pod'] ?? null) : null)
            ->filter()
            ->unique()
            ->count();

        return $pods > 0 ? $pods : null;
    }
}
