<?php

namespace App\Services\Observability\Tracing;

use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Services\Helpers\MonitoringEndpoint;
use App\Services\Observability\InClusterHttp;

/**
 * The Traces tab of a project: TraceQL search scoped to the project (resource attribute
 * runr.project_id, set on every span RunR's instrumentation emits), trace detail — only when the
 * trace holds a span of this project, so a trace id of another tenant reveals nothing — and the
 * project's log lines that carry the trace id (Loki).
 */
class TraceQueryService
{
    public const STATUSES = ['all', 'error', 'ok'];

    public function __construct(
        private TempoClient $tempo,
        private InClusterHttp $http,
    ) {}

    /**
     * @param  array{status?: string, min_duration_ms?: int|float|null, path?: string|null, from?: int|null, to?: int|null, limit?: int|null}  $filters
     *
     * @throws TracingNotQueryableException|TempoUnavailableException
     */
    public function search(Project $project, array $filters): array
    {
        [$region, $config] = $this->backend($project);

        $to = (int) ($filters['to'] ?? now()->timestamp);
        $maxSpan = max(1, (int) config('observability.tracing.search_max_hours', 168)) * 3600;
        $from = (int) ($filters['from'] ?? $to - 3600);
        $from = max($from, $to - $maxSpan);
        $limit = min(max(1, (int) ($filters['limit'] ?? config('observability.tracing.search_limit', 50))), 200);

        $traceql = self::traceQl($project->getKey(), $filters);
        $response = $this->tempo->search($region, $config, $traceql, $from, $to, $limit);

        return [
            'traceql' => $traceql,
            'from' => $from,
            'to' => $to,
            'traces' => TraceNormalizer::searchResults($response),
        ];
    }

    /**
     * @throws TracingNotQueryableException|TempoUnavailableException
     */
    public function show(Project $project, string $traceId): ?array
    {
        [$region, $config] = $this->backend($project);

        $raw = $this->tempo->trace($region, $config, strtolower($traceId));
        $trace = $raw ? TraceNormalizer::trace($raw) : null;

        if (! $trace || ! self::belongsTo($trace, $project->getKey())) {
            return null;
        }

        return $trace;
    }

    /**
     * Log lines of the project's pods containing the trace id, around the trace's time.
     *
     * @return list<array{ts_ms: int, pod: string, line: string}>
     *
     * @throws TracingNotQueryableException|TempoUnavailableException
     */
    public function logs(Project $project, string $traceId): ?array
    {
        $trace = $this->show($project, $traceId);
        if (! $trace) {
            return null;
        }

        $region = $project->knativeRegion;
        $ns = $region->project_namespace ?? $region->name ?? 'runr';
        $query = sprintf('{namespace="%s", pod=~"%s-.*"} |= "%s"', $ns, $project->slug, strtolower($traceId));

        $startMs = $trace['start_ms'] - 60_000;
        $endMs = $trace['end_ms'] + 300_000;

        $headers = $region->loki_org_id ? ['X-Scope-OrgID' => $region->loki_org_id] : [];
        $response = $this->http->get($region, MonitoringEndpoint::loki($region), 'loki/api/v1/query_range', [
            'query' => $query,
            'start' => $startMs * 1_000_000,
            'end' => $endMs * 1_000_000,
            'limit' => 500,
            'direction' => 'forward',
        ], $headers);

        if (! $response || ! $response->successful()) {
            return [];
        }

        $lines = [];
        foreach ((array) $response->json('data.result', []) as $stream) {
            $pod = $stream['stream']['pod'] ?? 'unknown';
            foreach ($stream['values'] ?? [] as [$tsNs, $text]) {
                $lines[] = ['ts_ms' => intdiv((int) $tsNs, 1_000_000), 'pod' => $pod, 'container' => $stream['stream']['container'] ?? null, 'line' => (string) $text];
            }
        }
        usort($lines, fn ($a, $b) => $a['ts_ms'] <=> $b['ts_ms']);

        return $lines;
    }

    /**
     * TraceQL of the list filters. Conditions are on the same (server) span: the project, then
     * status (error / ok), minimum duration and a path substring.
     */
    public static function traceQl(string $projectId, array $filters): string
    {
        $conds = [
            'resource.runr.project_id = '.self::str($projectId),
            'kind = server',
        ];

        $status = $filters['status'] ?? 'all';
        if ($status === 'error') {
            $conds[] = 'status = error';
        } elseif ($status === 'ok') {
            $conds[] = 'status != error';
        }

        $min = $filters['min_duration_ms'] ?? null;
        if ($min !== null && $min !== '' && (float) $min > 0) {
            $conds[] = 'duration >= '.rtrim(rtrim(number_format((float) $min, 3, '.', ''), '0'), '.').'ms';
        }

        $path = trim((string) ($filters['path'] ?? ''));
        if ($path !== '') {
            $re = self::str('.*'.self::regexQuote($path).'.*');
            $conds[] = "(span.url.path =~ {$re} || span.http.target =~ {$re})";
        }

        return '{ '.implode(' && ', $conds).' } | select(span.http.response.status_code, span.http.status_code, span.url.path, span.http.target, span.runr.cold_start)';
    }

    /** Does the trace hold a span of the project (resource runr.project_id)? */
    public static function belongsTo(array $trace, string $projectId): bool
    {
        foreach ($trace['spans'] as $span) {
            if ((string) ($span['resource']['runr.project_id'] ?? '') === $projectId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: KnativeRegion, 1: RegionTracingConfig}
     *
     * @throws TracingNotQueryableException
     */
    private function backend(Project $project): array
    {
        $region = $project->knativeRegion;
        if (! $region) {
            throw new TracingNotQueryableException(__('observability.tracing.no_region'));
        }

        $config = RegionTracingConfig::forRegion($region);
        if ($config->mode === 'external') {
            throw new TracingNotQueryableException(__('observability.tracing.external_backend', ['endpoint' => $config->export_endpoint]));
        }
        if (! $config->isQueryable()) {
            throw new TracingNotQueryableException(__('observability.tracing.not_ready'));
        }

        return [$region, $config];
    }

    /** TraceQL string literal. */
    private static function str(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /** RE2 metacharacters escaped. */
    private static function regexQuote(string $value): string
    {
        return preg_replace('/[.\\\\+*?\[^\]$(){}|]/', '\\\\$0', $value);
    }
}
