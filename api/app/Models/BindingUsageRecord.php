<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily usage of a binding (docs/bindings.md "Usage and billing"): storage GB-hours (measured
 * size × hours, hourly) and — dedicated Postgres — instance hours. The Tenancy usage
 * aggregation prices them with the region's CostConfig (raw_cost / final_cost / currency).
 * binding_name / type are kept so charges still name a purged binding.
 */
class BindingUsageRecord extends Model
{
    use HasUlids;

    protected $fillable = [
        'binding_id',
        'binding_name',
        'type',
        'plan',
        'region_id',
        'record_date',
        'storage_gb_hours',
        'instance_hours',
        'peak_bytes',
        'raw_cost',
        'final_cost',
        'currency',
    ];

    protected $casts = [
        'record_date' => 'date',
        'storage_gb_hours' => 'decimal:6',
        'instance_hours' => 'decimal:4',
        'peak_bytes' => 'integer',
        'raw_cost' => 'decimal:6',
        'final_cost' => 'decimal:6',
    ];
}
