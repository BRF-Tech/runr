<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily metered usage of one project or model service (App\Services\Metering\UsageAggregator):
 * function sessions (CPU millicore·ms, memory MiB·ms, duration, cold starts), the reserved
 * resources of always-on (custom-scale) workloads — whose day also counts as reserved × 24 h in
 * total_cpu_mc_ms / total_memory_mib_ms — and GPU-unit milliseconds. Model services have
 * source "model" and carry their service id in project_id / deployment_id.
 *
 * In the current organization's database; the central table additionally holds copies of every
 * tenant's usage in platform regions (tenant_id set). Quantities only: the Billing module prices
 * them when it calculates bills (docs/billing.md); raw_cost / final_cost are kept for the rows of
 * earlier releases.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string $deployment_id
 * @property string $region_id
 * @property Carbon $record_date
 * @property string $scale_mode scale_to_zero | custom_scale
 * @property string $entry_point http | schedule | queue
 * @property string|null $source project | model
 * @property string|null $created_by creator of a model service
 * @property int $session_count
 * @property int $total_duration_ms
 * @property int $total_cpu_mc_ms
 * @property int $total_memory_mib_ms
 * @property int $cold_start_count
 * @property int $reserved_cpu_m
 * @property int $reserved_memory_mb
 * @property int $min_scale
 * @property int $gpu_count
 * @property int $total_gpu_unit_ms
 * @property string $raw_cost
 * @property string $multiplier
 * @property string $final_cost
 * @property string $currency
 */
class UsageRecord extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
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
        'created_by',
    ];

    protected $casts = [
        'record_date' => 'date',
        'raw_cost' => 'decimal:6',
        'multiplier' => 'decimal:2',
        'final_cost' => 'decimal:6',
    ];
}
