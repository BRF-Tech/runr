<?php

namespace App\Mcp\Tools\Catalog\Operations;

use App\Mcp\Server\InternalApi;
use App\Mcp\Support\LogLines;
use App\Mcp\Tools\Concerns\ReadsDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class LogsTail extends Tool
{
    use ReadsDeployments;

    public function name(): string
    {
        return 'logs_tail';
    }

    public function title(): string
    {
        return 'Tail logs';
    }

    public function description(): string
    {
        return 'The newest log lines of a deployment (default: the current one) — the function\'s own output and, for hosted MCP servers, the gateway\'s call log. Filter by time (since: "15m", "2h", an ISO time or unix milliseconds), a substring, or error lines only. Pass last_ts_ms back as since to read only newer lines.';
    }

    public function inputSchema(): array
    {
        $max = max(1, (int) config('mcp.tools.max_log_lines', 500));

        return Schema::object([
            'project' => Schema::project(),
            'deployment' => Schema::string('Deployment id; default the current deployment.', ['maxLength' => 64]),
            'lines' => Schema::integer("Newest lines to return (default 100, at most {$max}).", 1, $max),
            'since' => Schema::string('Only lines after this time: "15m", "2h", "1d", an ISO 8601 time or unix milliseconds.', ['maxLength' => 40]),
            'contains' => Schema::string('Only lines containing this text (case-insensitive).', ['maxLength' => 200]),
            'errors_only' => Schema::boolean('Only lines that look like errors (error, exception, fatal, panic, traceback).'),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['logs.view'];
    }

    public function group(): string
    {
        return 'operations';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $deployment = $this->deployment($context, $projectId, $arguments['deployment'] ?? null);

        $since = null;
        if (isset($arguments['since']) && $arguments['since'] !== '') {
            $since = LogLines::sinceMs((string) $arguments['since']);
            if ($since === null) {
                throw new ToolError('since is not a time: use "15m", "2h", "1d", an ISO 8601 time or unix milliseconds.');
            }
        }

        $result = $context->api()->get(
            InternalApi::path('projects', $projectId, 'deployments', (string) $deployment['id'], 'logs'),
            $since !== null ? ['since' => $since] : [],
        );
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the logs');
        }

        $max = max(1, (int) config('mcp.tools.max_log_lines', 500));
        $limit = min($max, (int) ($arguments['lines'] ?? 100));
        $lines = LogLines::filter(
            LogLines::parse((string) $result->get('logs', '')),
            $since,
            isset($arguments['contains']) ? (string) $arguments['contains'] : null,
            (bool) ($arguments['errors_only'] ?? false),
            $limit,
        );

        $lastTs = $result->get('last_ts_ms');
        foreach ($lines as $line) {
            if ($line['ts'] !== null && ($lastTs === null || $line['ts'] > $lastTs)) {
                $lastTs = $line['ts'];
            }
        }

        $structured = array_filter([
            'deployment' => ['id' => $deployment['id'], 'status' => $deployment['status'] ?? null],
            'pods' => array_values(array_map(fn ($p) => array_filter([
                'name' => $p['name'] ?? null,
                'status' => $p['status'] ?? null,
                'ready' => $p['ready'] ?? null,
            ], fn ($v) => $v !== null), array_filter((array) $result->get('pods', []), 'is_array'))),
            'source' => $result->get('source'),
            'error' => $result->get('error'),
            'lines' => $lines,
            'last_ts_ms' => $lastTs,
        ], fn ($v) => $v !== null);

        $summary = count($lines).' line(s)';
        if ($lines) {
            $summary .= ":\n".implode("\n", array_map([LogLines::class, 'format'], $lines));
        } elseif ($result->get('error')) {
            $summary .= ': '.$result->get('error');
        }

        return ToolResult::ok($structured, $summary);
    }
}
