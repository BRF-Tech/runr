<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ReplayGet extends Tool
{
    public function name(): string
    {
        return 'replay_get';
    }

    public function title(): string
    {
        return 'Read a captured request';
    }

    public function description(): string
    {
        return 'One captured failed request: the request (method, path, query, headers left after redaction, body) and the response the function gave (status, headers, body), plus the last replay. Bodies are capped at the server\'s file size limit. Captures can hold personal data — use them for debugging only.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'capture' => Schema::string('The capture id (replays_list).', ['minLength' => 1, 'maxLength' => 64]),
        ], ['project', 'capture']);
    }

    public function permissions(): array
    {
        return ['replay.manage'];
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

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'replays', (string) $arguments['capture']));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The capture could not be read');
        }

        $capture = (array) $result->data();
        $max = max(1024, (int) config('mcp.tools.max_file_bytes', 512 * 1024));
        $shortened = [];
        foreach (['body', 'response_body'] as $field) {
            if (is_string($capture[$field] ?? null) && strlen($capture[$field]) > $max) {
                $capture[$field] = substr($capture[$field], 0, $max);
                $shortened[] = $field;
            }
        }
        if ($shortened) {
            $capture['shortened_fields'] = $shortened;
        }

        return ToolResult::ok(['capture' => $capture], sprintf(
            '%s %s answered %s.',
            $capture['method'] ?? '?',
            $capture['path'] ?? '?',
            ! empty($capture['timed_out']) ? 'with a timeout' : ($capture['status'] ?? '?'),
        ));
    }
}
