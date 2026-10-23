<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiPlanCreate extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_plan_create';
    }

    public function title(): string
    {
        return 'Create an API plan';
    }

    public function description(): string
    {
        return 'Creates a plan: rate limits and quotas the gateway enforces per consumer (or per key with a plan of its own). Every limit is optional; callers over a limit get 429 with RateLimit headers. Without gateway: a plan of the organization (needs api_products.manage).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'gateway' => Schema::string('The gateway id; leave out for an organization plan.', ['minLength' => 26, 'maxLength' => 26]),
            'name' => Schema::string('Name, e.g. "Free" or "Pro".', ['minLength' => 1, 'maxLength' => 100]),
            'description' => Schema::string('What the plan offers (optional).', ['maxLength' => 500]),
            'rate_per_second' => Schema::integer('Requests per second.', 1, 100000),
            'burst' => Schema::integer('Requests allowed at once above the per-second rate (default: the rate).', 1, 1000000),
            'rate_per_minute' => Schema::integer('Requests per minute (sliding window).', 1, 10000000),
            'quota_per_day' => Schema::integer('Requests per day (UTC).', 1),
            'quota_per_month' => Schema::integer('Requests per calendar month (UTC).', 1),
        ], ['name']);
    }

    public function permissions(): array
    {
        return ['projects.update|api_products.manage'];
    }

    public function group(): string
    {
        return 'gateways';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->post('/api-plans', self::compact([
            'gateway_id' => $arguments['gateway'] ?? null,
            'name' => (string) $arguments['name'],
            'description' => $arguments['description'] ?? null,
            'rate_per_second' => $arguments['rate_per_second'] ?? null,
            'burst' => $arguments['burst'] ?? null,
            'rate_per_minute' => $arguments['rate_per_minute'] ?? null,
            'quota_per_day' => $arguments['quota_per_day'] ?? null,
            'quota_per_month' => $arguments['quota_per_month'] ?? null,
        ]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The plan was not created');
        }

        $plan = (array) $result->data();

        return ToolResult::ok(['plan' => $plan], sprintf('Plan %s created.', $plan['name'] ?? $arguments['name']));
    }
}
