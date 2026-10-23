<?php

namespace Modules\Billing\Services;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Money;
use Modules\Billing\Support\Period;
use Modules\Billing\Support\ResolvedPrice;

/**
 * Calculates a bill (docs/billing.md#how-a-bill-is-calculated) over the days it covers — its
 * month, or the part of it billing was on (period_start … period_end):
 *
 *  - fixed lines: every allocation of the project in those days, at the region's monthly unit
 *    prices of each day (× (1 + markup)) / days of the month — the first month and every change
 *    are prorated by day from the approval (BillingPolicy decision 2); consecutive days at the same
 *    price form one line. An allocation still in effect counts to the end of the bill.
 *  - usage lines: metered usage at the pay-as-you-go prices of its day (× trigger multiplier ×
 *    (1 + markup)), grouped by environment, region, metric, trigger, price version and markup
 *    version — a price change in the month splits a line. A project's line says which environment
 *    its usage came from (environment_id / environment_name: production or one of its
 *    environments, docs/environments.md); a project that ran in an environment besides production
 *    names it in the description of each line too.
 *  - coverage: a project's own usage in a region on a day an allocation there is in effect is
 *    covered by the fixed price (metered_lines, not billed); metered_cost is the cost of all the
 *    project's usage at pay-as-you-go prices, difference = total − metered_cost.
 *
 * Money is exact (Money): a line's amount is calculated from exact prices and quantities and
 * rounded once, half up, to the currency's minor unit; a bill's totals are the sums of its rounded
 * lines. The quote of fixed resources uses the same calculation (firstMonth()).
 *
 * @phpstan-type Result array{line_items: list<array<string, mixed>>, metered_lines: list<array<string, mixed>>,
 *     fixed_total: float, usage_total: float, total: float, metered_cost: float, billing_type: string|null}
 */
class BillCalculator
{
    private const ENTRY_POINTS = ['http' => 'HTTP', 'queue' => 'Queue', 'schedule' => 'Schedule'];

    private const BINDING_TYPES = ['kv' => 'KV', 'object' => 'Object storage', 'postgres' => 'Postgres'];

    /** Parts of a project billed as lines of their own (usage_records.source, UsageReader component). */
    private const COMPONENTS = ['preview' => 'preview environments', 'queue_consumer' => 'queue consumer'];

    /** environment_name of usage of an environment that was deleted before its name was recorded. */
    public const DELETED_ENVIRONMENT = 'deleted environment';

    public function __construct(
        private readonly PriceBook $prices,
        private readonly Regions $regions,
        private readonly UsageReader $usage,
        private readonly RegionTypes $types,
    ) {}

    /** @return Result */
    public function forBill(Bill $bill, array $names = []): array
    {
        $period = $bill->periodObject();
        $from = $bill->period_start->toDateString();
        $to = $bill->period_end->toDateString();

        return match ($bill->kind) {
            Bill::PROJECT => $this->forProject((string) $bill->project_id, $period, $from, $to),
            Bill::ACCOUNT => $this->forAccount($bill->owner_id, $period, $from, $to),
            Bill::TENANT => $this->forTenant((string) $bill->tenant_id, $period, $names, $from, $to),
            default => $this->result([], [], []),
        };
    }

    /**
     * @param  string|null  $from  first day billed (default: the first of the month)
     * @param  string|null  $to  last day billed (default: the last of the month)
     * @return Result
     */
    public function forProject(string $projectId, Period $period, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->window($period, $from, $to);
        $allocations = $this->allocations(fn ($q) => $q->own()->where('project_id', $projectId), $from, $to);
        $fixed = $this->fixedLines($allocations, $period, $from, $to, PriceBook::CONTEXT, []);
        [$billed, $covered] = $this->usageLines($this->within($this->usage->projectUsage($projectId, $period), $from, $to), $allocations, PriceBook::CONTEXT, []);

        $project = null;
        try {
            $project = Project::query()->find($projectId, ['id', 'knative_region_id']);
        } catch (QueryException) {
        }

        return $this->result($fixed, $billed, $covered, $project ? $this->types->typeOf($project->knative_region_id) : null);
    }

    /**
     * What belongs to no project (model services, bindings, sandboxes), billed to its creator
     * ($ownerId null: the creator is unknown).
     *
     * @return Result
     */
    public function forAccount(?string $ownerId, Period $period, ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->window($period, $from, $to);
        $entries = array_values(array_filter(
            $this->within($this->usage->accountUsage($period), $from, $to),
            fn (array $e) => ($e['owner'] ?? null) === $ownerId,
        ));
        [$billed] = $this->usageLines($entries, new Collection, PriceBook::CONTEXT, []);

        return $this->result([], $billed, []);
    }

    /**
     * Platform → tenant: the tenant's usage of platform regions and its allocations there, at
     * platform prices without the tenant's markup, one line per project (or resource) and region.
     *
     * @param  array<string, string>  $names  project / model service id → name (the tenant's)
     * @return Result
     */
    public function forTenant(string $tenantId, Period $period, array $names = [], ?string $from = null, ?string $to = null): array
    {
        [$from, $to] = $this->window($period, $from, $to);
        $allocations = $this->allocations(fn ($q) => $q->where('tenant_id', $tenantId), $from, $to, BillingContext::central());
        $fixed = $this->fixedLines($allocations, $period, $from, $to, PriceBook::PLATFORM, $names);
        [$billed, $covered] = $this->usageLines($this->within($this->usage->tenantUsage($tenantId, $period), $from, $to), $allocations, PriceBook::PLATFORM, $names);

        return $this->result($fixed, $billed, $covered);
    }

    /**
     * Pay-as-you-go cost of a set of usage entries, coverage ignored (GET billing/usage).
     *
     * @param  list<array<string, mixed>>  $entries
     */
    public function meteredCost(array $entries, string $mode = PriceBook::CONTEXT): float
    {
        [$billed] = $this->usageLines($entries, new Collection, $mode, []);

        return Money::toFloat(Money::sum(array_column($billed, 'total')));
    }

    /**
     * Monthly price of fixed resources at the prices in effect on $day (markup included), rounded
     * half up to the minor unit; the breakdown rounded per resource.
     *
     * @return array{monthly_price: float, breakdown: array{cpu: float, memory: float, gpu: float}, price: ResolvedPrice}
     */
    public function monthlyPrice(?string $regionId, int $cpuM, int $memoryMb, int $gpuCount, ?CarbonImmutable $day = null): array
    {
        $price = $this->prices->resolve($regionId, $day ?? CarbonImmutable::today());
        $cost = $this->monthlyCost($cpuM, $memoryMb, $gpuCount, $price);

        return [
            'monthly_price' => Money::roundedFloat($cost['total']),
            'breakdown' => ['cpu' => Money::roundedFloat($cost['cpu']), 'memory' => Money::roundedFloat($cost['memory']), 'gpu' => Money::roundedFloat($cost['gpu'])],
            'price' => $price,
        ];
    }

    /**
     * What the resources cost from $from to the end of its month — exactly what the bill charges
     * for an allocation approved on $from (the same fixed lines, prices of each day).
     *
     * @return array{from: string, to: string, days: int, amount: float}
     */
    public function firstMonth(?string $regionId, int $cpuM, int $memoryMb, int $gpuCount, CarbonImmutable $from): array
    {
        $period = Period::of($from);
        $allocation = (new FixedRequest)->forceFill([
            'project_id' => '', 'region_id' => (string) $regionId, 'cpu_m' => $cpuM, 'memory_mb' => $memoryMb,
            'gpu_count' => $gpuCount, 'status' => FixedRequest::APPROVED, 'active_from' => $from->toDateString(),
        ]);
        $lines = $this->fixedLines(new Collection([$allocation]), $period, $from->toDateString(), $period->endDate(), PriceBook::CONTEXT, []);

        return [
            'from' => $from->toDateString(),
            'to' => $period->endDate(),
            'days' => $period->days() - $from->day + 1,
            'amount' => Money::toFloat(Money::sum(array_column($lines, 'total'))),
        ];
    }

    // ── Fixed ───────────────────────────────────────────────────────────────

    /**
     * Monthly cost of resources at a price, markup included, exact.
     *
     * @return array{cpu: BigDecimal, memory: BigDecimal, gpu: BigDecimal, total: BigDecimal}
     */
    private function monthlyCost(int $cpuM, int $memoryMb, int $gpuCount, ResolvedPrice $price): array
    {
        $factor = $price->markupFactorExact();
        $cpu = BigDecimal::of($cpuM)->exactlyDividedBy(1000)->multipliedBy($price->exact('cpu_vcpu_month'))->multipliedBy($factor);
        $memory = BigDecimal::of($memoryMb)->exactlyDividedBy(1024)->multipliedBy($price->exact('memory_gb_month'))->multipliedBy($factor);
        $gpu = BigDecimal::of($gpuCount)->multipliedBy($price->exact('gpu_month'))->multipliedBy($factor);

        return ['cpu' => $cpu, 'memory' => $memory, 'gpu' => $gpu, 'total' => $cpu->plus($memory)->plus($gpu)];
    }

    /**
     * Allocations (approved at some point) overlapping $from … $to.
     *
     * @param  callable(Builder<FixedRequest>): mixed  $scope
     * @return Collection<int, FixedRequest>
     */
    private function allocations(callable $scope, string $from, string $to, ?string $connection = null): Collection
    {
        try {
            $query = FixedRequest::on($connection)->whereIn('status', FixedRequest::ALLOCATED)->whereNotNull('active_from')
                ->whereDate('active_from', '<=', $to)
                ->where(fn ($q) => $q->whereNull('active_until')->orWhereDate('active_until', '>=', $from));
            $scope($query);

            return $query->orderBy('active_from')->get();
        } catch (QueryException) {
            return new Collection;
        }
    }

    /**
     * @param  Collection<int, FixedRequest>  $allocations
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function fixedLines(Collection $allocations, Period $period, string $from, string $to, string $mode, array $names): array
    {
        $lines = [];
        $days = $period->days();

        foreach ($allocations as $allocation) {
            $first = max($allocation->active_from->toDateString(), $from);
            $last = min($allocation->active_until?->toDateString() ?? $to, $to);
            if ($first > $last) {
                continue;
            }

            $current = null;
            for ($day = CarbonImmutable::parse($first); $day->toDateString() <= $last; $day = $day->addDay()) {
                $price = $this->prices->resolve($allocation->region_id, $day, $mode);
                $key = $price->versionKey();
                if ($current !== null && $current['key'] === $key) {
                    $current['days']++;
                    $current['to'] = $day->toDateString();

                    continue;
                }
                if ($current !== null) {
                    $lines[] = $this->fixedLine($allocation, $current, $days, $names);
                }
                $monthly = $this->monthlyCost($allocation->cpu_m, $allocation->memory_mb, $allocation->gpu_count, $price)['total'];
                $current = ['key' => $key, 'price' => $price, 'monthly' => $monthly, 'days' => 1, 'from' => $day->toDateString(), 'to' => $day->toDateString()];
            }
            if ($current !== null) {
                $lines[] = $this->fixedLine($allocation, $current, $days, $names);
            }
        }

        return $lines;
    }

    /**
     * One run of days at the same price: monthly cost × days ÷ days of the month, rounded once.
     *
     * @param  array{price: ResolvedPrice, monthly: BigDecimal, days: int, from: string, to: string}  $run
     * @param  array<string, string>  $names
     */
    private function fixedLine(FixedRequest $allocation, array $run, int $daysInMonth, array $names): array
    {
        $parts = [rtrim(rtrim(number_format($allocation->cpu_m / 1000, 3, '.', ''), '0'), '.').' vCPU', rtrim(rtrim(number_format($allocation->memory_mb / 1024, 3, '.', ''), '0'), '.').' GB memory'];
        if ($allocation->gpu_count > 0) {
            $parts[] = $allocation->gpu_count.' GPU';
        }
        $region = $this->regions->name($allocation->region_id) ?? $allocation->region_id;
        $projectName = $names[$allocation->project_id] ?? $allocation->project_name;
        $prefix = $allocation->tenant_id !== null ? ($projectName ?? $allocation->project_id).': ' : '';
        $daily = BigRational::of($run['monthly'])->dividedBy($daysInMonth);

        return [
            'type' => 'fixed',
            'metric' => 'fixed_resources',
            'description' => $prefix.'Fixed resources '.implode(' · ', $parts).' ('.$region.')',
            'region_id' => $allocation->region_id,
            'region_name' => $region,
            'project_id' => $allocation->project_id,
            'project_name' => $projectName,
            'resource_type' => null,
            'resource_id' => $allocation->tenant_id !== null ? $allocation->source_id : $allocation->id,
            'resource_name' => null,
            'entry_point' => null,
            'quantity' => $run['days'],
            'unit' => 'days',
            'unit_price' => Money::roundedFloat($daily, 6),
            'multiplier' => 1.0,
            'markup_percent' => round($run['price']->markupPercent, 3),
            'total' => Money::roundedFloat($daily->multipliedBy($run['days'])),
            'from' => $run['from'],
            'to' => $run['to'],
            'price_id' => $run['price']->priceId,
            'metadata' => [
                'fixed_request_id' => $allocation->tenant_id !== null ? $allocation->source_id : $allocation->id,
                'cpu_m' => $allocation->cpu_m,
                'memory_mb' => $allocation->memory_mb,
                'gpu_count' => $allocation->gpu_count,
                'price_source' => $run['price']->source,
            ],
        ];
    }

    // ── Usage ───────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  Collection<int, FixedRequest>  $allocations
     * @param  array<string, string>  $names
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [billed, covered]
     */
    private function usageLines(array $entries, Collection $allocations, string $mode, array $names): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $covered = $entry['kind'] === 'project' && $allocations->contains(
                fn (FixedRequest $a) => $a->project_id === $entry['project_id'] && $a->region_id === $entry['region_id'] && $a->coversDay($entry['date'])
            );
            $price = $this->prices->resolve($entry['region_id'], $entry['date'], $mode);

            foreach ($this->metrics($entry, $price) as [$metric, $quantity, $unitPrice, $multiplier, $unit]) {
                if ($quantity->isNegativeOrZero()) {
                    continue;
                }

                $key = implode('|', [
                    $covered ? 'c' : 'b', $entry['kind'], $entry['project_id'] ?? '', $entry['environment_id'] ?? '', $entry['resource_id'] ?? '',
                    $entry['region_id'] ?? '', $entry['entry_point'] ?? '', $entry['component'] ?? '', $metric, $price->versionKey(),
                ]);

                $groups[$key] ??= [
                    'covered' => $covered,
                    'entry' => $entry,
                    'metric' => $metric,
                    'unit' => $unit,
                    'unit_price' => $unitPrice,
                    'multiplier' => $multiplier,
                    'price' => $price,
                    'quantity' => BigRational::zero(),
                    'from' => $entry['date'],
                    'to' => $entry['date'],
                ];
                $groups[$key]['quantity'] = $groups[$key]['quantity']->plus($quantity);
                $groups[$key]['from'] = min($groups[$key]['from'], $entry['date']);
                $groups[$key]['to'] = max($groups[$key]['to'], $entry['date']);
            }
        }

        // Projects that ran in an environment besides production: every line of theirs names its
        // environment (docs/billing.md#environments)
        $environmentNames = $this->environmentNames($entries, $names);
        $withEnvironments = [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'project' && ($entry['environment_id'] ?? null) !== null) {
                $withEnvironments[(string) $entry['project_id']] = true;
            }
        }

        $billed = [];
        $covered = [];
        foreach ($groups as $group) {
            $line = $this->usageLine($group, $names, $environmentNames, isset($withEnvironments[(string) ($group['entry']['project_id'] ?? '')]));
            if ($group['covered']) {
                $covered[] = $line;
            } else {
                $billed[] = $line;
            }
        }

        // Per project its production lines first, then each environment's
        $environmentOrder = fn (array $line) => ($line['environment_id'] ?? null) === null ? '' : '~'.($line['environment_name'] ?? '');
        $sort = fn (array $a, array $b) => [$a['project_name'] ?? '', $environmentOrder($a), $a['region_name'] ?? '', $a['description'], $a['from']]
            <=> [$b['project_name'] ?? '', $environmentOrder($b), $b['region_name'] ?? '', $b['description'], $b['from']];
        usort($billed, $sort);
        usort($covered, $sort);

        return [$billed, $covered];
    }

    /**
     * Metrics of one usage entry: [metric, quantity, unit price, multiplier, unit], exact.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{0: string, 1: BigRational, 2: BigDecimal, 3: BigDecimal, 4: string}>
     */
    private function metrics(array $entry, ResolvedPrice $price): array
    {
        $one = BigDecimal::one();
        $quantity = fn (string $field) => $entry['exact'][$field] ?? BigRational::of(Money::dec((float) ($entry[$field] ?? 0)));

        return match ($entry['kind']) {
            'binding' => [
                ['binding_storage_gb_hours', $quantity('storage_gb_hours'), $price->exact(match ($entry['resource_type']) {
                    'kv' => 'kv_gb_hour',
                    'object' => 'object_gb_hour',
                    default => 'postgres_gb_hour',
                }), $one, 'GB-hours'],
                ['binding_instance_hours', $quantity('instance_hours'), $price->exact('postgres_instance_hour'), $one, 'instance-hours'],
            ],
            'sandbox' => [
                ['sandbox_vcpu_hours', $quantity('vcpu_hours'), $price->exact('cpu_vcpu_hour'), $one, 'vCPU-hours'],
                ['sandbox_memory_gb_hours', $quantity('memory_gb_hours'), $price->exact('memory_gb_hour'), $one, 'GB-hours'],
            ],
            default => [
                ['vcpu_hours', $quantity('vcpu_hours'), $price->exact('cpu_vcpu_hour'), $price->multiplierExact($entry['entry_point']), 'vCPU-hours'],
                ['memory_gb_hours', $quantity('memory_gb_hours'), $price->exact('memory_gb_hour'), $price->multiplierExact($entry['entry_point']), 'GB-hours'],
                ['gpu_hours', $quantity('gpu_hours'), $price->exact('gpu_hour'), $price->multiplierExact($entry['entry_point']), 'GPU-hours'],
            ],
        };
    }

    /**
     * Names of the environments the entries' usage came from (environment id → name): the name
     * recorded with the usage (usage_records.environment_name), else $names (a tenant's, read in its
     * schema), else the environment as it is now; an environment deleted before its name was
     * recorded is DELETED_ENVIRONMENT.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, string>  $names
     * @return array<string, string>
     */
    public function environmentNames(array $entries, array $names = []): array
    {
        $resolved = [];
        $missing = [];
        foreach ($entries as $entry) {
            $id = $entry['environment_id'] ?? null;
            if ($id === null || isset($resolved[$id])) {
                continue;
            }
            $name = $entry['environment_name'] ?? null;
            $name ??= $names[$id] ?? null;
            if ($name !== null && $name !== '') {
                $resolved[$id] = (string) $name;
            } else {
                $missing[$id] = true;
            }
        }

        $missing = array_keys(array_diff_key($missing, $resolved));
        if ($missing !== []) {
            try {
                if (ProjectEnvironment::tablesReady()) {
                    $resolved += ProjectEnvironment::query()->whereIn('id', $missing)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
                }
            } catch (QueryException) {
            }
            foreach ($missing as $id) {
                $resolved[$id] ??= self::DELETED_ENVIRONMENT;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, string>  $names
     * @param  array<string, string>  $environmentNames  environmentNames()
     * @param  bool  $namesEnvironment  the project ran in an environment besides production: the
     *                                  description names the environment of every line of it
     */
    private function usageLine(array $group, array $names, array $environmentNames = [], bool $namesEnvironment = false): array
    {
        /** @var array<string, mixed> $entry */
        $entry = $group['entry'];
        /** @var ResolvedPrice $price */
        $price = $group['price'];
        /** @var BigRational $quantity */
        $quantity = $group['quantity'];
        $region = $this->regions->name($entry['region_id']) ?? (string) $entry['region_id'];
        $projectName = $entry['project_id'] !== null ? ($names[$entry['project_id']] ?? null) : null;
        $resourceName = $entry['resource_name'] ?? ($entry['resource_id'] !== null ? ($names[$entry['resource_id']] ?? null) : null);

        $label = match ($group['metric']) {
            'vcpu_hours', 'sandbox_vcpu_hours' => 'vCPU hours',
            'memory_gb_hours', 'sandbox_memory_gb_hours' => 'Memory GB-hours',
            'gpu_hours' => 'GPU hours',
            'binding_storage_gb_hours' => 'storage GB-hours',
            'binding_instance_hours' => 'instance hours',
            default => (string) $group['metric'],
        };
        $subject = match ($entry['kind']) {
            'model' => 'Model service '.($resourceName ?? $entry['resource_id']).': ',
            'binding' => 'Binding '.($resourceName ?? $entry['resource_id']).' ('.(self::BINDING_TYPES[$entry['resource_type']] ?? $entry['resource_type']).'): ',
            'sandbox' => 'Sandboxes: ',
            default => $projectName !== null ? $projectName.': ' : '',
        };
        // A project's previews and queue consumer are lines of their own
        $component = $entry['kind'] === 'project' ? ($entry['component'] ?? null) : null;
        if ($component !== null) {
            $label .= ' — '.(self::COMPONENTS[$component] ?? $component);
        }
        $trigger = in_array($entry['kind'], ['project', 'model'], true) && $entry['entry_point']
            ? ', '.(self::ENTRY_POINTS[$entry['entry_point']] ?? ucfirst((string) $entry['entry_point']))
            : '';
        // The environment a project's usage came from (docs/environments.md): production — the
        // project's own workload — or one of its environments; previews are none of them
        $environmentId = $entry['kind'] === 'project' ? ($entry['environment_id'] ?? null) : null;
        $environmentName = match (true) {
            $entry['kind'] !== 'project' || $component === 'preview' => null,
            $environmentId === null => ProjectEnvironment::PRIMARY_NAME,
            default => $environmentNames[$environmentId] ?? self::DELETED_ENVIRONMENT,
        };
        $where = $namesEnvironment && $environmentName !== null ? $environmentName.', ' : '';
        $amount = $quantity->multipliedBy($group['unit_price'])->multipliedBy($group['multiplier'])->multipliedBy($price->markupFactorExact());

        return [
            'type' => 'usage',
            'metric' => $group['metric'],
            'description' => $subject.$label.' ('.$where.$region.$trigger.')',
            'region_id' => $entry['region_id'],
            'region_name' => $region,
            'project_id' => $entry['project_id'],
            'project_name' => $projectName,
            'resource_type' => $entry['resource_type'],
            'resource_id' => $entry['resource_id'],
            'resource_name' => $resourceName,
            'entry_point' => in_array($entry['kind'], ['project', 'model'], true) ? $entry['entry_point'] : null,
            'component' => $component,
            'environment_id' => $environmentId,
            'environment_name' => $environmentName,
            'quantity' => Money::roundedFloat($quantity, 4),
            'unit' => $group['unit'],
            'unit_price' => Money::roundedFloat($group['unit_price'], 8),
            'multiplier' => Money::roundedFloat($group['multiplier'], 3),
            'markup_percent' => round($price->markupPercent, 3),
            'total' => Money::roundedFloat($amount),
            'from' => $group['from'],
            'to' => $group['to'],
            'price_id' => $price->priceId,
            'metadata' => ['price_source' => $price->source],
        ];
    }

    // ── Result ──────────────────────────────────────────────────────────────

    /**
     * The days billed: $from … $to within the period (default: the whole month).
     *
     * @return array{0: string, 1: string}
     */
    private function window(Period $period, ?string $from, ?string $to): array
    {
        return [max($from ?? $period->startDate(), $period->startDate()), min($to ?? $period->endDate(), $period->endDate())];
    }

    /**
     * Usage entries of the days billed.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function within(array $entries, string $from, string $to): array
    {
        return array_values(array_filter($entries, fn (array $e) => $e['date'] >= $from && $e['date'] <= $to));
    }

    /**
     * @param  list<array<string, mixed>>  $fixed
     * @param  list<array<string, mixed>>  $billed
     * @param  list<array<string, mixed>>  $covered
     * @return Result
     */
    private function result(array $fixed, array $billed, array $covered, ?string $regionType = null): array
    {
        // Sums of the rounded lines
        $fixedTotal = Money::sum(array_column($fixed, 'total'));
        $usageTotal = Money::sum(array_column($billed, 'total'));
        $coveredTotal = Money::sum(array_column($covered, 'total'));

        $type = match (true) {
            $fixed !== [] && $billed !== [] => 'mixed',
            $fixed !== [] => 'fixed',
            $billed !== [] => 'payg',
            default => $regionType,
        };

        return [
            'line_items' => array_merge($fixed, $billed),
            'metered_lines' => $covered,
            'fixed_total' => Money::toFloat($fixedTotal),
            'usage_total' => Money::toFloat($usageTotal),
            'total' => Money::toFloat($fixedTotal->plus($usageTotal)),
            'metered_cost' => Money::toFloat($usageTotal->plus($coveredTotal)),
            'billing_type' => $type,
        ];
    }
}
