<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_prices_get (docs/billing.md#prices): the organization's default prices — current,
 * scheduled and past versions, what applies — and, with `region`, that region's billing type,
 * current prices (and where they come from), scheduled versions and markup, through GET
 * /api/billing/prices and GET /api/billing/regions.
 */
class BillingPricesGet extends BillingTool
{
    public function name(): string
    {
        return 'billing_prices_get';
    }

    public function title(): string
    {
        return 'Prices';
    }

    public function description(): string
    {
        return 'Prices of the organization (billing module): the default prices — the version in effect, scheduled and past versions — and what applies; with region, that region\'s billing type (payg or fixed), its current prices and where they come from (its own version, the defaults, the platform\'s for a shared region plus the markup) and its scheduled versions. Price fields: hourly prices of vCPU, memory GB and GPU (pay as you go), monthly ones (fixed allocations), binding storage, and the multipliers per trigger. Needs billing.view; billing_price_set schedules a new version.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'region' => Schema::string('A region (id or name): its prices instead of only the defaults.', ['maxLength' => 64]),
        ]);
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
        $result = $context->api()->get('billing/prices');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the prices');
        }
        $prices = (array) $result->json;
        $currency = (string) ($prices['currency'] ?? '');

        $out = [
            'currency' => $currency,
            'context' => $prices['context'] ?? null,
            'fields' => $prices['fields'] ?? [],
            'defaults' => $prices['defaults'] ?? null,
            'effective_defaults' => $prices['effective_defaults'] ?? null,
        ];
        $summary = 'Default prices in '.$currency.self::scheduled((array) ($prices['defaults']['scheduled'] ?? [])).': '.self::headline((array) ($prices['effective_defaults'] ?? []), $currency).'.';

        if (isset($arguments['region'])) {
            $row = self::region($context, (string) $arguments['region']);
            $out['region'] = $row;
            $summary .= sprintf(' Region %s (%s, %s): %s%s.',
                (string) ($row['name'] ?? ''), (string) ($row['billing_type'] ?? ''), ($row['own'] ?? true) ? 'own' : 'shared',
                self::headline((array) ($row['prices'] ?? []), $currency), self::scheduled((array) ($row['scheduled_prices'] ?? [])));
        }

        return ToolResult::ok($out, $summary);
    }

    /** The region row of GET /api/billing/regions, by id or name. */
    public static function region(ToolContext $context, string $reference): array
    {
        $result = $context->api()->get('billing/regions');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the regions');
        }

        foreach ((array) ($result->get('data') ?? []) as $row) {
            if (in_array($reference, [(string) ($row['id'] ?? ''), (string) ($row['name'] ?? '')], true)) {
                return (array) $row;
            }
        }

        throw new ToolError("No region \"{$reference}\" in this organization's billing (billing_regions_list lists them).");
    }

    /** The hourly and monthly prices that matter most, as text. */
    private static function headline(array $prices, string $currency): string
    {
        $parts = [];
        foreach (['cpu_vcpu_hour' => 'per vCPU-hour', 'memory_gb_hour' => 'per GB-hour', 'cpu_vcpu_month' => 'per vCPU-month', 'memory_gb_month' => 'per GB-month'] as $field => $label) {
            if (isset($prices[$field])) {
                $parts[] = rtrim(rtrim(number_format((float) $prices[$field], 6, '.', ''), '0'), '.').' '.$label;
            }
        }
        $source = isset($prices['source']) ? ' (from '.$prices['source'].')' : '';

        return ($parts !== [] ? implode(', ', $parts) : 'none').$source;
    }

    private static function scheduled(array $versions): string
    {
        $dates = array_values(array_filter(array_map(fn ($v) => $v['effective_from'] ?? null, $versions)));

        return $dates === [] ? '' : ' (new version(s) from '.implode(', ', $dates).')';
    }
}
