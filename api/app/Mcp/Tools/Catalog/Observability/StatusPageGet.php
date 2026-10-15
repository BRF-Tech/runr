<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class StatusPageGet extends Tool
{
    public function name(): string
    {
        return 'status_page_get';
    }

    public function title(): string
    {
        return 'Read the status page';
    }

    public function description(): string
    {
        return 'The organization\'s public status page: its settings (slug, title, description, logo, custom domain, public or not, incident history), its components — uptime checks shown on the page, with their current status — and the public path.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return ['status_page.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/observability/status-page');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the status page');
        }

        $data = (array) $result->data();
        unset($data['preview']); // the rendered public payload: the components say the same

        $page = (array) ($data['page'] ?? []);

        return ToolResult::ok($data, empty($page['exists'])
            ? 'The organization has no status page: status_page_update creates one.'
            : 'Status page "'.($page['title'] ?? '').'" at '.($data['public_path'] ?? '').(! empty($page['is_public']) ? ' (public).' : ' (not public).'));
    }
}
