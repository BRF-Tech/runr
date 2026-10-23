<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiPlansList extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_plans_list';
    }

    public function title(): string
    {
        return 'List API plans';
    }

    public function description(): string
    {
        return 'Lists the plans of the API product: rate limits (per second with burst, per minute) and quotas (per day, per calendar month) that consumers and keys get. With a gateway: its plans and the organization\'s.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'gateway' => Schema::string('Only plans offered on this gateway (id).', ['minLength' => 26, 'maxLength' => 26]),
        ]);
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
        $result = $context->api()->get('/api-plans', self::compact(['gateway_id' => $arguments['gateway'] ?? null]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The plans could not be listed');
        }

        $plans = (array) $result->data();

        return ToolResult::ok(['plans' => $plans], count($plans).' plan(s).');
    }
}
