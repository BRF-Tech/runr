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

class GitRepositoriesList extends Tool
{
    use ConnectsGitRepositories, ReadsGit;

    public function name(): string
    {
        return 'git_repositories_list';
    }

    public function title(): string
    {
        return 'List provider repositories';
    }

    public function description(): string
    {
        return 'Lists the repositories a git provider\'s credentials can deploy from (GitHub App installation, or a GitLab / Forgejo / Gitea access token), optionally filtered by a search term — the step before git_repo_connect.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()] + self::credentialProperties() + [
            'search' => Schema::string('Part of the repository name.', ['maxLength' => 100]),
        ], ['project']);
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
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $provider = self::findProvider($this->gitPayload($context, $projectId), $arguments['provider'] ?? null);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'git', 'repos'), self::credentialBody($arguments, (string) $provider['id']) + array_filter([
            'search' => $arguments['search'] ?? null,
        ]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The repositories could not be listed');
        }

        $repos = array_values(array_map(fn (array $r) => array_filter([
            'id' => isset($r['id']) ? (string) $r['id'] : null,
            'full_name' => $r['full_name'] ?? null,
            'url' => $r['url'] ?? $r['html_url'] ?? null,
            'default_branch' => $r['default_branch'] ?? null,
            'private' => $r['private'] ?? null,
        ], fn ($v) => $v !== null), array_filter((array) $result->data(), 'is_array')));

        return ToolResult::ok(['provider' => $provider['name'] ?? $provider['type'], 'repositories' => $repos, 'count' => count($repos)], count($repos).' repository(ies).');
    }
}
