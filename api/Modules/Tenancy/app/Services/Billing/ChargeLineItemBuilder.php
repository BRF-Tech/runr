<?php

namespace Modules\Tenancy\Services\Billing;

use Illuminate\Support\Collection;
use Modules\Tenancy\Models\CapacityDeal;
use Modules\Tenancy\Models\Charge;

/**
 * Line items of a charge (docs/billing.md):
 *
 *  - a fixed charge (capacity deal, plan, manual) is one line: its amount — "Capacity deal: …"
 *    (1 month), the plan fee, or the charge's description;
 *  - a usage charge has one line per project × region × scale mode × entry point from the
 *    tenant's usage_records of the period (scale-to-zero in "sessions", custom scale and model
 *    services in "days"), one line per binding (GB-hours / instance-hours) and one line per region
 *    of sandbox usage; total = Σ final_cost.
 *
 * Amounts are rounded to 2 decimals; lines that round to 0 are left out. Only usage in the
 * charge's currency is rated on it (usage in another currency has a usage charge of its own).
 *
 * @phpstan-type LineItem array{description: string, quantity: int|float, unit: string, unit_price: float, total: float, metadata: array<string, mixed>}
 */
class ChargeLineItemBuilder
{
    private const ENTRY_POINTS = ['http' => 'HTTP', 'queue' => 'Queue', 'schedule' => 'Schedule'];

    private const SCALE_MODES = ['scale_to_zero' => 'Scale-to-Zero', 'custom_scale' => 'Custom Scale'];

    private const BINDING_TYPES = ['kv' => 'KV', 'object' => 'Object storage', 'postgres' => 'Postgres'];

    /**
     * The single line of a capacity deal, plan or manual charge (null when its amount is 0).
     *
     * @return LineItem|null
     */
    public function fixedItem(Charge $charge, ?CapacityDeal $deal = null, ?string $regionName = null): ?array
    {
        $amount = round((float) $charge->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        if ($deal) {
            return [
                'description' => sprintf(
                    'Capacity deal: %dm CPU, %d MB memory%s',
                    (int) $deal->cpu_m,
                    (int) $deal->memory_mb,
                    $regionName ? " ({$regionName})" : '',
                ),
                'quantity' => 1,
                'unit' => 'month',
                'unit_price' => $amount,
                'total' => $amount,
                'metadata' => [
                    'capacity_deal_id' => $deal->id,
                    'region_id' => $deal->region_id,
                    'cpu_m' => (int) $deal->cpu_m,
                    'memory_mb' => (int) $deal->memory_mb,
                ],
            ];
        }

        return [
            'description' => $charge->description(),
            'quantity' => 1,
            'unit' => $charge->kind === Charge::KIND_PLAN ? 'period' : 'item',
            'unit_price' => $amount,
            'total' => $amount,
            'metadata' => ['kind' => $charge->kind],
        ];
    }

    /**
     * Usage lines of one currency.
     *
     * @param  Collection  $usage  usage records of the period (UsageRecord)
     * @param  array<string, string>  $names  project / model service id → name
     * @param  Collection|null  $bindingUsage  binding usage records of the period (BindingUsageRecord)
     * @param  list<array>  $sandboxItems  sandbox lines of the period (SandboxUsageAggregator::chargeItems)
     * @return list<LineItem>
     */
    public function usageItems(string $currency, Collection $usage, array $names = [], ?Collection $bindingUsage = null, array $sandboxItems = []): array
    {
        $currency = strtoupper($currency);

        return array_merge(
            $this->meteredItems($currency, $usage, $names),
            $this->bindingItems($currency, $bindingUsage ?? collect()),
            $sandboxItems,
        );
    }

    /** @param  list<array{total: float|int|string}>  $items */
    public static function total(array $items): float
    {
        return round(array_sum(array_map(fn ($i) => (float) $i['total'], $items)), 2);
    }

    /** @return list<LineItem> */
    private function meteredItems(string $currency, Collection $usage, array $names): array
    {
        $groups = $usage
            ->filter(fn ($u) => strtoupper((string) $u->currency) === $currency)
            ->groupBy(fn ($u) => implode('|', [$u->project_id, $u->region_id, $u->scale_mode, $u->entry_point]));

        $items = [];
        foreach ($groups as $group) {
            $first = $group->first();
            $total = round((float) $group->sum(fn ($u) => (float) $u->final_cost), 2);
            if ($total <= 0) {
                continue;
            }

            // Model services (source "model") are metered per day, not per session
            $isModel = ($first->source ?? 'project') === 'model';
            $sessions = $first->scale_mode === 'scale_to_zero' && ! $isModel;
            $quantity = $sessions ? (int) $group->sum('session_count') : $group->count();
            $latest = $group->sortBy(fn ($u) => (string) $u->record_date)->last();
            $name = $names[$first->project_id] ?? $first->project_id;

            $items[] = [
                'description' => sprintf(
                    $isModel ? 'Model service: %s (%s, %s)' : 'Project: %s (%s, %s)',
                    $name,
                    self::ENTRY_POINTS[$first->entry_point] ?? ucfirst((string) $first->entry_point),
                    self::SCALE_MODES[$first->scale_mode] ?? (string) $first->scale_mode,
                ),
                'quantity' => $quantity,
                'unit' => $sessions ? 'sessions' : 'days',
                'unit_price' => $quantity > 0 ? round($total / $quantity, 6) : $total,
                'total' => $total,
                'metadata' => [
                    'source' => $isModel ? 'model' : 'project',
                    'project_id' => $first->project_id,
                    'region_id' => $first->region_id,
                    'scale_mode' => $first->scale_mode,
                    'entry_point' => $first->entry_point,
                    'multiplier' => (float) $latest->multiplier,
                    'raw_cost' => round((float) $group->sum(fn ($u) => (float) $u->raw_cost), 6),
                    'days' => $group->pluck('record_date')->map(fn ($d) => (string) ($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : $d))->unique()->count(),
                ],
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['description'], $b['description']));

        return $items;
    }

    /**
     * One line per binding (docs/bindings.md): storage in GB-hours (dedicated Postgres without
     * measured storage: instance hours), total = Σ final_cost of its usage records.
     *
     * @return list<LineItem>
     */
    private function bindingItems(string $currency, Collection $usage): array
    {
        $items = [];

        foreach ($usage->filter(fn ($u) => strtoupper((string) $u->currency) === $currency)->groupBy('binding_id') as $group) {
            $first = $group->first();
            $total = round((float) $group->sum(fn ($u) => (float) $u->final_cost), 2);
            if ($total <= 0) {
                continue;
            }

            $gbHours = round((float) $group->sum(fn ($u) => (float) $u->storage_gb_hours), 2);
            $instanceHours = round((float) $group->sum(fn ($u) => (float) $u->instance_hours), 2);
            $byStorage = $gbHours > 0 || $instanceHours <= 0;
            $quantity = $byStorage ? $gbHours : $instanceHours;

            $items[] = [
                'description' => sprintf(
                    'Binding: %s (%s%s)',
                    $first->binding_name,
                    self::BINDING_TYPES[$first->type] ?? ucfirst((string) $first->type),
                    $first->plan === 'dedicated' ? ', dedicated' : '',
                ),
                'quantity' => $quantity,
                'unit' => $byStorage ? 'GB-hours' : 'instance-hours',
                'unit_price' => $quantity > 0 ? round($total / $quantity, 6) : $total,
                'total' => $total,
                'metadata' => [
                    'source' => 'binding',
                    'binding_id' => $first->binding_id,
                    'type' => $first->type,
                    'plan' => $first->plan,
                    'region_id' => $first->region_id,
                    'storage_gb_hours' => $gbHours,
                    'instance_hours' => $instanceHours,
                ],
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['description'], $b['description']));

        return $items;
    }
}
