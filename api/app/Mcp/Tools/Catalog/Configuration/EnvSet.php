<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Tools\Concerns\ChangesEnv;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class EnvSet extends Tool
{
    use ChangesEnv;

    public function name(): string
    {
        return 'env_set';
    }

    public function title(): string
    {
        return 'Set environment variables';
    }

    public function description(): string
    {
        return 'Sets environment variables of a project. With secret true the values are stored write-only (encrypted, delivered through a Kubernetes Secret, never shown again); use it for passwords, tokens and keys. A name moves between plain and secret when set the other way. Changes reach the workload with the next deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'vars' => Schema::map(['type' => 'string', 'maxLength' => 32768], 'NAME → value. Names: letters, digits and _, not starting with a digit; PORT, K_* and RUNR_* are reserved.') + [
                'propertyNames' => ['type' => 'string', 'pattern' => '^[A-Za-z_][A-Za-z0-9_]*$', 'maxLength' => 255],
                'minProperties' => 1,
                'maxProperties' => 100,
            ],
            'secret' => Schema::boolean('Store the values as secret variables (default false).', false),
            'message' => Schema::string('Message of the version the change saves.', ['maxLength' => 500]),
        ], ['project', 'vars']);
    }

    public function permissions(): array
    {
        return ['env.edit'];
    }

    public function group(): string
    {
        return 'configuration';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $env = $this->env($context, $projectId);
        $secret = (bool) ($arguments['secret'] ?? false);

        $plain = $env['plain'];
        $plainChanged = false;
        $secrets = [];

        foreach ((array) $arguments['vars'] as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            if ($secret) {
                $secrets[$name] = $value;
                if (array_key_exists($name, $plain)) {
                    unset($plain[$name]);
                    $plainChanged = true;
                }

                continue;
            }
            if (! array_key_exists($name, $plain) || $plain[$name] !== $value) {
                $plain[$name] = $value;
                $plainChanged = true;
            }
            if (in_array($name, $env['secret'], true)) {
                $secrets[$name] = ''; // moves from secret to plain
                $plainChanged = true;
            }
        }

        $names = array_map('strval', array_keys((array) $arguments['vars']));
        if (! $plainChanged && $secrets === []) {
            return ToolResult::ok(['unchanged' => $names], 'Nothing changed: the variables already have these values.');
        }

        $this->applyEnv($context, $projectId, $plainChanged ? $plain : null, $secrets, isset($arguments['message']) ? (string) $arguments['message'] : null);

        return ToolResult::ok([
            'set' => $names,
            'secret' => $secret,
            'requires_deploy' => true,
        ], 'Set '.implode(', ', $names).($secret ? ' as secret variable(s)' : '').'. Deploy to apply.');
    }
}
