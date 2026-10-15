<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Tools\Concerns\ChangesEnv;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class EnvUnset extends Tool
{
    use ChangesEnv;

    public function name(): string
    {
        return 'env_unset';
    }

    public function title(): string
    {
        return 'Remove environment variables';
    }

    public function description(): string
    {
        return 'Removes environment variables — plain or secret — from a project. Changes reach the workload with the next deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'names' => Schema::array(['type' => 'string', 'minLength' => 1, 'maxLength' => 255], 'Variable names to remove.', 100) + ['minItems' => 1],
            'message' => Schema::string('Message of the version the change saves.', ['maxLength' => 500]),
        ], ['project', 'names']);
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $env = $this->env($context, $projectId);

        $plain = $env['plain'];
        $plainChanged = false;
        $secrets = [];
        $removed = [];
        $unknown = [];

        foreach (array_unique(array_map('strval', (array) $arguments['names'])) as $name) {
            $found = false;
            if (array_key_exists($name, $plain)) {
                unset($plain[$name]);
                $plainChanged = $found = true;
            }
            if (in_array($name, $env['secret'], true)) {
                $secrets[$name] = '';
                $found = true;
            }
            if ($found) {
                $removed[] = $name;
            } else {
                $unknown[] = $name;
            }
        }

        if ($removed === []) {
            throw new ToolError('None of these variables is set: '.implode(', ', $unknown).'. env_list shows the variables.');
        }

        $this->applyEnv($context, $projectId, $plainChanged ? $plain : null, $secrets, isset($arguments['message']) ? (string) $arguments['message'] : null);

        return ToolResult::ok(array_filter([
            'removed' => $removed,
            'not_set' => $unknown,
            'requires_deploy' => true,
        ], fn ($v) => $v !== []), 'Removed '.implode(', ', $removed).'. Deploy to apply.');
    }
}
