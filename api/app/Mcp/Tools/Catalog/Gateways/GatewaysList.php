<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class GatewaysList extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'gateways_list';
    }

    public function title(): string
    {
        return 'List API gateways';
    }

    public function description(): string
    {
        return 'Lists the caller\'s API gateways (one per region) with their host, custom domain and routes: path, project, authentication (public, key or jwt) and whether the project is reachable only through the gateway.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'gateways';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('/gateways');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The gateways could not be listed');
        }

        $gateways = array_map([self::class, 'gatewaySummary'], (array) $result->data());

        return ToolResult::ok(['gateways' => $gateways], count($gateways).' gateway(s).');
    }
}
