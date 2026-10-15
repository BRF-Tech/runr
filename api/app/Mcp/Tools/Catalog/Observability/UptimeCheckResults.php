<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsUptime;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class UptimeCheckResults extends Tool
{
    use ReadsUptime;

    public function name(): string
    {
        return 'uptime_check_results';
    }

    public function title(): string
    {
        return 'Uptime check results';
    }

    public function description(): string
    {
        return 'Results of one uptime check: uptime over 24 h / 7 d / 30 d, the average response time, the latest probes (status, response time, error, location) and its incidents.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'check' => Schema::string('The check: its id or exact name.', ['minLength' => 1, 'maxLength' => 100]),
            'hours' => Schema::integer('Results of the last hours (default 24).', 1, 720),
            'location' => Schema::string('Only results of this location (control_plane or a region id).', ['maxLength' => 64]),
            'limit' => Schema::integer('At most this many results (default 50, newest first).', 1, 2000),
        ], ['project', 'check']);
    }

    public function permissions(): array
    {
        return ['metrics.view'];
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
        $check = $this->findUptimeCheck($context, $projectId, (string) $arguments['check']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'uptime-checks', (string) $check['id'], 'results'), [
            'hours' => $arguments['hours'] ?? null,
            'location' => $arguments['location'] ?? null,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the uptime results');
        }

        $data = (array) $result->data();
        $results = array_slice(array_values(array_filter((array) ($data['results'] ?? []), 'is_array')), 0, (int) ($arguments['limit'] ?? 50));
        $failed = count(array_filter($results, fn ($r) => empty($r['ok'])));

        return ToolResult::ok([
            'check' => self::uptimeSummary((array) ($data['check'] ?? $check)),
            'uptime' => $data['uptime'] ?? null,
            'avg_response_ms_24h' => $data['avg_response_ms_24h'] ?? null,
            'locations' => $data['locations'] ?? [],
            'results' => $results,
            'incidents' => $data['incidents'] ?? [],
        ], count($results)." result(s), {$failed} failed.");
    }
}
