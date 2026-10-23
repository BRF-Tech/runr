<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_queue_consumers_set (docs/billing.md#queue-consumers): whether queue consumers are
 * charged, at the two levels an organization sets itself —
 *
 *  - level "region": the region owner's setting, on one of the organization's own regions (the
 *    platform's for a platform region): PUT /api/billing/regions/{region} {charge_queue_consumers}
 *  - level "organization": the organization's own choice in a region, its own or a shared one:
 *    PUT /api/billing/regions/{region}/queue-consumers {charge: false|null}
 *
 * The platform's choice per tenant is billing_region_organization_set.
 */
class BillingQueueConsumersSet extends BillingTool
{
    public function name(): string
    {
        return 'billing_queue_consumers_set';
    }

    public function title(): string
    {
        return 'Queue consumer charging';
    }

    public function description(): string
    {
        return 'Whether queue triggers\' consumers (50m CPU, 64 MiB, running while the project is deployed) are charged (billing module). level "region": the region\'s own setting, by its owner — charge true or false; a shared platform region\'s is the platform\'s. level "organization": the organization\'s own choice for its projects in a region, its own or shared — charge false switches it off, null follows the region again (it cannot switch on what the region leaves off). Charging applies only when every level allows it; open bills follow at once. Needs billing.manage; billing_regions_list shows every level.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('The region (id or name).', ['maxLength' => 64]),
            'level' => Schema::enum(['region', 'organization'], 'Which setting.'),
            'charge' => ['type' => ['boolean', 'null'], 'description' => 'region: true or false. organization: false (not charged for the organization\'s projects) or null (as the region).'],
        ], ['region', 'level', 'charge']);
    }

    public function permissions(): array
    {
        return ['billing.manage'];
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $row = BillingPricesGet::region($context, (string) $arguments['region']);
        $region = (string) $row['id'];
        $charge = $arguments['charge'];

        if ($arguments['level'] === 'region') {
            if ($charge === null) {
                throw new ToolError('level "region" takes charge true or false.');
            }
            $result = $context->api()->put(InternalApi::path('billing', 'regions', $region), ['charge_queue_consumers' => (bool) $charge]);
        } else {
            // true is kept as "as the region": the organization cannot switch on what the region leaves off
            $result = $context->api()->put(InternalApi::path('billing', 'regions', $region, 'queue-consumers'), ['charge' => $charge === null ? null : (bool) $charge]);
        }
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The queue consumer setting was not saved');
        }

        $saved = (array) $result->data();

        return ToolResult::ok(['region' => $saved], sprintf('Region %s: the region %s queue consumers%s; the organization\'s choice is %s; its projects\' consumers are %s.',
            (string) ($saved['name'] ?? $region),
            ($saved['charge_queue_consumers'] ?? false) ? 'charges' : 'does not charge',
            array_key_exists('queue_consumers_owner_override', $saved) && $saved['queue_consumers_owner_override'] === false ? ' (the platform switched it off for this organization)' : '',
            match ($saved['queue_consumers_override'] ?? null) {
                false => 'off', true => 'as the region', default => 'as the region'
            },
            ($saved['queue_consumers_charged'] ?? false) ? 'charged' : 'not charged'));
    }
}
