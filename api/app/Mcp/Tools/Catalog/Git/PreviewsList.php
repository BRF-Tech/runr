<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsGit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class PreviewsList extends Tool
{
    use ReadsGit;

    public function name(): string
    {
        return 'previews_list';
    }

    public function title(): string
    {
        return 'List preview environments';
    }

    public function description(): string
    {
        return 'Preview environments of a project — one per open pull / merge request of its connected repository — with their URL, branch, commit, status and expiry. Deleted previews only with all true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'all' => Schema::boolean('Include deleted previews (default false).', false),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['git.pull'];
    }

    public function group(): string
    {
        return 'git';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'previews'), ['all' => ! empty($arguments['all']) ? 1 : null]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the previews');
        }

        $previews = array_map([self::class, 'previewSummary'], array_values(array_filter((array) $result->data(), 'is_array')));

        return ToolResult::ok(['previews' => $previews, 'count' => count($previews)], count($previews).' preview(s).');
    }
}
