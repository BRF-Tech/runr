<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Tools\Concerns\ReadsGit;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class GitStatus extends Tool
{
    use ReadsGit;

    public function name(): string
    {
        return 'git_status';
    }

    public function title(): string
    {
        return 'Git deploy status';
    }

    public function description(): string
    {
        return 'Git deploy of a project: the RunR git remote URL and how to push to it (any user name, an API token with git.push as password), the deploy branch, auto-deploy and preview settings, the connected provider repository (GitHub, GitLab, Forgejo / Gitea), the providers available to connect and the latest push deploys. A push to the deploy branch creates a version and, with auto-deploy on, deploys it.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'deploys' => Schema::integer('How many recent git deploys to include (default 5).', 0, 50),
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
        $payload = $this->gitPayload($context, $projectId);
        $settings = (array) ($payload['settings'] ?? []);
        $count = (int) ($arguments['deploys'] ?? 5);
        $remote = (string) ($payload['remote_url'] ?? '');
        $branch = (string) ($settings['deploy_branch'] ?? 'main');
        $readOnly = (bool) ($payload['read_only'] ?? false);

        $connection = is_array($payload['connection'] ?? null) ? array_filter([
            'provider' => $payload['connection']['provider_name'] ?? $payload['connection']['provider_type'] ?? null,
            'provider_type' => $payload['connection']['provider_type'] ?? null,
            'repository' => $payload['connection']['repo_full_name'] ?? null,
            'url' => $payload['connection']['repo_url'] ?? null,
            'default_branch' => $payload['connection']['default_branch'] ?? null,
            'webhook' => $payload['connection']['webhook_registered'] ?? null,
            'last_event_at' => $payload['connection']['last_event_at'] ?? null,
        ], fn ($v) => $v !== null) : null;

        $result = [
            'remote_url' => $remote,
            'remote_ready' => (bool) ($payload['remote_ready'] ?? false),
            'remote_head' => $payload['remote_head'] ?? null,
            'read_only' => $readOnly,
            'push' => $readOnly
                ? 'The project deploys from its connected repository: push there; the RunR remote is read-only.'
                : "git remote add runr {$remote} && git push runr {$branch} — user name: anything, password: an API token with git.push.",
            'settings' => [
                'deploy_branch' => $branch,
                'root_dir' => $settings['root_dir'] ?? null,
                'auto_deploy' => (bool) ($settings['auto_deploy'] ?? true),
                'allow_force_push' => (bool) ($settings['allow_force_push'] ?? false),
                'previews_enabled' => (bool) ($settings['previews_enabled'] ?? false),
                'preview_limit' => $settings['preview_limit'] ?? null,
                'preview_ttl_hours' => $settings['preview_ttl_hours'] ?? null,
                // Preview env overrides: names only (values stay in the console)
                'preview_env_names' => array_keys((array) ($settings['preview_env'] ?? [])),
                'fork_previews' => (bool) ($settings['fork_previews'] ?? false),
                'fork_secrets' => (bool) ($settings['fork_secrets'] ?? false),
            ],
            'connection' => $connection,
            'providers' => array_values(array_map(fn (array $p) => [
                'id' => $p['id'] ?? null,
                'type' => $p['type'] ?? null,
                'name' => $p['name'] ?? null,
                'connect_modes' => $p['connect_modes'] ?? [],
            ], array_filter((array) ($payload['providers'] ?? []), 'is_array'))),
            'abilities' => $payload['abilities'] ?? null,
        ];

        if ($count > 0) {
            $result['deploys'] = array_map([self::class, 'gitDeploySummary'], $this->gitDeploys($context, $projectId, $count));
        }

        return ToolResult::ok($result, $readOnly
            ? 'Deploys from '.($connection['repository'] ?? 'its connected repository').' (branch '.$branch.').'
            : "RunR git remote {$remote}, deploy branch {$branch}".(($result['settings']['auto_deploy']) ? ', auto-deploy on.' : ', auto-deploy off.'));
    }
}
