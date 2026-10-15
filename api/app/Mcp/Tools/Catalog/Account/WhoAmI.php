<?php

namespace App\Mcp\Tools\Catalog\Account;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class WhoAmI extends Tool
{
    public function name(): string
    {
        return 'whoami';
    }

    public function title(): string
    {
        return 'Who am I';
    }

    public function description(): string
    {
        return 'The signed-in user, the organization (tenant) this connection works in and the permissions the connection holds — organization-wide and per project. Call it first to know which actions are possible.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return [];
    }

    public function group(): string
    {
        return 'account';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $me = $context->api()->get('/auth/me');
        if (! $me->ok()) {
            throw ToolError::fromApi($me);
        }
        $permissions = $context->api()->get('/auth/permissions');
        if (! $permissions->ok()) {
            throw ToolError::fromApi($permissions);
        }

        $user = (array) $me->json;
        $tenant = null;
        if (config('tenancy.enabled')) {
            $current = $context->api()->get('/tenant/current');
            $tenant = $current->ok() ? $current->get('tenant') : null;
        }

        $result = [
            'user' => array_filter([
                'id' => isset($user['id']) ? (string) $user['id'] : null,
                'name' => $user['name'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
            ], fn ($v) => $v !== null),
            'organization' => is_array($tenant) ? array_filter(['id' => $tenant['id'] ?? null, 'name' => $tenant['name'] ?? null]) : null,
            'role' => $permissions->get('role'),
            'platform_admin' => (bool) $permissions->get('platform_admin'),
            'token_scoped' => (bool) $permissions->get('token_scoped'),
            'permissions' => array_values((array) $permissions->get('permissions', [])),
            'project_permissions' => (array) $permissions->get('projects', []),
        ];

        return ToolResult::ok($result, sprintf(
            'Signed in as %s%s; %d organization-wide permission(s).',
            $result['user']['name'] ?? $result['user']['username'] ?? 'unknown',
            $result['organization'] ? ' in '.($result['organization']['name'] ?? $result['organization']['id']) : '',
            count($result['permissions']),
        ));
    }
}
