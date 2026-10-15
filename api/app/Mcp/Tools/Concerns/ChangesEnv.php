<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;

/**
 * Environment variables of a project (docs/security.md): plain variables live in the config
 * (config.env_vars, readable with env.view), secret variables are write-only
 * (config.secret_env_keys lists their names). One PUT /projects/{id} changes both — plain
 * values as the full new map, secret values in config.secret_env_vars (an empty value removes
 * one) — so a name can move between plain and secret in one step.
 */
trait ChangesEnv
{
    use ReadsProjects;

    /**
     * @return array{project: array, plain: array<string, string>, secret: list<string>}
     *
     * @throws ToolError
     */
    protected function env(ToolContext $context, string $projectId): array
    {
        $project = $this->project($context, $projectId);
        $config = self::config($project);

        if (! empty($config['env_vars_hidden']) || ! array_key_exists('env_vars', $config)) {
            throw new ToolError('The environment variables of this project are hidden from this connection (requires env.view).', ['permissions' => ['env.view']]);
        }

        $plain = [];
        foreach ((array) ($config['env_vars'] ?? []) as $name => $value) {
            $plain[(string) $name] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return [
            'project' => $project,
            'plain' => $plain,
            'secret' => array_values(array_map('strval', (array) ($config['secret_env_keys'] ?? []))),
        ];
    }

    /**
     * Sends the change: the new plain map when it changed, secret writes / removals.
     *
     * @param  array<string, string>  $plain  the complete new plain map
     * @param  array<string, string>  $secrets  name => value ('' removes the secret)
     */
    protected function applyEnv(ToolContext $context, string $projectId, ?array $plain, array $secrets, ?string $message): void
    {
        $config = [];
        if ($plain !== null) {
            $config['env_vars'] = $plain === [] ? new \stdClass : $plain;
        }
        if ($secrets !== []) {
            $config['secret_env_vars'] = $secrets;
        }
        if ($config === []) {
            return;
        }

        $payload = ['config' => $config];
        if ($message !== null && $message !== '') {
            $payload['config_message'] = $message;
        }

        $result = $context->api()->put(InternalApi::path('projects', $projectId), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The environment was not changed');
        }
    }
}
