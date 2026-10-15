<?php

namespace App\Mcp\Tools\Catalog\Git;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GitProvidersList extends Tool
{
    public function name(): string
    {
        return 'git_providers_list';
    }

    public function title(): string
    {
        return 'List git providers';
    }

    public function description(): string
    {
        return 'The GitHub Apps, GitLab and Forgejo / Gitea instances projects can connect repositories through (Settings → Git providers): type, name, URLs, whether they are configured and enabled, how projects connect (connect_modes) and how many repositories use them. Secrets appear as has_* flags only. Adding or changing a provider (registering the GitHub App, the GitLab application) happens in the console.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return ['git_providers.manage'];
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
        $result = $context->api()->get('/git-providers');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the git providers');
        }

        $providers = array_values(array_map(fn (array $p) => array_filter([
            'id' => $p['id'] ?? null,
            'type' => $p['type'] ?? null,
            'name' => $p['name'] ?? null,
            'web_url' => $p['web_url'] ?? null,
            'api_url' => $p['api_url'] ?? null,
            'app_slug' => $p['app_slug'] ?? null,
            'is_enabled' => $p['is_enabled'] ?? null,
            'configured' => $p['configured'] ?? null,
            'connect_modes' => $p['connect_modes'] ?? null,
            'has_client_secret' => $p['has_client_secret'] ?? null,
            'has_private_key' => $p['has_private_key'] ?? null,
            'has_webhook_secret' => $p['has_webhook_secret'] ?? null,
            'repositories' => $p['links_count'] ?? null,
        ], fn ($v) => $v !== null), array_filter((array) $result->data(), 'is_array')));

        return ToolResult::ok(['providers' => $providers, 'count' => count($providers)], count($providers).' git provider(s).');
    }
}
