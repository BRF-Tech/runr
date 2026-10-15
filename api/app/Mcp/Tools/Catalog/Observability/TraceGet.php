<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class TraceGet extends Tool
{
    /** Span attributes worth showing to a model (the rest stays in the console). */
    private const KEY_ATTRIBUTES = [
        'http.request.method', 'http.method', 'http.response.status_code', 'http.status_code', 'url.path', 'http.target',
        'http.route', 'url.full', 'server.address', 'db.system', 'db.statement', 'db.operation', 'messaging.system',
        'rpc.method', 'runr.cold_start', 'exception.type', 'exception.message', 'error.type',
    ];

    public function name(): string
    {
        return 'trace_get';
    }

    public function title(): string
    {
        return 'Read a trace';
    }

    public function description(): string
    {
        return 'One trace of a project as a span tree (depth, service, name, kind, duration, start offset, status, key attributes, exception events) — where the time went and which span failed — and, with include_logs (needs logs.view), the log lines that carry the trace id.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'trace_id' => Schema::string('The trace id (32 hex characters, traces_search shows them).', ['pattern' => '^[0-9a-fA-F]{32}$']),
            'include_logs' => Schema::boolean('Add the log lines of the trace (default false).', false),
            'max_spans' => Schema::integer('At most this many spans (default 100).', 1, 1000),
        ], ['project', 'trace_id']);
    }

    public function permissions(): array
    {
        return ['traces.view'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $traceId = strtolower((string) $arguments['trace_id']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'traces', $traceId));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The trace could not be read');
        }

        $trace = (array) $result->data();
        $max = (int) ($arguments['max_spans'] ?? 100);
        $spans = array_values(array_filter((array) ($trace['spans'] ?? []), 'is_array'));

        $out = [
            'trace_id' => $trace['trace_id'] ?? $traceId,
            'start' => $trace['start'] ?? null,
            'duration_ms' => $trace['duration_ms'] ?? null,
            'span_count' => $trace['span_count'] ?? count($spans),
            'error_count' => $trace['error_count'] ?? null,
            'services' => $trace['services'] ?? [],
            'spans' => array_map([self::class, 'span'], array_slice($spans, 0, $max)),
            'truncated' => count($spans) > $max,
        ];

        if (! empty($arguments['include_logs'])) {
            $logs = $context->api()->get(InternalApi::path('projects', $projectId, 'traces', $traceId, 'logs'));
            if ($logs->ok()) {
                $out['logs'] = array_map(fn (array $l) => array_filter([
                    'ts' => isset($l['ts_ms']) ? gmdate('Y-m-d\TH:i:s', intdiv((int) $l['ts_ms'], 1000)).sprintf('.%03dZ', (int) $l['ts_ms'] % 1000) : null,
                    'pod' => $l['pod'] ?? null,
                    'line' => $l['line'] ?? null,
                ], fn ($v) => $v !== null), array_slice(array_values(array_filter((array) $logs->data(), 'is_array')), 0, (int) config('mcp.tools.max_log_lines', 500)));
            } else {
                $out['logs_error'] = $logs->errorText();
            }
        }

        return ToolResult::ok($out, sprintf(
            'Trace %s: %s span(s), %s error(s), %s ms.',
            $out['trace_id'],
            $out['span_count'],
            $out['error_count'] ?? 0,
            $out['duration_ms'] ?? '?',
        ));
    }

    /** One span, without raw ids beyond its own and its parent's, and only key attributes. */
    private static function span(array $span): array
    {
        $attributes = array_intersect_key((array) ($span['attributes'] ?? []), array_flip(self::KEY_ATTRIBUTES));
        $events = array_values(array_filter(array_map(fn ($e) => is_array($e) ? array_filter([
            'name' => $e['name'] ?? null,
            'offset_ms' => $e['offset_ms'] ?? null,
            'message' => $e['attributes']['exception.message'] ?? null,
            'type' => $e['attributes']['exception.type'] ?? null,
        ], fn ($v) => $v !== null) : null, (array) ($span['events'] ?? []))));

        return array_filter([
            'span_id' => $span['span_id'] ?? null,
            'parent_span_id' => $span['parent_span_id'] ?? null,
            'depth' => $span['depth'] ?? 0,
            'service' => $span['service'] ?? null,
            'name' => $span['name'] ?? null,
            'kind' => $span['kind'] ?? null,
            'start_offset_ms' => $span['start_offset_ms'] ?? null,
            'duration_ms' => $span['duration_ms'] ?? null,
            'status' => ($span['status']['code'] ?? 'unset') === 'unset' ? null : ($span['status']['code'] ?? null),
            'status_message' => $span['status']['message'] ?? null,
            'attributes' => $attributes ?: null,
            'events' => $events ?: null,
        ], fn ($v) => $v !== null);
    }
}
