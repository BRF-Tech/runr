<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Helpers\MonitoringEndpoint;
use App\Services\Helpers\RunrClusterIdentity;
use App\Triggers\ProjectTriggers;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KnativeMetricsService
{
    /**
     * Query Prometheus for deployment metrics.
     *
     * Quick (cpu/memory/rps): uses pre-computed recording rules (runr:*) —
     * Prometheus evaluates these every 2s, so queries are instant label lookups.
     *
     * Full (avg, totals): SWR cache with 60s freshness via defer().
     *
     * Both modes use stale-while-revalidate so the API always responds instantly.
     */
    public function getDeploymentMetrics(Project $project, Deployment $deployment, bool $quick = false): array
    {
        $region = $project->knativeRegion;

        if (! $region) {
            return $this->empty();
        }

        $ns = $region->project_namespace ?? $region->name ?? 'runr';
        $svc = $project->slug;
        $cnt = $project->service_type === 'caas' ? 'app' : 'handler';
        $k8s = new KubernetesClient($region->kubeconfig);

        // CronJob pods (a schedule alone) are named differently from the Knative Service's
        $triggerType = app(ProjectTriggers::class)->plan($project)->cronJob() ? 'schedule' : 'http';
        $queries = $this->buildQueries($ns, $svc, $cnt, $quick, $deployment, $triggerType);

        // Quick metrics: SWR cache with 15s freshness (matches cAdvisor scrape interval).
        // defer() triggers background refresh so the caller always gets a fast response.
        if ($quick) {
            $cacheKey = 'metrics_'.$deployment->id.'_q';
            $entry = cache()->get($cacheKey);

            if ($entry !== null) {
                if (now()->timestamp - $entry['at'] >= 1) {
                    defer(fn () => cache()->put($cacheKey, [
                        'raw' => $this->runParallel($k8s, $region, $queries),
                        'at' => now()->timestamp,
                    ], 300));
                }

                return $this->parseResults($entry['raw'], true);
            }

            $raw = $this->runParallel($k8s, $region, $queries);
            cache()->put($cacheKey, ['raw' => $raw, 'at' => now()->timestamp], 300);

            return $this->parseResults($raw, true);
        }

        // Full metrics (avg1h): SWR cache with 60s freshness via defer().
        $cacheKey = 'metrics_'.$deployment->id.'_f';
        $entry = cache()->get($cacheKey);

        if ($entry !== null) {
            if (now()->timestamp - $entry['at'] >= 60) {
                defer(fn () => cache()->put($cacheKey, [
                    'raw' => $this->runParallel($k8s, $region, $queries),
                    'at' => now()->timestamp,
                ], 1800));
            }

            return $this->parseResults($entry['raw'], false);
        }

        $raw = $this->runParallel($k8s, $region, $queries);
        cache()->put($cacheKey, ['raw' => $raw, 'at' => now()->timestamp], 1800);

        return $this->parseResults($raw, false);
    }

    /**
     * Instant PromQL queries against the region's Prometheus (same path as the deployment
     * metrics: direct from RunR's own cluster, else through the Kubernetes API proxy), uncached.
     * A query without a result is null.
     *
     * @param  array<string, string>  $queries  key => PromQL
     * @return array<string, float|null>
     */
    public function instant(KnativeRegion $region, array $queries): array
    {
        if (! $region->kubeconfig || $queries === []) {
            return array_fill_keys(array_keys($queries), null);
        }

        return $this->runParallel(new KubernetesClient($region->kubeconfig), $region, $queries);
    }

    // ── Query builders ────────────────────────────────────────────────────────

    private function buildQueries(string $ns, string $svc, string $cnt, bool $quick, Deployment $deployment, string $triggerType = 'http'): array
    {
        // Schedule trigger: CronJob pods named {slug}-trigger-{id}-{hash}; otherwise the Knative
        // revisions' pods {slug}-{n}-deployment-… — not an environment's ({slug}-{env}-…,
        // docs/environments.md) nor a preview's
        $isSchedule = $triggerType === 'schedule';
        $pod = $isSchedule ? $svc.'-trigger-.*' : $svc.'-[0-9]+-deployment-.*';
        $labels = "namespace=\"{$ns}\",pod=~\"{$pod}\",container=\"{$cnt}\"";

        // Schedule jobs are short-lived — use 6m window to capture metrics
        // from the most recent execution. Long-running services use 30s.
        $window = $isSchedule ? '6m' : '30s';
        $rateWin = $isSchedule ? '6m' : '30s';

        $quickQueries = [
            'cpu' => "sum(last_over_time(rate(container_cpu_usage_seconds_total{{$labels}}[{$rateWin}])[{$window}:5s]))",
            'memory' => "sum(last_over_time(container_memory_working_set_bytes{{$labels}}[{$window}])) / 1048576",
            'rps_kn' => "sum(rate(kn_revision_request_count_total{namespace_name=\"{$ns}\",service_name=\"{$svc}\"}[1m]))",
            'rps_lg' => "sum(rate(revision_request_count_total{namespace_name=\"{$ns}\",service_name=\"{$svc}\"}[1m]))",
        ];

        if ($quick) {
            return $quickQueries;
        }

        $queries = [
            'cpu' => "sum(last_over_time(runr:cpu_cores:rate2m{{$labels}}[{$window}]))",
            'memory' => "sum(last_over_time(runr:memory_mb:current{{$labels}}[{$window}]))",
            'rps_kn' => "sum(rate(kn_revision_request_count_total{namespace_name=\"{$ns}\",service_name=\"{$svc}\"}[1m]))",
            'rps_lg' => "sum(rate(revision_request_count_total{namespace_name=\"{$ns}\",service_name=\"{$svc}\"}[1m]))",
        ];

        $queries['cpuAvg1h'] = "sum(last_over_time(runr:cpu_cores:avg1h{{$labels}}[3m]))";
        $queries['memAvg1h'] = "sum(last_over_time(runr:memory_mb:avg1h{{$labels}}[3m]))";

        return $queries;
    }

    private function runParallel(KubernetesClient $k8s, KnativeRegion $region, array $queries): array
    {
        $endpoint = MonitoringEndpoint::prometheus($region);

        // Direct HTTP is ~10× faster than the K8s API proxy — but only from RunR's own cluster:
        // for any other region the in-cluster name resolves inside RunR's cluster and would read
        // a different Prometheus (200 with empty vectors, so no fallback would ever trigger).
        if ($this->isRunrCluster($region)) {
            try {
                return $this->runDirectParallel($endpoint->directUrl('api/v1/query'), $queries);
            } catch (\Throwable $e) {
                Log::warning('Direct Prometheus unreachable — falling back to K8s proxy', ['error' => $e->getMessage()]);
            }
        }

        try {
            return $k8s->prometheusParallelQuery(
                $endpoint->namespace, $endpoint->service, $endpoint->port, $queries, $endpoint->path, $endpoint->scheme,
            );
        } catch (\Throwable $e) {
            Log::warning('Prometheus parallel query failed', ['error' => $e->getMessage()]);

            return array_fill_keys(array_keys($queries), null);
        }
    }

    private function isRunrCluster(KnativeRegion $region): bool
    {
        try {
            return app(RunrClusterIdentity::class)->isRunrCluster($region);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Query Prometheus directly via HTTP (no K8s API proxy overhead).
     * Only for a region on RunR's own cluster (see runParallel()); $url is the query endpoint.
     */
    private function runDirectParallel(string $url, array $queries): array
    {

        $responses = Http::pool(function ($pool) use ($url, $queries) {
            foreach ($queries as $key => $q) {
                $pool->as($key)->timeout(8)->get($url, ['query' => $q]);
            }
        });

        $results = [];
        $anySuccess = false;

        foreach ($queries as $key => $_) {
            $r = $responses[$key] ?? null;
            if ($r instanceof Response && $r->successful()) {
                $anySuccess = true;
                $val = $r->json('data.result.0.value.1');
                $results[$key] = $val !== null ? (float) $val : null;
            } else {
                $results[$key] = null;
            }
        }

        // If no response succeeded, Prometheus is unreachable via direct URL.
        // Throwing here causes runParallel's catch to fall back to the K8s API proxy.
        if (! $anySuccess) {
            throw new \RuntimeException('Direct Prometheus unreachable — falling back to K8s proxy');
        }

        return $results;
    }

    private function parseResults(array $raw, bool $quick): array
    {
        $cpu = $raw['cpu'] !== null ? round($raw['cpu'], 4) : null;
        $memory = $raw['memory'] !== null ? round($raw['memory'], 2) : null; // already MB from recording rule
        $rps = $raw['rps_kn'] ?? $raw['rps_lg'] ?? null;
        $rps = $rps !== null ? round($rps, 3) : null;

        if ($quick) {
            return compact('cpu', 'memory', 'rps');
        }

        $cpuAvg1h = $raw['cpuAvg1h'] !== null ? round($raw['cpuAvg1h'], 4) : null;
        $memAvg1h = $raw['memAvg1h'] !== null ? round($raw['memAvg1h'], 2) : null; // already MB

        return compact('cpu', 'memory', 'rps', 'cpuAvg1h', 'memAvg1h');
    }

    private function empty(): array
    {
        return ['cpu' => null, 'memory' => null, 'rps' => null];
    }
}
