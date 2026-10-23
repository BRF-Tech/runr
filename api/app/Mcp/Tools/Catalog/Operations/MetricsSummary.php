<?php

namespace App\Mcp\Tools\Catalog\Operations;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * metrics_summary: GET /api/projects/{project}/metrics/summary with the units of its numbers — the
 * recorded samples are what the pods used (cores, MiB, requests per second), the sessions' CPU and
 * memory totals are the deployment's size × the sessions' pod time, as metering counts them.
 */
class MetricsSummary extends Tool
{
    /** What each number of the API's answer is measured in (structured "units") */
    public const UNITS = [
        'metrics' => [
            'avg_cpu' => 'cores the pods used, average of the samples', 'max_cpu' => 'cores, highest sample',
            'avg_memory' => 'MiB the pods used, average of the samples', 'max_memory' => 'MiB, highest sample',
            'avg_rps' => 'requests per second, average', 'max_rps' => 'requests per second, highest sample',
        ],
        'sessions' => [
            'total_duration_ms' => 'milliseconds the sessions ran',
            'avg_duration_ms' => 'milliseconds per session',
            'total_cpu_ms' => 'vCPU milliseconds: the deployment\'s size × the sessions\' pod time (1 vCPU for 1 s = 1000), not what the pods used',
            'total_memory_mb_ms' => 'MiB × milliseconds: the deployment\'s memory size × the sessions\' pod time',
        ],
    ];

    public function name(): string
    {
        return 'metrics_summary';
    }

    public function title(): string
    {
        return 'Metrics summary';
    }

    public function description(): string
    {
        return 'Summarises a project\'s recorded metrics over the last 7, 30 or 90 days: CPU (cores) and memory (MiB) the pods used, requests per second, and the pod sessions — their number, cold starts, run time and the deployment\'s size × their pod time (total_cpu_ms in vCPU milliseconds, total_memory_mb_ms in MiB × ms, as metering counts them). The answer\'s "units" names each field\'s unit. Use it to size a project (project_update) or to spot load changes.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'period' => Schema::enum(['7d', '30d', '90d'], 'Time window (default 7d).', '7d'),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['metrics.view'];
    }

    public function group(): string
    {
        return 'operations';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    /** "Last 7d: 0.250 cores, 192 MiB and 4.00 requests/s on average; 40 sessions at 0.75 vCPU-hours and 1.50 GiB-hours of the deployment's size." */
    private static function summary(string $period, array $metrics, array $sessions): string
    {
        $text = "Metrics of the last {$period}";
        if (isset($metrics['avg_cpu']) || isset($metrics['avg_memory'])) {
            $text .= sprintf(': %s cores, %s MiB and %s requests/s on average',
                number_format((float) ($metrics['avg_cpu'] ?? 0), 3, '.', ''), number_format((float) ($metrics['avg_memory'] ?? 0), 0, '.', ''),
                number_format((float) ($metrics['avg_rps'] ?? 0), 2, '.', ''));
        }
        $count = (int) ($sessions['total_sessions'] ?? 0);
        if ($count > 0) {
            $text .= sprintf('; %d session(s) at %s vCPU-hours and %s GiB-hours of the deployment\'s size',
                $count, number_format((float) ($sessions['total_cpu_ms'] ?? 0) / 3_600_000, 2, '.', ''),
                number_format((float) ($sessions['total_memory_mb_ms'] ?? 0) / 1024 / 3_600_000, 2, '.', ''));
        }

        return $text.'.';
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $period = (string) ($arguments['period'] ?? '7d');

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'metrics', 'summary'), ['period' => $period]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the metrics');
        }

        $metrics = (array) ($result->get('metrics') ?? []);
        $sessions = (array) ($result->get('sessions') ?? []);

        return ToolResult::ok([
            'period' => $period,
            'metrics' => $result->get('metrics'),
            'sessions' => $result->get('sessions'),
            'units' => self::UNITS,
        ], self::summary($period, $metrics, $sessions));
    }
}
