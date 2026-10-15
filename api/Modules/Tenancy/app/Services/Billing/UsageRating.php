<?php

namespace Modules\Tenancy\Services\Billing;

use App\Models\AiModelService;
use App\Models\BindingUsageRecord;
use App\Models\Project;
use App\Services\Sandboxes\SandboxUsageAggregator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\UsageRecord;

/**
 * Rated usage of a tenant in a date range (docs/billing.md): the usage records the metering wrote
 * (projects and model services in usage_records, bindings in binding_usage_records — both in the
 * tenant's database —, sandboxes in the central sandbox_usage_records), already priced with the
 * region's cost configuration, turned into line items per currency.
 */
class UsageRating
{
    public function __construct(private readonly ChargeLineItemBuilder $builder) {}

    /**
     * Line items per currency (currencies without a billable line are left out).
     *
     * @return array<string, list<array>> currency → line items
     */
    public function rate(string $tenantId, string $from, string $to): array
    {
        // Projects, model services and bindings: rated inside the tenant (their models belong to
        // its connection)
        $rated = $this->inTenant($tenantId, function () use ($from, $to) {
            [$usage, $names, $bindings] = $this->records($from, $to);

            $rated = [];
            $currencies = $usage->pluck('currency')->merge($bindings->pluck('currency'))
                ->map(fn ($c) => strtoupper((string) $c))->filter()->unique();
            foreach ($currencies as $currency) {
                $rated[$currency] = $this->builder->usageItems($currency, $usage, $names, $bindings);
            }

            return $rated;
        });

        // Sandboxes: central sandbox_usage_records
        foreach (SandboxUsageAggregator::currencies($tenantId, $from, $to) as $currency) {
            $rated[$currency] = array_merge($rated[$currency] ?? [], SandboxUsageAggregator::chargeItems($tenantId, $from, $to, $currency));
        }

        $rated = array_filter($rated, fn (array $items) => $items !== []);
        ksort($rated);

        return $rated;
    }

    /**
     * Line items of one currency.
     *
     * @return list<array>
     */
    public function items(string $tenantId, string $from, string $to, string $currency): array
    {
        return $this->rate($tenantId, $from, $to)[strtoupper($currency)] ?? [];
    }

    /**
     * Rated totals per currency.
     *
     * @return array<string, float>
     */
    public function totals(string $tenantId, string $from, string $to): array
    {
        return array_map(fn (array $items) => ChargeLineItemBuilder::total($items), $this->rate($tenantId, $from, $to));
    }

    /**
     * Sessions metered in the range (usage_records.session_count).
     */
    public function sessions(string $tenantId, string $from, string $to): int
    {
        return (int) $this->inTenant($tenantId, fn () => UsageRecord::query()
            ->whereDate('record_date', '>=', $from)
            ->whereDate('record_date', '<=', $to)
            ->sum('session_count'));
    }

    /**
     * Usage records of the range (tenant context).
     *
     * @return array{0: Collection<int, UsageRecord>, 1: array<string, string>, 2: Collection<int, BindingUsageRecord>}
     */
    private function records(string $from, string $to): array
    {
        $usage = UsageRecord::query()
            ->whereDate('record_date', '>=', $from)
            ->whereDate('record_date', '<=', $to)
            ->get();

        $names = $usage->isEmpty() ? [] : Project::query()
            ->whereIn('id', $usage->pluck('project_id')->unique()->all())
            ->pluck('slug', 'id')
            ->all();

        // Usage of model services (source "model") carries the service id
        $modelIds = $usage->where('source', 'model')->pluck('project_id')->unique()->all();
        if ($modelIds !== []) {
            $names += AiModelService::query()->whereIn('id', $modelIds)->pluck('name', 'id')->all();
        }

        // Bindings (docs/bindings.md): storage GB-hours / instance hours of the range
        $bindings = Schema::hasTable('binding_usage_records')
            ? BindingUsageRecord::query()
                ->whereDate('record_date', '>=', $from)
                ->whereDate('record_date', '<=', $to)
                ->get()
            : collect();

        return [$usage, $names, $bindings];
    }

    /**
     * usage_records / projects live in the tenant's database: run inside the tenant when tenancy
     * is on (as is when the request already runs in it), on the current connection otherwise.
     */
    private function inTenant(string $tenantId, callable $callback): mixed
    {
        if (! config('tenancy.enabled')) {
            return $callback();
        }

        if (tenancy()->initialized && (string) tenancy()->tenant?->getTenantKey() === $tenantId) {
            return $callback();
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            throw new \RuntimeException("Tenant {$tenantId} not found.");
        }

        // stancl's run() leaves the tenant initialized when the callback throws: restore the context
        $previous = tenancy()->initialized ? tenancy()->tenant : null;
        try {
            return $tenant->run($callback);
        } catch (\Throwable $e) {
            $previous ? tenancy()->initialize($previous) : tenancy()->end();

            throw $e;
        }
    }
}
