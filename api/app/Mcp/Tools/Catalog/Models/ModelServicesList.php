<?php

namespace App\Mcp\Tools\Catalog\Models;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ModelServicesList extends Tool
{
    public function name(): string
    {
        return 'model_services_list';
    }

    public function title(): string
    {
        return 'List model services';
    }

    public function description(): string
    {
        return 'Lists the organization\'s model services (Hugging Face models served in a region): model, engine, device, status, the in-cluster URL and its OpenAI-compatible base when the engine has one. model_invoke calls one; model_bind gives a project access.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('Only services of this region (region id).', ['maxLength' => 64]),
        ]);
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
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/model-services', ['knative_region_id' => $arguments['region'] ?? null]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result);
        }

        $services = array_values(array_map([self::class, 'summary'], array_filter((array) $result->get('data', []), 'is_array')));

        return ToolResult::ok(['services' => $services], count($services).' model service(s).');
    }

    /** A model service without its environment values. */
    public static function summary(array $service): array
    {
        $env = $service['env_vars'] ?? null;

        return array_filter([
            'id' => $service['id'] ?? null,
            'name' => $service['name'] ?? null,
            'model' => $service['model']['hub_id'] ?? $service['model']['name'] ?? null,
            'task' => $service['model']['task'] ?? null,
            'region' => $service['region_name'] ?? $service['knative_region_id'] ?? null,
            'engine' => $service['engine'] ?? null,
            'device' => $service['device'] ?? null,
            'mode' => $service['mode'] ?? null,
            'gpu' => $service['gpu'] ?? null,
            'status' => $service['status'] ?? null,
            'status_message' => $service['status_message'] ?? null,
            'url' => $service['url'] ?? null,
            'openai_base' => $service['openai_base'] ?? null,
            'playground' => $service['playground'] ?? null,
            'env_names' => is_array($env) ? array_values(array_map('strval', array_keys($env))) : null,
            'bindings' => $service['bindings_count'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
