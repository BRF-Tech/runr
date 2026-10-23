<?php

namespace App\Mcp\Tools\Catalog\Gateways;

use App\Mcp\Tools\Concerns\ReadsGateways;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ApiConsumersList extends Tool
{
    use ReadsGateways;

    public function name(): string
    {
        return 'api_consumers_list';
    }

    public function title(): string
    {
        return 'List API consumers';
    }

    public function description(): string
    {
        return 'Lists the consumers of the API product: who calls a gateway\'s key or JWT routes, with their plan, status, external id (the invoicing system\'s customer number) and key counts. With a gateway: its consumers and the organization\'s.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'gateway' => Schema::string('Only consumers accepted on this gateway (id).', ['minLength' => 26, 'maxLength' => 26]),
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
        $result = $context->api()->get('/api-consumers', self::compact(['gateway_id' => $arguments['gateway'] ?? null]));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The consumers could not be listed');
        }

        $consumers = array_map(fn ($c) => [
            'id' => $c['id'] ?? null,
            'name' => $c['name'] ?? null,
            'scope' => $c['scope'] ?? null,
            'gateway_id' => $c['gateway_id'] ?? null,
            'external_id' => $c['external_id'] ?? null,
            'plan' => $c['plan']['name'] ?? null,
            'status' => $c['status'] ?? null,
            'keys' => $c['keys'] ?? null,
        ], (array) $result->data());

        return ToolResult::ok(['consumers' => $consumers], count($consumers).' consumer(s).');
    }
}
