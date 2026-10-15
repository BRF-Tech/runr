<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Metered sandbox usage of an organization in a region on a day (SandboxUsageAggregator): sandbox
 * seconds and reserved resources × time in the units of usage_records (millicore·ms, MiB·ms),
 * priced with the region's cost config.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $knative_region_id
 * @property Carbon $record_date
 * @property int $sandbox_count
 * @property int $sandbox_seconds
 * @property int $cpu_mc_ms
 * @property int $memory_mib_ms
 * @property string $raw_cost
 * @property string $multiplier
 * @property string $final_cost
 * @property string $currency
 */
class SandboxUsageRecord extends Model
{
    use HasUlids, UsesCentralConnection;

    protected $fillable = [
        'tenant_id', 'knative_region_id', 'record_date', 'sandbox_count', 'sandbox_seconds',
        'cpu_mc_ms', 'memory_mib_ms', 'raw_cost', 'multiplier', 'final_cost', 'currency',
    ];

    protected $casts = [
        'record_date' => 'date',
        'sandbox_count' => 'integer',
        'sandbox_seconds' => 'integer',
        'cpu_mc_ms' => 'integer',
        'memory_mib_ms' => 'integer',
        'raw_cost' => 'decimal:6',
        'multiplier' => 'decimal:2',
        'final_cost' => 'decimal:6',
    ];
}
