<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ConnectsGitRepositories;
use App\Mcp\Tools\Concerns\ReadsGit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GitRepoConnect extends Tool
{
    use ConnectsGitRepositories, ReadsGit;

    public function name(): string
    {
        return 'git_repo_connect';
    }

    public function title(): string
    {
        return 'Connect a provider repository';
    }

    public function description(): string
    {
        return 'Connects a GitHub, GitLab or Forgejo / Gitea repository to the project: pushes to its deploy branch deploy the project (the RunR remote becomes read-only), pull / merge requests get previews when previews are on. RunR checks that the repository can be read, stores the credentials encrypted and registers the webhook. One repository per project.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()] + self::credentialProperties() + [
            'repository' => Schema::string('The repository: full name (acme/orders) or id (git_repositories_list).', ['minLength' => 1, 'maxLength' => 255]),
        ], ['project', 'repository']);
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
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $provider = self::findProvider($this->gitPayload($context, $projectId), $arguments['provider'] ?? null);

        $result = $context->api()->post(
            InternalApi::path('projects', $projectId, 'git', 'connect'),
            self::credentialBody($arguments, (string) $provider['id']) + ['repository' => (string) $arguments['repository']],
        );
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The repository was not connected');
        }

        $connection = (array) $result->get('data.connection', []);

        return ToolResult::ok([
            'connection' => array_filter([
                'provider' => $connection['provider_name'] ?? $provider['name'] ?? null,
                'repository' => $connection['repo_full_name'] ?? null,
                'url' => $connection['repo_url'] ?? null,
                'default_branch' => $connection['default_branch'] ?? null,
                'webhook' => $connection['webhook_registered'] ?? null,
            ], fn ($v) => $v !== null),
            'deploy_branch' => $result->get('data.settings.deploy_branch'),
            'read_only' => (bool) $result->get('data.read_only', true),
        ], 'Connected '.($connection['repo_full_name'] ?? $arguments['repository']).'; pushes to '.$result->get('data.settings.deploy_branch', 'main').' deploy the project.');
    }
}
