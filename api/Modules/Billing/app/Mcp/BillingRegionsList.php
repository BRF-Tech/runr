<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_regions_list (docs/billing.md#region-billing-types, #queue-consumers): the regions of the
 * organization with their billing type, current prices, allocations, and whether queue consumers
 * are charged there — the region's setting, the platform's choice for the tenant on a shared
 * region, the organization's own choice, and what applies — through GET /api/billing/regions.
 */
class BillingRegionsList extends BillingTool
{
    public function name(): string
    {
        return 'billing_regions_list';
    }

    public function title(): string
    {
        return 'Regions and their billing';
    }

    public function description(): string
    {
        return 'The organization\'s regions in billing (billing module): own or shared, billing type (payg or fixed) and whether it can change, active fixed allocations and pending requests, whether GPUs are metered, the current prices and scheduled versions, the markup of a shared region, and queue consumer charging at every level — charge_queue_consumers (the region owner\'s setting), queue_consumers_owner_override (on a shared region, the platform\'s choice for this tenant), queue_consumers_override (the organization\'s own choice) and queue_consumers_charged (what applies to its projects). Needs billing.view; billing_queue_consumers_set changes the settings.';
    }

    public function inputSchema(): array
    {
        return Schema::object();
    }

    public function permissions(): array
    {
        return ['billing.view'];
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $result = $context->api()->get('billing/regions');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the regions');
        }

        $rows = array_values((array) ($result->get('data') ?? []));
        $lines = array_map(fn ($r) => sprintf('%s: %s%s, queue consumers %s',
            (string) ($r['name'] ?? ''), (string) ($r['billing_type'] ?? ''), ($r['own'] ?? true) ? '' : ' (shared)',
            ($r['queue_consumers_charged'] ?? false) ? 'charged' : 'not charged'), $rows);

        return ToolResult::ok(['currency' => $result->get('currency'), 'regions' => $rows],
            $rows === [] ? 'No regions.' : count($rows).' region(s). '.implode('; ', $lines).'.');
    }
}
