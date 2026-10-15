<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GitSettingsUpdate extends Tool
{
    private const FIELDS = [
        'deploy_branch', 'root_dir', 'auto_deploy', 'allow_force_push', 'previews_enabled',
        'preview_limit', 'preview_ttl_hours', 'preview_env', 'fork_previews', 'fork_secrets',
    ];

    public function name(): string
    {
        return 'git_settings_update';
    }

    public function title(): string
    {
        return 'Change git deploy settings';
    }

    public function description(): string
    {
        return 'Changes how pushes deploy a project: deploy branch, root directory of a monorepo, auto-deploy, force pushes, and preview environments of pull / merge requests (on/off, limit, lifetime, environment overrides, forks). Preview settings also need previews.manage. Only the fields given change.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'deploy_branch' => Schema::string('Branch whose pushes deploy (default main).', ['minLength' => 1, 'maxLength' => 200]),
            'root_dir' => Schema::string('Directory of the project inside the repository; empty string: the repository root.', ['maxLength' => 255]),
            'auto_deploy' => Schema::boolean('Deploy every push to the deploy branch once its version is ready.'),
            'allow_force_push' => Schema::boolean('Accept non-fast-forward pushes to the deploy branch.'),
            'previews_enabled' => Schema::boolean('A preview environment per open pull / merge request (needs a connected repository).'),
            'preview_limit' => Schema::integer('Live previews at most.', 0, 100),
            'preview_ttl_hours' => Schema::integer('A preview without a new commit for this long is deleted.', 1, 8760),
            'preview_env' => Schema::map(['type' => 'string', 'maxLength' => 4096], 'Environment overrides of previews, NAME → value (non-secret values; replaces the whole set).'),
            'fork_previews' => Schema::boolean('Previews for requests from forks.'),
            'fork_secrets' => Schema::boolean('Give fork previews the secret variables (off: secrets are removed from fork previews).'),
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
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $payload = array_intersect_key($arguments, array_flip(self::FIELDS));
        if ($payload === []) {
            throw new ToolError('Give at least one setting to change ('.implode(', ', self::FIELDS).').');
        }
        if (array_key_exists('root_dir', $payload) && trim((string) $payload['root_dir']) === '') {
            $payload['root_dir'] = null;
        }
        if (array_key_exists('preview_env', $payload) && $payload['preview_env'] === []) {
            $payload['preview_env'] = null;
        }

        $result = $context->api()->put(InternalApi::path('projects', $projectId, 'git'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The git settings were not changed');
        }

        $settings = (array) $result->get('data.settings', []);
        $settings['preview_env_names'] = array_keys((array) ($settings['preview_env'] ?? []));
        unset($settings['preview_env']);

        return ToolResult::ok(['settings' => $settings, 'changed' => array_keys($payload)], 'Git settings saved: '.implode(', ', array_keys($payload)).'.');
    }
}
