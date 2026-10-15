<?php

namespace Modules\Tenancy\Services\Bindings;

use App\Models\BindingUsageRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\CostConfig;

/**
 * Prices the binding usage records of a day (and the day before — its last hour is booked after
 * midnight) in the current tenant with the region's CostConfig:
 *
 *   raw_cost = storage_gb_hours × <type>_price_per_gb_hour
 *            + instance_hours × postgres_instance_price_per_hour   (dedicated Postgres)
 *
 * No multiplier (those are per trigger type of functions); final_cost = raw_cost.
 */
class BindingUsagePricing
{
    public function priceDate(string $date): int
    {
        if (! Schema::hasTable('binding_usage_records')) {
            return 0;
        }

        $dates = [$date, Carbon::parse($date)->subDay()->toDateString()];
        $records = BindingUsageRecord::query()
            ->where(fn ($q) => $q->whereDate('record_date', $dates[0])->orWhereDate('record_date', $dates[1]))
            ->get();

        foreach ($records as $record) {
            $config = CostConfig::forRegion($record->region_id);
            $raw = (float) $record->storage_gb_hours * $config->bindingStoragePrice((string) $record->type)
                + (float) $record->instance_hours * (float) $config->postgres_instance_price_per_hour;

            $record->raw_cost = round($raw, 6);
            $record->final_cost = round($raw, 6);
            $record->currency = $config->effectiveCurrency();
            $record->save();
        }

        return $records->count();
    }
}
