<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily usage of a binding (docs/bindings.md "Usage and billing"): storage GB-hours (measured
 * size × hours, hourly) and — dedicated Postgres — instance hours, with the binding's creator
 * (created_by: billed to that user, docs/billing.md). The Billing module prices the quantities;
 * raw_cost / final_cost / currency are kept for the rows of earlier releases. The central table
 * also holds copies of tenant usage in platform regions (tenant_id, UsageAggregator::mirror).
 * binding_name / type are kept so bills still name a purged binding.
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
        'created_by',
        'tenant_id',
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
