<?php

namespace App\Services\Observability\Tracing;

use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Services\Helpers\ManagedTracing;
use App\Services\Helpers\MonitoringEndpoint;
use App\Services\Observability\InClusterHttp;

/**
 * Tempo's HTTP API of a region, server-side only (InClusterHttp): RunR's Tempo or a reused
 * in-cluster one (tempo_url, tempo_org_id as X-Scope-OrgID).
 */
class TempoClient
{
    public function __construct(private InClusterHttp $http) {}

    public function endpoint(RegionTracingConfig $config): MonitoringEndpoint
    {
        return MonitoringEndpoint::parse($config->tempo_url)
            ?? MonitoringEndpoint::parse(ManagedTracing::managedTempoUrl());
    }

    /**
     * TraceQL search: GET /api/search?q=…&start=…&end=…&limit=…&spss=…
     *
     * @throws TempoUnavailableException
     */
    public function search(KnativeRegion $region, RegionTracingConfig $config, string $traceql, int $start, int $end, int $limit): array
    {
        $response = $this->http->get($region, $this->endpoint($config), 'api/search', [
            'q' => $traceql,
            'start' => $start,
            'end' => $end,
            'limit' => $limit,
            'spss' => 3,
        ], $this->headers($config), (int) config('observability.tracing.query_timeout', 10));

        if (! $response || ! $response->successful()) {
            throw new TempoUnavailableException($response ? 'Tempo answered '.$response->status().': '.mb_substr($response->body(), 0, 300) : 'Tempo unreachable');
        }

        return (array) $response->json();
    }

    /**
     * One trace (OTLP JSON); null when Tempo does not know it.
     *
     * @throws TempoUnavailableException
     */
    public function trace(KnativeRegion $region, RegionTracingConfig $config, string $traceId): ?array
    {
        $response = $this->http->get($region, $this->endpoint($config), 'api/traces/'.$traceId, [], $this->headers($config), (int) config('observability.tracing.query_timeout', 10));

        if ($response && $response->status() === 404) {
            return null;
        }
        if (! $response || ! $response->successful()) {
            throw new TempoUnavailableException($response ? 'Tempo answered '.$response->status() : 'Tempo unreachable');
        }

        return (array) $response->json();
    }

    private function headers(RegionTracingConfig $config): array
    {
        return $config->tempo_org_id ? ['X-Scope-OrgID' => $config->tempo_org_id] : [];
    }
}
