<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Load tests of a project (docs/load-test.md): the list with what a new test can choose, one test,
 * waiting for a test to end, and the concise form of a test and its results.
 */
trait ReadsLoadTests
{
    /** States a load test ends in. */
    protected static array $finalLoadTestStates = ['completed', 'failed', 'cancelled'];

    /**
     * @return array{tests: list<array>, meta: array}
     */
    protected function loadTests(ToolContext $context, string $projectId, int $limit = 5): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'load-tests'), ['limit' => $limit]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the load tests');
        }

        return [
            'tests' => array_values(array_filter((array) $result->data(), 'is_array')),
            'meta' => (array) $result->get('meta', []),
        ];
    }

    protected function loadTest(ToolContext $context, string $projectId, string $id): array
    {
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'load-tests', $id));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the load test');
        }

        return (array) $result->data();
    }

    /**
     * Polls the test until it ends or $deadline passes, with progress notifications.
     */
    protected function waitForLoadTest(ToolContext $context, string $projectId, array $test, int $deadline): array
    {
        $step = 0;
        while (! in_array($test['status'] ?? null, self::$finalLoadTestStates, true)) {
            $context->progress(++$step, null, sprintf('Load test %s: %s (%d users, %d s)…', $test['id'] ?? '?', $test['status'] ?? 'pending', (int) ($test['users'] ?? 0), (int) ($test['duration_seconds'] ?? 0)));
            if (time() >= $deadline || ! $context->pause()) {
                break;
            }
            $test = $this->loadTest($context, $projectId, (string) $test['id']);
        }

        return $test;
    }

    protected static function loadTestSummary(array $test): array
    {
        $results = (array) ($test['results'] ?? []);
        $latency = (array) ($results['latency_ms'] ?? []);

        return array_filter([
            'id' => $test['id'] ?? null,
            'status' => $test['status'] ?? null,
            'request' => trim(($test['method'] ?? 'GET').' '.($test['url'] ?? '')),
            'users' => $test['users'] ?? null,
            'duration_seconds' => $test['duration_seconds'] ?? null,
            'requests' => $results['requests'] ?? null,
            'rps' => $results['rps'] ?? null,
            'error_rate_percent' => isset($results['error_rate']) ? round(((float) $results['error_rate']) * 100, 2) : null,
            'latency_ms' => $latency !== [] ? array_filter([
                'p50' => $latency['p50'] ?? null,
                'p95' => $latency['p95'] ?? null,
                'p99' => $latency['p99'] ?? null,
                'max' => $latency['max'] ?? null,
            ], fn ($v) => $v !== null) : null,
            'pods_peak' => $results['pods_peak'] ?? null,
            'cold_starts' => $results['cold_starts'] ?? null,
            'error' => $test['error'] ?? null,
            'started_at' => $test['started_at'] ?? null,
            'finished_at' => $test['finished_at'] ?? null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
    }

    /** One line of a finished test for the text answer. */
    protected static function loadTestLine(array $summary): string
    {
        if (($summary['status'] ?? null) !== 'completed') {
            return 'Load test '.($summary['status'] ?? 'pending').(isset($summary['error']) ? ': '.$summary['error'] : '.');
        }

        return sprintf(
            'Load test completed: p50 %s ms, p95 %s ms, p99 %s ms, %s requests/s, %s %% errors, %s pod(s) at peak, %s cold start(s).',
            $summary['latency_ms']['p50'] ?? '—',
            $summary['latency_ms']['p95'] ?? '—',
            $summary['latency_ms']['p99'] ?? '—',
            $summary['rps'] ?? '—',
            $summary['error_rate_percent'] ?? '—',
            $summary['pods_peak'] ?? '—',
            $summary['cold_starts'] ?? '—',
        );
    }
}
