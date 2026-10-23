<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Models\BindingReplica;
use App\Models\BindingUsageRecord;
use App\Services\Bindings\DataServices\PostgresService;
use App\Services\GlobalProjects\Data\RegionalData;
use App\Services\Metering\UsageAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly measurement of bindings (current organization context): one round-trip per region and
 * type (BindingDriver::measure), then per binding:
 *
 *  - usage_bytes / usage_measured_at on the binding;
 *  - the day's BindingUsageRecord: storage_gb_hours += GiB × hours since the last measurement
 *    (at most 2 h — a gap is not billed backwards), instance_hours += running instances × hours
 *    (dedicated Postgres), peak_bytes;
 *  - the size limit (BindingDriver::enforce: read-only over it).
 *
 * Quantities only, with the binding's creator (created_by — a binding belongs to no project, its
 * usage is billed to the user who created it); the Billing module prices them (docs/billing.md),
 * the core metering copies tenant usage in platform regions centrally (UsageAggregator::mirror).
 */
class BindingUsageMeter
{
    public const MAX_INTERVAL_HOURS = 2.0;

    /** @var array<string, bool> context (tenant id or "central") => binding_usage_records.created_by exists */
    private array $creatorColumn = [];

    public function __construct(private BindingDrivers $drivers) {}

    public function measure(): int
    {
        $bindings = Binding::query()
            ->whereIn('status', [Binding::STATUS_READY, Binding::STATUS_ROTATING, Binding::STATUS_DELETING])
            ->where('type', '!=', 'external')
            ->whereNotNull('knative_region_id')
            ->with('region')
            ->get();

        $measured = 0;
        foreach ($bindings->groupBy(fn (Binding $b) => $b->knative_region_id.'|'.$b->type) as $group) {
            $region = $group->first()->region;
            if (! $region?->kubeconfig) {
                continue;
            }

            $driver = $this->drivers->for($group->first()->type);

            try {
                $usage = $driver->measure($region, $group->values());
            } catch (\Throwable $e) {
                Log::warning('Bindings: usage measurement failed', ['region' => $region->name, 'type' => $group->first()->type, 'error' => $e->getMessage()]);

                continue;
            }

            foreach ($group as $binding) {
                if (! isset($usage[$binding->id])) {
                    continue;
                }

                // a global project's replica clusters (docs/global-projects.md, "Data"): their
                // instances and copies of the data count with the binding
                [$replicaInstances, $replicaCopies] = $this->replicas($binding);
                $this->record(
                    $binding,
                    (int) $usage[$binding->id]['bytes'],
                    (int) ($usage[$binding->id]['instances'] ?? 0) + $replicaInstances,
                    null,
                    (int) $usage[$binding->id]['bytes'] * $replicaCopies,
                );

                try {
                    $driver->enforce($binding, (int) $usage[$binding->id]['bytes']);
                } catch (\Throwable $e) {
                    Log::warning('Bindings: size limit enforcement failed', ['binding' => $binding->resource_name, 'error' => $e->getMessage()]);
                }

                $binding->save();
                $measured++;
            }
        }

        return $measured;
    }

    /**
     * Book one measurement (changes $binding in memory, saves the record).
     */
    public function record(Binding $binding, int $bytes, int $instances, ?Carbon $now = null, int $replicaBytes = 0): BindingUsageRecord
    {
        $now ??= now();
        $since = $binding->usage_measured_at ?? $binding->created_at ?? $now;
        $hours = min(self::MAX_INTERVAL_HOURS, max(0, $since->diffInSeconds($now, true)) / 3600);
        $date = $now->toDateString();

        $record = BindingUsageRecord::query()
            ->where('binding_id', $binding->id)
            ->whereDate('record_date', $date)
            ->first()
            ?? new BindingUsageRecord(['binding_id' => $binding->id, 'record_date' => $date]);

        $record->fill([
            'binding_name' => $binding->name,
            'type' => $binding->type,
            'plan' => $binding->plan,
            'region_id' => $binding->knative_region_id,
        ]);
        // A binding belongs to no project: its usage is billed to its creator (docs/billing.md)
        if ($this->recordsCreator()) {
            $record->created_by = $binding->created_by;
        }
        $record->storage_gb_hours = round((float) $record->storage_gb_hours + (($bytes + $replicaBytes) / 1024 ** 3) * $hours, 6);
        $record->instance_hours = round((float) $record->instance_hours + $instances * $hours, 4);
        $record->peak_bytes = max((int) $record->peak_bytes, $bytes);
        $record->save();

        $binding->usage_bytes = $bytes;
        $binding->usage_measured_at = $now;

        return $record;
    }

    /**
     * The ready replica clusters of a dedicated binding of a global project: [running instances,
     * copies of the data]. Each copy is as large as the binding's database (physical replication).
     *
     * @return array{0: int, 1: int}
     */
    private function replicas(Binding $binding): array
    {
        if (! $binding->isDedicated() || ! RegionalData::replicated($binding) || $binding->status === Binding::STATUS_DELETING) {
            return [0, 0];
        }

        $instances = 0;
        $copies = 0;
        foreach (BindingReplica::query()->where('binding_id', $binding->id)->where('kind', BindingReplica::KIND_REPLICA)->with('knativeRegion')->get() as $row) {
            if (! $row->knativeRegion) {
                continue;
            }
            try {
                $ready = (int) (app(PostgresService::class)->clusterStatus($row->knativeRegion, $binding->dedicatedClusterName())['readyInstances'] ?? 0);
            } catch (\Throwable $e) {
                Log::info('Bindings: replica cluster not measured', ['binding' => $binding->resource_name, 'region' => $row->knativeRegion->name, 'error' => $e->getMessage()]);
                $ready = 0;
            }
            $instances += $ready;
            $copies += $ready > 0 ? 1 : 0;
        }

        return [$instances, $copies];
    }

    /** binding_usage_records.created_by exists (a tenant schema may still miss TenantMigrations/0059). */
    private function recordsCreator(): bool
    {
        $record = new BindingUsageRecord;
        $context = UsageAggregator::tenantKey() ?? 'central';

        return $this->creatorColumn[$context] ??= Schema::connection($record->getConnectionName())->hasColumn($record->getTable(), 'created_by');
    }
}
