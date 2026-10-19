<?php

namespace Modules\Billing\Services;

use App\Models\AiModelService;
use App\Models\Binding;
use App\Models\BindingUsageRecord;
use App\Models\SandboxUsageRecord;
use App\Models\UsageRecord;
use App\Support\ConvertedOrganization;
use Brick\Math\BigRational;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Money;
use Modules\Billing\Support\Period;

/**
 * The metered usage a bill is calculated from (docs/billing.md#metering-and-bills), read from the
 * usage tables the core metering writes and turned into plain quantities:
 *
 *  - usage_records (source project / model): vCPU-hours, GB-hours, GPU-hours per day, region and
 *    trigger; 0.5.7 custom-scale rows without CPU·time are derived from their reservation
 *  - binding_usage_records: storage GB-hours and dedicated Postgres instance-hours per binding
 *  - sandbox_usage_records (central): vCPU-hours and GB-hours per organization, region and creator
 *
 * The organization's own rows in its database; a tenant's usage of platform regions also from the
 * central copies (tenant_id), which is what the platform bills the tenant for.
 *
 * @phpstan-type Entry array{date: string, kind: string, region_id: string|null, project_id: string|null,
 *     resource_id: string|null, resource_name: string|null, resource_type: string|null, owner: string|null,
 *     entry_point: string|null, vcpu_hours: float, memory_gb_hours: float, gpu_hours: float,
 *     storage_gb_hours: float, instance_hours: float, sessions: int, exact: array<string, BigRational>}
 *
 * The float quantities are for display; exact holds them as exact fractions (milliseconds per hour,
 * stored decimals), which the bills are calculated from (Money).
 */
class UsageReader
{
    private const MS_PER_HOUR = 3_600_000;

    private const MS_PER_DAY = 86_400_000;

    /** @var array<string, bool> */
    private array $columns = [];

    private ?ConvertedOrganization $converted = null;

    /**
     * Usage of one project's workloads in the organization.
     *
     * @return list<array<string, mixed>>
     */
    public function projectUsage(string $projectId, Period $period): array
    {
        return $this->withoutConverted($this->usageRows($period, fn (Builder $q) => $q->where('project_id', $projectId)->where(fn ($w) => $w->whereNull('source')->orWhere('source', 'project'))));
    }

    /**
     * Usage of every project of the organization (project id → entries).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function projectsUsage(Period $period): array
    {
        $byProject = [];
        foreach ($this->withoutConverted($this->usageRows($period, fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('source')->orWhere('source', 'project')))) as $entry) {
            $byProject[(string) $entry['project_id']][] = $entry;
        }

        return $byProject;
    }

    /**
     * Usage that belongs to no project — model services, bindings, sandboxes — with the user it is
     * billed to (owner: the creator; null when unknown).
     *
     * @return list<array<string, mixed>>
     */
    public function accountUsage(Period $period): array
    {
        return $this->withoutConverted(array_merge(
            $this->modelUsage($period),
            $this->bindingUsage($period, null),
            $this->sandboxUsage($period, BillingContext::tenantKey(), false),
        ));
    }

    /**
     * Central context of a converted installation: from the conversion day on, the projects, model
     * services and bindings copied into the tenant are billed by the tenant only
     * (ConvertedOrganization); their earlier usage stays on the central bills.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function withoutConverted(array $entries): array
    {
        $this->converted ??= new ConvertedOrganization;
        $since = $this->converted->since();
        if ($since === null) {
            return $entries;
        }

        return array_values(array_filter($entries, fn (array $e) => $e['date'] < $since
            || ! ($this->converted->contains($e['project_id'] ?? null) || $this->converted->contains($e['resource_id'] ?? null))));
    }

    /**
     * A tenant's usage of platform regions from the central copies (platform → tenant bills; run
     * in the central context).
     *
     * @return list<array<string, mixed>>
     */
    public function tenantUsage(string $tenantId, Period $period): array
    {
        $central = BillingContext::central();

        $records = $this->hasColumn('usage_records', 'tenant_id', $central)
            ? $this->usageRows($period, fn (Builder $q) => $q->where('tenant_id', $tenantId), $central, false)
            : [];

        return array_merge(
            $records,
            $this->bindingUsage($period, $tenantId),
            $this->sandboxUsage($period, $tenantId, true),
        );
    }

    /**
     * Tenants with usage copied to the central tables in the period.
     *
     * @return list<string>
     */
    public function tenantsWithUsage(Period $period): array
    {
        $central = BillingContext::central();
        $ids = collect();

        foreach (['usage_records' => UsageRecord::class, 'binding_usage_records' => BindingUsageRecord::class] as $table => $model) {
            if ($this->hasColumn($table, 'tenant_id', $central)) {
                $ids = $ids->merge($model::on($central)->whereNotNull('tenant_id')
                    ->whereDate('record_date', '>=', $period->startDate())->whereDate('record_date', '<=', $period->endDate())
                    ->distinct()->pluck('tenant_id'));
            }
        }

        $ids = $ids->merge(SandboxUsageRecord::query()->whereNotNull('tenant_id')
            ->whereDate('record_date', '>=', $period->startDate())->whereDate('record_date', '<=', $period->endDate())
            ->distinct()->pluck('tenant_id'));

        return $ids->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /**
     * @param  callable(Builder): mixed  $scope
     * @return list<array<string, mixed>>
     */
    private function usageRows(Period $period, callable $scope, ?string $connection = null, bool $ownOnly = true): array
    {
        try {
            $query = UsageRecord::on($connection)
                ->whereDate('record_date', '>=', $period->startDate())
                ->whereDate('record_date', '<=', $period->endDate());
            // The central table also holds the copies of tenants' usage (tenant_id)
            if ($ownOnly && ! BillingContext::inTenant() && $this->hasColumn('usage_records', 'tenant_id', $connection)) {
                $query->whereNull('tenant_id');
            }
            $scope($query);
            $rows = $query->get();
        } catch (QueryException) {
            return [];
        }

        $hasOwner = $this->hasColumn('usage_records', 'created_by', $connection);
        $entries = [];
        foreach ($rows as $row) {
            $cpu = (int) $row->total_cpu_mc_ms;
            $memory = (int) $row->total_memory_mib_ms;
            $gpu = (int) $row->total_gpu_unit_ms;
            // 0.5.7 custom-scale rows carry the reservation only
            if ($row->scale_mode === 'custom_scale') {
                $scale = max(1, (int) $row->min_scale);
                if ($cpu <= 0) {
                    $cpu = $scale * (int) $row->reserved_cpu_m * self::MS_PER_DAY;
                }
                if ($memory <= 0) {
                    $memory = $scale * (int) $row->reserved_memory_mb * self::MS_PER_DAY;
                }
            }
            $exact = [
                'vcpu_hours' => Money::fraction($cpu, 1000 * self::MS_PER_HOUR),
                'memory_gb_hours' => Money::fraction($memory, 1024 * self::MS_PER_HOUR),
                'gpu_hours' => Money::fraction($gpu, self::MS_PER_HOUR),
            ];

            $model = ($row->source ?? 'project') === 'model';
            $entries[] = [
                'date' => $this->date($row->record_date),
                'kind' => $model ? 'model' : 'project',
                'region_id' => $row->region_id,
                'project_id' => $model ? null : (string) $row->project_id,
                'resource_id' => $model ? (string) $row->project_id : null,
                'resource_name' => null,
                'resource_type' => $model ? 'model_service' : null,
                'owner' => $hasOwner ? ($row->getAttribute('created_by') !== null ? (string) $row->getAttribute('created_by') : null) : null,
                'entry_point' => $row->entry_point ?: 'http',
                'vcpu_hours' => $cpu / (1000 * self::MS_PER_HOUR),
                'memory_gb_hours' => $memory / (1024 * self::MS_PER_HOUR),
                'gpu_hours' => $gpu / self::MS_PER_HOUR,
                'storage_gb_hours' => 0.0,
                'instance_hours' => 0.0,
                'sessions' => (int) $row->session_count,
                'exact' => $exact,
            ];
        }

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    private function modelUsage(Period $period): array
    {
        $entries = $this->usageRows($period, fn (Builder $q) => $q->where('source', 'model'));
        if ($entries === []) {
            return [];
        }

        try {
            $services = AiModelService::query()->whereIn('id', array_unique(array_column($entries, 'resource_id')))->get(['id', 'name', 'created_by'])->keyBy('id');
        } catch (QueryException) {
            $services = collect();
        }

        foreach ($entries as $i => $entry) {
            $service = $services->get($entry['resource_id']);
            $entries[$i]['resource_name'] = $service?->name;
            $entries[$i]['owner'] ??= $service?->created_by !== null ? (string) $service->created_by : null;
        }

        return $entries;
    }

    /**
     * Binding usage of the organization ($tenantId null) or the central copies of a tenant's.
     *
     * @return list<array<string, mixed>>
     */
    private function bindingUsage(Period $period, ?string $tenantId): array
    {
        $connection = $tenantId !== null ? BillingContext::central() : null;
        if (! $this->hasTable('binding_usage_records', $connection)) {
            return [];
        }

        $query = BindingUsageRecord::on($connection)
            ->whereDate('record_date', '>=', $period->startDate())
            ->whereDate('record_date', '<=', $period->endDate());
        $hasTenant = $this->hasColumn('binding_usage_records', 'tenant_id', $connection);
        if ($tenantId !== null) {
            if (! $hasTenant) {
                return [];
            }
            $query->where('tenant_id', $tenantId);
        } elseif ($hasTenant && ! BillingContext::inTenant()) {
            $query->whereNull('tenant_id');
        }

        try {
            $rows = $query->get();
        } catch (QueryException) {
            return [];
        }

        $hasOwner = $this->hasColumn('binding_usage_records', 'created_by', $connection);
        $missing = $rows->filter(fn ($r) => ! $hasOwner || $r->getAttribute('created_by') === null)->pluck('binding_id')->unique()->values()->all();
        $creators = [];
        if ($missing !== [] && $tenantId === null) {
            try {
                $creators = Binding::query()->whereIn('id', $missing)->pluck('created_by', 'id')->all();
            } catch (QueryException) {
                $creators = [];
            }
        }

        $entries = [];
        foreach ($rows as $row) {
            $owner = $hasOwner ? $row->getAttribute('created_by') : null;
            $owner ??= $creators[$row->binding_id] ?? null;

            $entries[] = [
                'date' => $this->date($row->record_date),
                'kind' => 'binding',
                'region_id' => $row->region_id,
                'project_id' => null,
                'resource_id' => (string) $row->binding_id,
                'resource_name' => $row->binding_name,
                'resource_type' => (string) $row->type,
                'owner' => $owner !== null ? (string) $owner : null,
                'entry_point' => null,
                'vcpu_hours' => 0.0,
                'memory_gb_hours' => 0.0,
                'gpu_hours' => 0.0,
                'storage_gb_hours' => (float) $row->storage_gb_hours,
                'instance_hours' => (float) $row->instance_hours,
                'sessions' => 0,
                'exact' => [
                    'storage_gb_hours' => BigRational::of(Money::dec($row->getAttributes()['storage_gb_hours'] ?? null)),
                    'instance_hours' => BigRational::of(Money::dec($row->getAttributes()['instance_hours'] ?? null)),
                ],
            ];
        }

        return $entries;
    }

    /**
     * Sandbox usage of an organization (tenant id, null = the install / platform itself).
     * $platformRegionsOnly: what the platform bills a tenant for.
     *
     * @return list<array<string, mixed>>
     */
    private function sandboxUsage(Period $period, ?string $tenantId, bool $platformRegionsOnly): array
    {
        try {
            $rows = SandboxUsageRecord::query()
                ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
                ->whereDate('record_date', '>=', $period->startDate())
                ->whereDate('record_date', '<=', $period->endDate())
                ->get();
        } catch (QueryException) {
            return [];
        }

        $regions = app(Regions::class);
        $hasOwner = $this->hasColumn('sandbox_usage_records', 'created_by', BillingContext::central());
        $entries = [];
        foreach ($rows as $row) {
            if ($platformRegionsOnly && ! $regions->isPlatformRegion($row->knative_region_id)) {
                continue;
            }

            $owner = $hasOwner ? $row->getAttribute('created_by') : null;
            $entries[] = [
                'date' => $this->date($row->record_date),
                'kind' => 'sandbox',
                'region_id' => $row->knative_region_id,
                'project_id' => null,
                'resource_id' => null,
                'resource_name' => null,
                'resource_type' => 'sandbox',
                'owner' => $owner !== null ? (string) $owner : null,
                'entry_point' => null,
                'vcpu_hours' => (float) $row->cpu_mc_ms / (1000 * self::MS_PER_HOUR),
                'memory_gb_hours' => (float) $row->memory_mib_ms / (1024 * self::MS_PER_HOUR),
                'gpu_hours' => 0.0,
                'storage_gb_hours' => 0.0,
                'instance_hours' => 0.0,
                'sessions' => (int) $row->sandbox_count,
                'exact' => [
                    'vcpu_hours' => Money::fraction((int) $row->cpu_mc_ms, 1000 * self::MS_PER_HOUR),
                    'memory_gb_hours' => Money::fraction((int) $row->memory_mib_ms, 1024 * self::MS_PER_HOUR),
                ],
            ];
        }

        return $entries;
    }

    private function date(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function hasTable(string $table, ?string $connection): bool
    {
        $key = ($connection ?? BillingContext::key()).'|'.$table;

        return $this->columns[$key] ??= DB::connection($connection)->getSchemaBuilder()->hasTable($table);
    }

    private function hasColumn(string $table, string $column, ?string $connection): bool
    {
        $key = ($connection ?? BillingContext::key()).'|'.$table.'.'.$column;

        return $this->columns[$key] ??= DB::connection($connection)->getSchemaBuilder()->hasColumn($table, $column);
    }

    /** @return Collection<int, string> distinct owners (creators) with organization-level usage */
    public function accountOwners(Period $period): Collection
    {
        return collect($this->accountUsage($period))->map(fn (array $e) => $e['owner'] ?? '')->unique()->values();
    }
}
