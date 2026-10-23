<?php

namespace App\Services\Rightsizing;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Helpers\MonitoringEndpoint;
use App\Services\Observability\InClusterHttp;
use Illuminate\Support\Carbon;

/**
 * The peaks of a project's pods over one day, read from the region's Prometheus in one query
 * (InClusterHttp: direct on RunR's own cluster, else the Kubernetes API service proxy) and
 * evaluated at the end of the day. Per pod, from the cAdvisor metrics the region collects:
 *
 *   memory_max / memory_p95   highest / 95th percentile working set of one pod (MiB) — the
 *                             95th percentile of each pod's series, the highest of the pods
 *   cpu_max / cpu_p95         the same of the CPU use over 1-minute rates (millicores)
 *   throttled                 throttled / all CFS periods of the day (the limit held the pod back)
 *   oom_events, oom_restarts  OOM kills: cAdvisor's container OOM events, and restarts of a
 *                             container whose last termination was OOMKilled (kube-state-metrics)
 *   samples, pods             memory samples and distinct pods behind the day
 *
 * Only the project's own workload container is read — "handler" (code projects) or "app"
 * (CaaS) of the Knative Service's revision pods (<slug>-<generation>-deployment-…) or of the
 * CronJob's pods (<slug>-trigger-…) — never the queue-proxy, the queue consumer or a preview
 * (<slug>-pr-<n>-…). Pods in a Kata VM report no container of their own and give no readings.
 * Series cAdvisor reports twice (different id / image labels) are folded per pod.
 */
class ResourcePeakQuery
{
    public function __construct(private InClusterHttp $http) {}

    /**
     * The day's statistics, keyed as above (a missing statistic is null); null when the region's
     * Prometheus did not answer.
     *
     * @param  Carbon  $until  end of the day (exclusive) the query is evaluated at
     * @return array<string, float|null>|null
     */
    public function day(KnativeRegion $region, Project $project, string $triggerType, Carbon $until): ?array
    {
        $promql = self::promql(self::selector($region, $project, $triggerType));

        $response = $this->http->get($region, MonitoringEndpoint::prometheus($region), 'api/v1/query', [
            'query' => $promql,
            'time' => $until->getTimestamp(),
        ], timeout: 30);

        if (! $response || ! $response->successful() || $response->json('status') !== 'success') {
            return null;
        }

        return self::parse((array) $response->json('data.result', []));
    }

    /** Label selector of the project's workload container in the region's project namespace. */
    public static function selector(KnativeRegion $region, Project $project, string $triggerType): string
    {
        $namespace = (string) ($region->project_namespace ?? $region->name ?? 'runr');
        $slug = (string) $project->slug;
        $pod = $triggerType === 'schedule' ? $slug.'-trigger-.+' : $slug.'-[0-9]+-deployment-.+';
        $container = $project->service_type === 'caas' ? 'app' : 'handler';

        return sprintf(
            'namespace="%s",pod=~"%s",container="%s"',
            addcslashes($namespace, '\\"'),
            addcslashes($pod, '\\"'),
            $container,
        );
    }

    /** One instant query answering every statistic, each marked with its runr_stat label. */
    public static function promql(string $selector): string
    {
        $mem = "container_memory_working_set_bytes{{$selector}}";
        $cpuRate = "rate(container_cpu_usage_seconds_total{{$selector}}[1m])";
        $oomReason = "kube_pod_container_status_last_terminated_reason{{$selector},reason=\"OOMKilled\"}";

        $stats = [
            'memory_max' => "max(max by (pod) (max_over_time({$mem}[1d]))) / 1048576",
            'memory_p95' => "max(max by (pod) (quantile_over_time(0.95, {$mem}[1d]))) / 1048576",
            'cpu_max' => "max(max by (pod) (max_over_time({$cpuRate}[1d:1m]))) * 1000",
            'cpu_p95' => "max(max by (pod) (quantile_over_time(0.95, {$cpuRate}[1d:1m]))) * 1000",
            'throttled' => "sum(increase(container_cpu_cfs_throttled_periods_total{{$selector}}[1d])) / sum(increase(container_cpu_cfs_periods_total{{$selector}}[1d]))",
            'oom_events' => "sum(max by (pod) (increase(container_oom_events_total{{$selector}}[1d])))",
            'oom_restarts' => "sum(max by (namespace, pod, container) (increase(kube_pod_container_status_restarts_total{{$selector}}[1d])) * on (namespace, pod, container) (max by (namespace, pod, container) (max_over_time({$oomReason}[1d]))))",
            'samples' => "sum(max by (pod) (count_over_time({$mem}[1d])))",
            'pods' => "count(max by (pod) (count_over_time({$mem}[1d])))",
        ];

        $parts = [];
        foreach ($stats as $name => $expr) {
            $parts[] = "label_replace({$expr}, \"runr_stat\", \"{$name}\", \"\", \"\")";
        }

        return implode(' or ', $parts);
    }

    /**
     * The statistics of an instant vector result ({metric: {runr_stat}, value: [ts, "v"]});
     * NaN and infinities (0 / 0: no CFS periods) count as missing.
     *
     * @param  list<array<string, mixed>>  $result
     * @return array<string, float|null>
     */
    public static function parse(array $result): array
    {
        $stats = array_fill_keys(['memory_max', 'memory_p95', 'cpu_max', 'cpu_p95', 'throttled', 'oom_events', 'oom_restarts', 'samples', 'pods'], null);

        foreach ($result as $series) {
            $name = $series['metric']['runr_stat'] ?? null;
            $value = $series['value'][1] ?? null;
            if (! is_string($name) || ! array_key_exists($name, $stats) || ! is_numeric($value)) {
                continue;
            }
            $f = (float) $value;
            if (is_nan($f) || is_infinite($f)) {
                continue;
            }
            $stats[$name] = max($stats[$name] ?? $f, $f);
        }

        return $stats;
    }
}
