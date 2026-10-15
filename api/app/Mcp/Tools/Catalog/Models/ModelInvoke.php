<?php

namespace App\Mcp\Tools\Catalog\Models;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ModelInvoke extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'model_invoke';
    }

    public function title(): string
    {
        return 'Invoke a model service';
    }

    public function description(): string
    {
        return 'Sends one request to a model service through the playground path, e.g. POST /v1/chat/completions with {"model": …, "messages": [...]} for an OpenAI-compatible engine. Only the engine\'s documented paths are allowed (model_services_list shows the playground examples).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'service' => Schema::string('Model service id or name.', ['minLength' => 1, 'maxLength' => 64]),
            'path' => Schema::string('Request path, e.g. "/v1/chat/completions".', ['minLength' => 1, 'maxLength' => 200]),
            'method' => Schema::enum(['GET', 'POST'], 'HTTP method (default POST).', 'POST'),
            'body' => ['type' => ['object', 'array', 'string'], 'description' => 'Request body (JSON).'],
        ], ['service', 'path']);
    }

    public function permissions(): array
    {
        return ['models.view'];
    }

    public function group(): string
    {
        return 'models';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $serviceId = $this->modelServiceId($context, (string) $arguments['service']);
        $context->setTarget('model_service', $serviceId);

        $body = $arguments['body'] ?? null;
        $result = $context->api()->post(InternalApi::path('model-services', $serviceId, 'invoke'), array_filter([
            'path' => (string) $arguments['path'],
            'method' => $arguments['method'] ?? 'POST',
            'body' => is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $body,
        ], fn ($v) => $v !== null));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The model service did not answer');
        }

        return ToolResult::ok((array) $result->json, 'Model service answered.');
    }
}
