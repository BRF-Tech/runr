<?php

namespace App\Mcp\Tools\Catalog\Models;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ModelsSearch extends Tool
{
    public function name(): string
    {
        return 'models_search';
    }

    public function title(): string
    {
        return 'Search Hugging Face models';
    }

    public function description(): string
    {
        return 'Searches the Hugging Face Hub (through RunR, with the organization\'s token for gated models): id, task, library, downloads, likes, gated. Deploy a result as a model service with model_service_deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'search' => Schema::string('Search text, e.g. "qwen 0.5b instruct".', ['maxLength' => 200]),
            'task' => Schema::string('Pipeline task, e.g. "text-generation", "feature-extraction".', ['maxLength' => 64, 'pattern' => '^[a-z0-9-]+$']),
            'library' => Schema::string('Library, e.g. "transformers", "gguf".', ['maxLength' => 64, 'pattern' => '^[a-z0-9_.-]+$']),
            'sort' => Schema::enum(['downloads', 'likes', 'trending', 'updated'], 'Order (default downloads).', 'downloads'),
            'limit' => Schema::integer('Results (default 20).', 1, 50),
            'cursor' => Schema::string('next_cursor of a previous search.', ['maxLength' => 500]),
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
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/models/hub/search', [
            'search' => $arguments['search'] ?? null,
            'task' => $arguments['task'] ?? null,
            'library' => $arguments['library'] ?? null,
            'sort' => $arguments['sort'] ?? null,
            'limit' => $arguments['limit'] ?? null,
            'cursor' => $arguments['cursor'] ?? null,
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The Hub search failed');
        }

        $models = array_values(array_filter((array) $result->get('data', []), 'is_array'));

        return ToolResult::ok(array_filter([
            'models' => $models,
            'next_cursor' => $result->get('next_cursor'),
        ], fn ($v) => $v !== null), count($models).' model(s).');
    }
}
