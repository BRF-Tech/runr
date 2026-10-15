<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GitRepoDisconnect extends Tool
{
    public function name(): string
    {
        return 'git_repo_disconnect';
    }

    public function title(): string
    {
        return 'Disconnect the provider repository';
    }

    public function description(): string
    {
        return 'Disconnects the project from its provider repository: the webhook is removed and every preview environment is deleted; the project keeps its versions and the RunR git remote accepts pushes again. Ask the user first and pass confirm true.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'confirm' => Schema::confirm('disconnecting the repository and deleting its previews'),
        ], ['project', 'confirm']);
    }

    public function permissions(): array
    {
        return ['git.manage'];
    }

    public function group(): string
    {
        return 'git';
    }

    public function annotations(): array
    {
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->delete(InternalApi::path('projects', $projectId, 'git', 'connect'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The repository was not disconnected');
        }

        return ToolResult::ok([
            'connection' => $result->get('data.connection'),
            'read_only' => (bool) $result->get('data.read_only', false),
            'remote_url' => $result->get('data.remote_url'),
        ], 'Disconnected; pushes to the RunR git remote deploy the project again.');
    }
}
