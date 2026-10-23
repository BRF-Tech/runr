<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiUsageGet extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_usage';
    }

    public function title(): string
    {
        return 'API usage';
    }

    public function description(): string
    {
        return 'API usage of the gateways: requests let through and refused (unauthorized, forbidden, rate limited, over quota), and what the functions answered to the calls let through (responses, status_2xx/3xx/4xx/5xx, upstream_errors, errors, latency_avg_ms, latency_p50_ms, latency_p95_ms, latency_p99_ms, bytes_in, bytes_out), grouped by hour, day or month, or by consumer, key or route. Default: the last 7 days by day. Times are UTC.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'gateway' => Schema::string('Only this gateway (id).', ['minLength' => 26, 'maxLength' => 26]),
            'consumer' => Schema::string('Only this consumer (id).', ['minLength' => 26, 'maxLength' => 26]),
            'key' => Schema::string('Only this key (id).', ['minLength' => 26, 'maxLength' => 26]),
            'route' => Schema::string('Only this route (id).', ['minLength' => 26, 'maxLength' => 26]),
            'from' => Schema::string('Start, ISO 8601 (default 7 days ago).', ['maxLength' => 40]),
            'to' => Schema::string('End, ISO 8601 (default the end of this hour).', ['maxLength' => 40]),
            'group_by' => Schema::enum(['hour', 'day', 'month', 'consumer', 'key', 'route'], 'Grouping.', 'day'),
        ]);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'gateways';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/api-usage', self::compact([
            'gateway_id' => $arguments['gateway'] ?? null,
            'consumer_id' => $arguments['consumer'] ?? null,
            'key_id' => $arguments['key'] ?? null,
            'route_id' => $arguments['route'] ?? null,
            'from' => $arguments['from'] ?? null,
            'to' => $arguments['to'] ?? null,
            'group_by' => $arguments['group_by'] ?? null,
        ]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The usage could not be read');
        }

        $json = (array) $result->json;
        $totals = (array) ($json['totals'] ?? []);
        $summary = sprintf('%d request(s) let through, %d refused.',
            (int) ($totals['requests'] ?? 0),
            (int) (($totals['unauthorized'] ?? 0) + ($totals['forbidden'] ?? 0) + ($totals['rate_limited'] ?? 0) + ($totals['quota_exceeded'] ?? 0)),
        );
        if ((int) ($totals['responses'] ?? 0) > 0) {
            $summary .= sprintf(' The functions answered %d: %d error(s), latency p50 %s ms, p95 %s ms, p99 %s ms.',
                (int) $totals['responses'], (int) ($totals['errors'] ?? 0),
                $totals['latency_p50_ms'] ?? '-', $totals['latency_p95_ms'] ?? '-', $totals['latency_p99_ms'] ?? '-');
        }

        return ToolResult::ok([
            'from' => $json['from'] ?? null,
            'to' => $json['to'] ?? null,
            'group_by' => $json['group_by'] ?? null,
            'totals' => $json['totals'] ?? null,
            'data' => $json['data'] ?? [],
        ], $summary);
    }
}
