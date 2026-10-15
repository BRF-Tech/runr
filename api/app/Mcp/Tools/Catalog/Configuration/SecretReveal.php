<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class SecretReveal extends Tool
{
    public function name(): string
    {
        return 'secret_reveal';
    }

    public function title(): string
    {
        return 'Reveal a secret variable';
    }

    public function description(): string
    {
        return 'Returns the value of one secret environment variable of a project. Every read is recorded in the audit log (project.secret.reveal). Only call it when the user needs the value; never repeat it in summaries.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'name' => Schema::string('Name of the secret variable (env_list shows them).', ['minLength' => 1, 'maxLength' => 255, 'pattern' => '^[A-Za-z_][A-Za-z0-9_]*$']),
        ], ['project', 'name']);
    }

    public function permissions(): array
    {
        return ['secrets.view_values'];
    }

    public function group(): string
    {
        return 'configuration';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $name = (string) $arguments['name'];

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'secrets', $name, 'value'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, "Reading secret {$name}");
        }

        return ToolResult::ok(['name' => $name, 'value' => (string) $result->get('value', '')], "Value of secret {$name}.");
    }
}
