<?php

namespace App\Services\Bindings;

use App\Models\Binding;
use App\Models\BindingUsageRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

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
 * The Tenancy usage aggregation prices the records with the region's CostConfig.
 */
class BindingUsageMeter
{
    public const MAX_INTERVAL_HOURS = 2.0;

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

                $this->record($binding, (int) $usage[$binding->id]['bytes'], (int) ($usage[$binding->id]['instances'] ?? 0));

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
    public function record(Binding $binding, int $bytes, int $instances, ?Carbon $now = null): BindingUsageRecord
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
        $record->storage_gb_hours = round((float) $record->storage_gb_hours + ($bytes / 1024 ** 3) * $hours, 6);
        $record->instance_hours = round((float) $record->instance_hours + $instances * $hours, 4);
        $record->peak_bytes = max((int) $record->peak_bytes, $bytes);
        $record->save();

        $binding->usage_bytes = $bytes;
        $binding->usage_measured_at = $now;

        return $record;
    }
}
