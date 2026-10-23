<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_region_organization_set (docs/billing.md#queue-consumers): platform administrators — the
 * platform's queue consumer choice for one tenant in a platform region, through PUT
 * /api/billing/regions/{region}/organizations/{tenant} (@platform:billing.manage). The platform's
 * bill to the tenant and the tenant's own bills follow it.
 */
class BillingRegionOrganizationSet extends BillingTool
{
    public function name(): string
    {
        return 'billing_region_organization_set';
    }

    public function title(): string
    {
        return 'Queue consumer charging for a tenant';
    }

    public function description(): string
    {
        return 'Platform administrators (billing module): switch queue consumer charging off for one tenant in a platform region (charge false) or let it follow the region again (charge null). The platform\'s bill to the tenant and the bills the tenant makes for its projects follow it; it cannot switch on what the region leaves off.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('The platform region (id).', ['minLength' => 1, 'maxLength' => 64]),
            'tenant' => Schema::string('The tenant (id or slug).', ['minLength' => 1, 'maxLength' => 64]),
            'charge' => ['type' => ['boolean', 'null'], 'description' => 'false: not charged to the tenant; null: as the region.'],
        ], ['region', 'tenant', 'charge']);
    }

    public function permissions(): array
    {
        return ['@platform:billing.manage'];
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $charge = $arguments['charge'];
        $result = $context->api()->put(
            InternalApi::path('billing', 'regions', (string) $arguments['region'], 'organizations', (string) $arguments['tenant']),
            ['charge' => $charge === null ? null : (bool) $charge],
        );
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The choice for the tenant was not saved');
        }

        $row = (array) $result->data();

        return ToolResult::ok(['organization' => $row], sprintf('Tenant %s: queue consumers %s by the platform (%s).',
            (string) ($row['tenant']['slug'] ?? $arguments['tenant']),
            ($row['queue_consumers_charged'] ?? false) ? 'charged' : 'not charged',
            ($row['queue_consumers_override'] ?? null) === false ? 'switched off for it' : 'as the region'));
    }
}
