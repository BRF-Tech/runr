<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Tools\Concerns\ReadsGit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class GitDeploysList extends Tool
{
    use ReadsGit;

    public function name(): string
    {
        return 'git_deploys_list';
    }

    public function title(): string
    {
        return 'Git deploy history';
    }

    public function description(): string
    {
        return 'The git deploys of a project, newest first: production pushes and preview builds with commit, branch, author, message, the version they created and their status (queued, building, deploying, active, failed, unchanged, skipped) with the reason of a failure.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'kind' => Schema::enum(['production', 'preview'], 'Only production pushes or only previews.'),
            'limit' => Schema::integer('At most this many deploys (default 20).', 1, 100),
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
        $deploys = $this->gitDeploys($context, $projectId, (int) ($arguments['limit'] ?? 20), $arguments['kind'] ?? null);

        return ToolResult::ok(
            ['deploys' => array_map([self::class, 'gitDeploySummary'], $deploys), 'count' => count($deploys)],
            count($deploys).' git deploy(s).',
        );
    }
}
