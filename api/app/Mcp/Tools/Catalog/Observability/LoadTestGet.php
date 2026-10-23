<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ReadsLoadTests;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class LoadTestGet extends Tool
{
    use ReadsLoadTests;

    public function name(): string
    {
        return 'load_test_get';
    }

    public function title(): string
    {
        return 'Read load test results';
    }

    public function description(): string
    {
        return 'Reads a load test of a project — its status and results (p50 / p95 / p99 latency, error rate, requests per second, pods at peak, cold starts) — together with the last five runs side by side, so a change can be compared with the runs before it. Without load_test it reads the latest run. Also says what a new test can choose and how many tests the organization has left today.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'load_test' => Schema::string('Id of the test (default: the latest).', ['minLength' => 26, 'maxLength' => 26]),
        ], ['project']);
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
        $list = $this->loadTests($context, $projectId, 5);
        $recent = array_map([self::class, 'loadTestSummary'], $list['tests']);
        $meta = $list['meta'];
        $limits = array_filter([
            'users' => $meta['users'] ?? null,
            'durations' => $meta['durations'] ?? null,
            'targets' => $meta['targets'] ?? null,
            'daily_limit' => $meta['daily_limit'] ?? null,
            'used_today' => $meta['used_today'] ?? null,
        ], fn ($v) => $v !== null);

        $id = isset($arguments['load_test']) ? strtolower((string) $arguments['load_test']) : null;
        $test = $id !== null ? self::loadTestSummary($this->loadTest($context, $projectId, $id)) : ($recent[0] ?? null);

        if ($test === null) {
            return ToolResult::ok(['load_test' => null, 'recent' => [], 'limits' => $limits], 'The project has no load test yet; load_test_run starts one.');
        }

        return ToolResult::ok(['load_test' => $test, 'recent' => $recent, 'limits' => $limits], self::loadTestLine($test));
    }
}
