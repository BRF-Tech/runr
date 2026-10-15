<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id',
        'deployment_id',
        'region_id',
        'record_date',
        'scale_mode',
        'entry_point',
        'session_count',
        'total_duration_ms',
        'total_cpu_mc_ms',
        'total_memory_mib_ms',
        'cold_start_count',
        'reserved_cpu_m',
        'reserved_memory_mb',
        'min_scale',
        'raw_cost',
        'multiplier',
        'final_cost',
        'currency',
        // GPU usage and model services (TenantMigrations/0045, docs/ai.md#quotas-and-billing)
        'source',
        'gpu_count',
        'total_gpu_unit_ms',
    ];

    protected $casts = [
        'record_date' => 'date',
        'raw_cost' => 'decimal:6',
        'multiplier' => 'decimal:2',
        'final_cost' => 'decimal:6',
    ];
}
