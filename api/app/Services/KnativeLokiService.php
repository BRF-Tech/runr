<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Helpers\MonitoringEndpoint;
use App\Services\Helpers\RunrClusterIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Queries Loki for deployment logs using LogQL.
 *
 * The region's Loki is the in-cluster Service in loki_url (RunR's loki-stack by default,
 * MonitoringEndpoint). Access strategy (fastest → slowest):
 *  1. Direct HTTP to the Service — only when the region is RunR's own cluster — <500ms
 *  2. K8s API service proxy of the region's cluster                          — ~2s
 *  3. Caller falls back to kubectl logs (also when Loki has no lines yet)     — ~5-10s
 * loki_org_id (optional) is sent as X-Scope-OrgID.
 */
class KnativeLokiService
{
    private const LOG_LIMIT = 2000;

    private const TIMEOUT_S = 8;

    private const LOOKBACK_S = 86400; // 24h default window

    private string $userContainer = 'handler';

    /**
     * Query Loki for logs. Tries direct URL first, then K8s proxy.
     * Returns null when both fail (triggers kubectl fallback in caller).
     * On success returns ['logs' => string, 'last_ts_ms' => int|null].
     *
     * @param  int|null  $sinceMs  Unix ms — fetch only logs after this point.
     *                             Null → fetch last 24h (first load).
     */
    public function queryLogs(
        KnativeRegion $region,
        Project $project,
        Deployment $deployment,
        KubernetesClient $k8s,
        ?int $sinceMs = null,
    ): ?array {
        $this->userContainer = $project->service_type === 'caas' ? 'app' : 'handler';
        $ns = $region->project_namespace ?? $region->name ?? 'runr';
        $slug = $project->slug;
        $query = "{namespace=\"{$ns}\",pod=~\"{$slug}-.*\"}";

        // +1ms to exclude the last already-seen log entry
        $startNs = $sinceMs
            ? (($sinceMs + 1) * 1_000_000)
            : (now()->timestamp - self::LOOKBACK_S).'000000000';

        $endNs = (string) ((int) (microtime(true) * 1_000_000_000));

        $params = [
            'query' => $query,
            'limit' => self::LOG_LIMIT,
            'start' => (string) $startNs,
            'end' => $endNs,
            'direction' => 'forward',
        ];

        $endpoint = MonitoringEndpoint::loki($region);
        // Multi-tenant Loki (operator-supplied): the tenant whose streams hold this cluster's logs
        $headers = $region->loki_org_id ? ['X-Scope-OrgID' => $region->loki_org_id] : [];

        // 1. Direct HTTP — only from RunR's own cluster: elsewhere the in-cluster name resolves
        //    inside RunR's cluster and would read a different Loki
        if ($this->isRunrCluster($region)) {
            $streams = $this->queryDirect($endpoint->directUrl('loki/api/v1/query_range'), $params, $headers);
            if ($streams !== null) {
                return $this->formatStreams($streams);
            }
        }

        // 2. K8s API service proxy of the region's cluster
        $streams = $this->queryProxy($k8s, $endpoint, $params, $headers);
        if ($streams !== null) {
            return $this->formatStreams($streams);
        }

        return null;
    }

    // ── Transport ────────────────────────────────────────────────────────────

    private function isRunrCluster(KnativeRegion $region): bool
    {
        try {
            return app(RunrClusterIdentity::class)->isRunrCluster($region);
        } catch (\Throwable) {
            return false;
        }
    }

    private function queryDirect(string $url, array $params, array $headers): ?array
    {
        try {
            $r = Http::timeout(self::TIMEOUT_S)->withHeaders($headers)->get($url, $params);

            return $r->successful() ? $r->json('data.result', []) : null;
        } catch (\Throwable $e) {
            Log::warning('Loki direct failed — trying K8s proxy', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function queryProxy(KubernetesClient $k8s, MonitoringEndpoint $endpoint, array $params, array $headers): ?array
    {
        try {
            $r = $k8s->serviceProxyGet(
                $endpoint->namespace, $endpoint->service, $endpoint->port,
                $endpoint->proxyPath('loki/api/v1/query_range'), $params, $headers, $endpoint->scheme,
            );

            return $r?->json('data.result') ?? null;
        } catch (\Throwable $e) {
            Log::warning('Loki K8s proxy failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    // ── Formatting ───────────────────────────────────────────────────────────

    /**
     * Format Loki streams into output lines.
     * Each line is prefixed with the unix millisecond timestamp so the frontend
     * can display accurate log times and use the last timestamp as the next
     * incremental cursor (eliminating client-clock drift gaps).
     *
     * Format: "<unix_ms>\t<log_text>\n"
     * Pod headers: "=== <pod_name> ===\n"
     *
     * Returns ['logs' => string, 'last_ts_ms' => int|null].
     */
    private function formatStreams(array $streams): array
    {
        if (empty($streams)) {
            return ['logs' => '', 'last_ts_ms' => null];
        }

        $lines = [];
        $seen = [];           // dedup key: "ms|text" — Promtail can emit the same entry in multiple streams
        foreach ($streams as $stream) {
            $labels = $stream['stream'] ?? [];
            // Filter to user container only — skip queue-proxy sidecar.
            // Promtail may use 'container', 'container_name', or 'app' label.
            $containerLabel = $labels['container'] ?? $labels['container_name'] ?? $labels['app'] ?? '';
            if ($containerLabel && $containerLabel !== $this->userContainer) {
                continue;
            }
            $pod = $labels['pod'] ?? 'unknown';
            foreach ($stream['values'] as [$nsTs, $text]) {
                $ms = (int) ($nsTs / 1_000_000);
                $key = $ms.'|'.$text;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $lines[] = [$ms, $pod, $text];
            }
        }
        usort($lines, fn ($a, $b) => $a[0] <=> $b[0]);

        $output = '';
        $currentPod = null;
        $lastMs = null;

        foreach ($lines as [$ms, $pod, $text]) {
            if ($pod !== $currentPod) {
                $output .= "=== {$pod} ===\n";
                $currentPod = $pod;
            }
            $output .= $ms."\t".$text."\n";
            $lastMs = $ms;
        }

        return ['logs' => $output, 'last_ts_ms' => $lastMs];
    }
}
