<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_region_organizations (docs/billing.md#queue-consumers): platform administrators — the
 * tenants a platform region is shared with and the platform's queue consumer choice for each,
 * through GET /api/billing/regions/{region}/organizations (@platform:billing.view).
 */
class BillingRegionOrganizations extends BillingTool
{
    public function name(): string
    {
        return 'billing_region_organizations';
    }

    public function title(): string
    {
        return 'Tenants of a platform region';
    }

    public function description(): string
    {
        return 'Platform administrators (billing module): the tenants a platform region is shared with — share status, the platform\'s queue consumer choice for each (queue_consumers_override: false = not charged to that tenant, null = as the region) and whether the platform\'s bill to the tenant charges them (queue_consumers_charged) — and the region\'s own setting. billing_region_organization_set changes one tenant\'s.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('The platform region (id).', ['minLength' => 1, 'maxLength' => 64]),
        ], ['region']);
    }

    public function permissions(): array
    {
        return ['@platform:billing.view'];
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get(InternalApi::path('billing', 'regions', (string) $arguments['region'], 'organizations'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the tenants of the region');
        }

        $rows = array_values((array) ($result->get('data') ?? []));
        $off = array_filter($rows, fn ($r) => ($r['queue_consumers_override'] ?? null) === false);

        return ToolResult::ok(['charge_queue_consumers' => (bool) $result->get('charge_queue_consumers'), 'organizations' => $rows],
            sprintf('The region %s queue consumers; shared with %d tenant(s)%s.',
                $result->get('charge_queue_consumers') ? 'charges' : 'does not charge', count($rows),
                $off !== [] ? ', switched off for '.implode(', ', array_map(fn ($r) => (string) ($r['tenant']['slug'] ?? $r['tenant']['id'] ?? ''), $off)) : ''));
    }
}
