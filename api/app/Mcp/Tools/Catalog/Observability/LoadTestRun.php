<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsLoadTests;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class LoadTestRun extends Tool
{
    use ReadsLoadTests;

    public function name(): string
    {
        return 'load_test_run';
    }

    public function title(): string
    {
        return 'Run a load test';
    }

    public function description(): string
    {
        return 'Runs a load test of an HTTP project from inside its region: 10, 50 or 100 concurrent users send one request (GET, or POST with a small body) to a path of the project\'s own URL for 30, 60 or 300 seconds. Waits for the end by default and returns p50 / p95 / p99 latency, error rate, requests per second, the pods the project scaled to and its cold starts. The test traffic is metered and billed like any other traffic of the project; one test per project at a time, and an organization has a daily limit. load_test_get compares it with earlier runs.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'path' => Schema::string('Path (and query) on the project\'s URL, e.g. /api/orders?page=1 (default /).', ['maxLength' => 1024, 'pattern' => '^/']),
            'method' => Schema::enum(['GET', 'POST'], 'HTTP method (default GET).'),
            'body' => Schema::string('Request body of a POST (at most a few KiB).', ['maxLength' => 65536]),
            'content_type' => Schema::string('Content-Type of the body (default application/json).', ['maxLength' => 100]),
            'users' => Schema::enum([10, 50, 100], 'Concurrent users.'),
            'duration_seconds' => Schema::enum([30, 60, 300], 'How long the test runs, in seconds.'),
            'host' => Schema::string('The project\'s host to test: its function host (default) or one of its active custom domains.', ['maxLength' => 253]),
            'wait' => Schema::boolean('Wait until the test has ended (default true).', true),
        ], ['project', 'users', 'duration_seconds']);
    }

    public function permissions(): array
    {
        return ['projects.deploy'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function streams(): bool
    {
        return true;
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $wait = (bool) ($arguments['wait'] ?? true);

        $body = array_filter([
            'host' => $arguments['host'] ?? null,
            'path' => $arguments['path'] ?? null,
            'method' => $arguments['method'] ?? null,
            'body' => $arguments['body'] ?? null,
            'content_type' => $arguments['content_type'] ?? null,
        ], fn ($v) => $v !== null && $v !== '') + [
            'users' => (int) $arguments['users'],
            'duration_seconds' => (int) $arguments['duration_seconds'],
        ];

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'load-tests'), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The load test did not start');
        }
        $test = (array) $result->data();
        $context->setTarget('load_test', (string) ($test['id'] ?? ''));

        if ($wait) {
            // the test's own length, the start of its Job and the collection of the results
            $deadline = time() + $context->maxWait((int) $body['duration_seconds'] + 240);
            $test = $this->waitForLoadTest($context, $projectId, $test, $deadline);
        }

        $summary = self::loadTestSummary($test);
        if (($test['status'] ?? null) === 'failed') {
            return ToolResult::error('The load test failed: '.($test['error'] ?? 'no details'), ['load_test' => $summary]);
        }

        if (! in_array($test['status'] ?? null, self::$finalLoadTestStates, true)) {
            return ToolResult::ok(['load_test' => $summary], 'The load test is '.($test['status'] ?? 'pending').'; load_test_get follows it.');
        }

        return ToolResult::ok(['load_test' => $summary], self::loadTestLine($summary));
    }
}
