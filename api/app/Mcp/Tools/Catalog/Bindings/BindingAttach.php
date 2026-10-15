<?php

namespace App\Mcp\Tools\Catalog\Bindings;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class BindingAttach extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'binding_attach';
    }

    public function title(): string
    {
        return 'Attach a binding to a project';
    }

    public function description(): string
    {
        return 'Attaches a binding to a project: on the next deploy the project gets the binding\'s connection settings as environment variables (e.g. REDIS_URL, DATABASE_URL, S3_*), optionally behind a prefix (CACHE_ → CACHE_REDIS_URL). The answer names the variables.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'binding' => Schema::string('Binding id or name (bindings_list).', ['minLength' => 1, 'maxLength' => 64]),
            'env_prefix' => Schema::string('Prefix of the variable names: upper-case, ending in "_" (e.g. "CACHE_").', ['maxLength' => 32, 'pattern' => '^[A-Z][A-Z0-9_]*_$']),
        ], ['project', 'binding']);
    }

    public function permissions(): array
    {
        return ['bindings.manage', 'env.edit'];
    }

    public function group(): string
    {
        return 'bindings';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $bindingId = $this->bindingId($context, (string) $arguments['binding']);

        $payload = ['binding_id' => $bindingId];
        if (! empty($arguments['env_prefix'])) {
            $payload['env_prefix'] = (string) $arguments['env_prefix'];
        }

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'bindings'), $payload);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The binding was not attached');
        }

        $attachment = (array) $result->data();

        return ToolResult::ok(['attachment' => array_filter([
            'id' => $attachment['id'] ?? null,
            'binding' => $attachment['binding']['name'] ?? $bindingId,
            'env_prefix' => $attachment['env_prefix'] ?? null,
            'env_names' => $attachment['env_names'] ?? null,
            'requires_deploy' => true,
        ], fn ($v) => $v !== null && $v !== '')], 'Attached; deploy to inject '.implode(', ', (array) ($attachment['env_names'] ?? [])).'.');
    }
}
