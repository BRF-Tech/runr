<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ReplaysList extends Tool
{
    public function name(): string
    {
        return 'replays_list';
    }

    public function title(): string
    {
        return 'List captured failed requests';
    }

    public function description(): string
    {
        return 'Failed requests (HTTP 5xx, timeouts) a project captured for replay, newest first: method, path, status, duration, trace id and the result of the last replay. replay_get shows one with its (redacted) headers and bodies; replay_run sends it again.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'status' => Schema::integer('Only captures with this status code.', 100, 999),
            'path' => Schema::string('Part of the request path.', ['maxLength' => 200]),
            'page' => Schema::integer('Page (50 per page).', 1, 1000),
        ], ['project']);
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

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'replays'), [
            'status' => $arguments['status'] ?? null,
            'path' => $arguments['path'] ?? null,
            'page' => $arguments['page'] ?? null,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the captured requests');
        }

        $captures = array_map(fn (array $c) => array_filter($c, fn ($v) => $v !== null && $v !== ''), array_values(array_filter((array) $result->data(), 'is_array')));

        return ToolResult::ok([
            'captures' => $captures,
            'total' => $result->get('meta.total'),
            'page' => $result->get('meta.current_page'),
            'last_page' => $result->get('meta.last_page'),
        ], ($result->get('meta.total') ?? count($captures)).' captured request(s).');
    }
}
