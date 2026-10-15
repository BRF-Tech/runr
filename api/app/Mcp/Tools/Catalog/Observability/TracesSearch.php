<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Support\LogLines;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class TracesSearch extends Tool
{
    public function name(): string
    {
        return 'traces_search';
    }

    public function title(): string
    {
        return 'Search traces';
    }

    public function description(): string
    {
        return 'Searches the OpenTelemetry traces of a project (the region\'s Tempo): by status (error / ok), minimum duration, request path and time range; newest first with start, duration, HTTP status, path and cold start. Then trace_get shows one trace\'s spans and logs. Needs tracing on for the project (observability_get).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'status' => Schema::enum(['all', 'error', 'ok'], 'Only failed or only successful requests (default all).'),
            'min_duration_ms' => ['type' => 'number', 'minimum' => 0, 'maximum' => 3600000, 'description' => 'Only requests at least this slow.'],
            'path' => Schema::string('Part of the request path.', ['maxLength' => 200]),
            'since' => Schema::string('Start of the range: "15m", "6h", "2d", an ISO 8601 time or unix seconds (default: an hour ago; at most 7 days).', ['maxLength' => 40]),
            'until' => Schema::string('End of the range, same forms (default now).', ['maxLength' => 40]),
            'limit' => Schema::integer('At most this many traces (default 20).', 1, 200),
        ], ['project']);
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

        $query = [
            'status' => $arguments['status'] ?? null,
            'min_duration_ms' => $arguments['min_duration_ms'] ?? null,
            'path' => $arguments['path'] ?? null,
            'limit' => (int) ($arguments['limit'] ?? 20),
        ];
        foreach (['since' => 'from', 'until' => 'to'] as $argument => $param) {
            if (isset($arguments[$argument])) {
                $ms = LogLines::sinceMs((string) $arguments[$argument]);
                if ($ms === null) {
                    throw new ToolError("{$argument}: not a time (use \"15m\", \"6h\", an ISO 8601 time or unix seconds).");
                }
                $query[$param] = intdiv($ms, 1000);
            }
        }

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'traces'), $query);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The traces could not be searched');
        }

        $traces = array_map(fn (array $t) => array_filter([
            'trace_id' => $t['trace_id'] ?? null,
            'start' => $t['start'] ?? null,
            'duration_ms' => $t['duration_ms'] ?? null,
            'status_code' => $t['status_code'] ?? null,
            'error' => $t['error'] ?? null,
            'path' => $t['path'] ?? null,
            'cold_start' => $t['cold_start'] ?? null,
            'root_service' => $t['root_service'] ?? null,
            'root_name' => $t['root_name'] ?? null,
        ], fn ($v) => $v !== null), array_values(array_filter((array) $result->get('data.traces', []), 'is_array')));

        return ToolResult::ok([
            'traces' => $traces,
            'count' => count($traces),
            'from' => $result->get('data.from'),
            'to' => $result->get('data.to'),
        ], count($traces).' trace(s).');
    }
}
