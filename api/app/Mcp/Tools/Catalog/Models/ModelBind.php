<?php

namespace App\Mcp\Tools\Catalog\Models;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ModelBind extends Tool
{
    use ReadsProjects;

    public function name(): string
    {
        return 'model_bind';
    }

    public function title(): string
    {
        return 'Bind a model service to a project';
    }

    public function description(): string
    {
        return 'Gives a project access to a model service: on the next deploy the project gets RUNR_MODEL_<ALIAS>_URL (and the OpenAI-compatible base when the engine has one); mount_weights also mounts the model files read-only. The project must run in the service\'s region.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'service' => Schema::string('Model service id or name.', ['minLength' => 1, 'maxLength' => 64]),
            'alias' => Schema::string('Alias in the variable names (upper-case letters, digits, _); default derived from the service.', ['maxLength' => 32, 'pattern' => '^[A-Z][A-Z0-9_]*$']),
            'mount_weights' => Schema::boolean('Also mount the model weights into the project (default false).'),
        ], ['project', 'service']);
    }

    public function permissions(): array
    {
        return ['models.deploy', 'projects.update'];
    }

    public function group(): string
    {
        return 'models';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $serviceId = $this->modelServiceId($context, (string) $arguments['service']);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'models'), array_filter([
            'ai_model_service_id' => $serviceId,
            'alias' => $arguments['alias'] ?? null,
            'mount_weights' => isset($arguments['mount_weights']) ? (bool) $arguments['mount_weights'] : null,
        ], fn ($v) => $v !== null));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The model service was not bound');
        }

        $binding = (array) $result->data();

        return ToolResult::ok(['binding' => $binding + ['requires_deploy' => true]], 'Bound; deploy the project to receive the RUNR_MODEL_* variables.');
    }
}
