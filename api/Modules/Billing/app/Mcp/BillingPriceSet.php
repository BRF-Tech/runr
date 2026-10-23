<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;
use Illuminate\Support\Carbon;
use Modules\Billing\Support\DefaultPrices;

/**
 * billing_price_set (docs/billing.md#effective-dates): a new price version of the organization —
 * its defaults or one of its own regions — from a date on, through POST /api/billing/prices. The
 * API takes every price: the ones the call leaves out are those of the version that applies just
 * before that date, so an agent changes one price without restating the others. A version of the
 * same scope and date is replaced.
 */
class BillingPriceSet extends BillingTool
{
    public function name(): string
    {
        return 'billing_price_set';
    }

    public function title(): string
    {
        return 'Schedule a price version';
    }

    public function description(): string
    {
        return 'Schedules a new price version (billing module) for the organization\'s default prices or one of its own regions, effective from a date (today or later; the previous version holds until then, so open bills are billed day by day with the right one). Give only the prices that change in prices: the others are copied from the version that applies the day before. A version of the same scope and date is replaced. A shared platform region carries the platform\'s prices (a tenant sets its markup instead). Needs billing.manage.';
    }

    public function inputSchema(): array
    {
        $fields = [];
        foreach (DefaultPrices::fields() as $field) {
            $fields[$field] = ['type' => 'number', 'minimum' => 0, 'maximum' => 1000000000, 'description' => self::fieldDescription($field)];
        }

        return Schema::object([
            'region' => Schema::string('A region of the organization (id or name); without it the default prices.', ['maxLength' => 64]),
            'effective_from' => Schema::string('First day of the version, YYYY-MM-DD (today or later).', ['pattern' => '^\d{4}-\d{2}-\d{2}$']),
            'prices' => Schema::object($fields) + ['description' => 'The prices that change, in the billing currency.'],
            'note' => Schema::string('Why (kept with the version).', ['maxLength' => 255]),
        ], ['effective_from', 'prices']);
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
        $changes = (array) $arguments['prices'];
        if ($changes === []) {
            throw new ToolError('Nothing to change: give at least one price in prices.');
        }
        $from = (string) $arguments['effective_from'];

        $regionId = null;
        if (isset($arguments['region'])) {
            $row = BillingPricesGet::region($context, (string) $arguments['region']);
            $regionId = (string) $row['id'];
            $base = self::versionBefore($from, (array) ($row['scheduled_prices'] ?? []), (array) ($row['prices'] ?? []));
        } else {
            $result = $context->api()->get('billing/prices');
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'Reading the prices');
            }
            $scheduled = (array) ($result->get('defaults.scheduled') ?? []);
            $base = self::versionBefore($from, $scheduled, (array) ($result->get('effective_defaults') ?? []));
        }

        $body = ['effective_from' => $from] + ($regionId !== null ? ['region_id' => $regionId] : []);
        foreach (DefaultPrices::fields() as $field) {
            $body[$field] = array_key_exists($field, $changes) ? (float) $changes[$field] : (float) ($base[$field] ?? DefaultPrices::VALUES[$field]);
        }
        if (isset($arguments['note'])) {
            $body['note'] = (string) $arguments['note'];
        }

        $saved = $context->api()->post('billing/prices', $body);
        if (! $saved->ok()) {
            throw ToolError::fromApi($saved, 'The price version was not saved');
        }

        $version = (array) $saved->data();
        $changed = implode(', ', array_map(fn ($f) => "{$f} {$body[$f]}", array_keys($changes)));

        return ToolResult::ok(['version' => $version, 'changed' => array_keys($changes)],
            sprintf('Price version from %s saved for %s: %s; the other prices as before.', $from, $regionId !== null ? 'region '.($row['name'] ?? $regionId) : 'the defaults', $changed));
    }

    /**
     * The prices in effect the day before $from: the latest scheduled version starting before it,
     * else what applies today.
     *
     * @param  list<array<string, mixed>>  $scheduled
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private static function versionBefore(string $from, array $scheduled, array $current): array
    {
        $base = $current;
        $baseDate = Carbon::today()->toDateString();
        foreach ($scheduled as $version) {
            $date = (string) ($version['effective_from'] ?? '');
            if ($date !== '' && $date < $from && $date >= $baseDate) {
                $base = $version;
                $baseDate = $date;
            }
        }

        return $base;
    }

    private static function fieldDescription(string $field): string
    {
        return match (true) {
            str_starts_with($field, 'multiplier_') => 'Multiplier of the '.substr($field, strlen('multiplier_')).' trigger\'s usage (1 = as priced).',
            str_ends_with($field, '_hour') => 'Price per '.str_replace('_', ' ', substr($field, 0, -5)).'-hour (pay as you go).',
            str_ends_with($field, '_month') => 'Price per '.str_replace('_', ' ', substr($field, 0, -6)).'-month (fixed allocations).',
            default => $field,
        };
    }
}
