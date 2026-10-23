<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiConsumerCreate extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_consumer_create';
    }

    public function title(): string
    {
        return 'Create an API consumer';
    }

    public function description(): string
    {
        return 'Creates a consumer of a gateway\'s API (without gateway: of the organization, accepted on every gateway — needs api_products.manage). Give it a plan for rate limits and quotas, an external_id to match the invoicing system, a jwt_subject for JWT routes; then api_key_create gives it a key.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'gateway' => Schema::string('The gateway id (gateways_list); leave out for an organization consumer.', ['minLength' => 26, 'maxLength' => 26]),
            'name' => Schema::string('Name of the consumer, e.g. the customer\'s company.', ['minLength' => 1, 'maxLength' => 120]),
            'email' => Schema::string('Contact e-mail (optional).', ['maxLength' => 255]),
            'external_id' => Schema::string('Customer number in the invoicing system (optional).', ['maxLength' => 191]),
            'jwt_subject' => Schema::string('JWT routes: the claim value that names this consumer (optional).', ['maxLength' => 191]),
            'plan' => Schema::string('Plan id (api_plans_list); none = no limits besides the gateway\'s.', ['minLength' => 26, 'maxLength' => 26]),
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
        $result = $context->api()->post('/api-consumers', self::compact([
            'gateway_id' => $arguments['gateway'] ?? null,
            'name' => (string) $arguments['name'],
            'email' => $arguments['email'] ?? null,
            'external_id' => $arguments['external_id'] ?? null,
            'jwt_subject' => $arguments['jwt_subject'] ?? null,
            'plan_id' => $arguments['plan'] ?? null,
        ]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The consumer was not created');
        }

        $consumer = (array) $result->data();

        return ToolResult::ok(['consumer' => [
            'id' => $consumer['id'] ?? null,
            'name' => $consumer['name'] ?? null,
            'scope' => $consumer['scope'] ?? null,
            'plan_id' => $consumer['plan_id'] ?? null,
        ]], sprintf('Consumer %s created; api_key_create gives it a key.', $consumer['name'] ?? $arguments['name']));
    }
}
