<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Metered usage of a model service per day (models:meter samples its running pods every minute):
 * replica, GPU-unit, CPU (millicore) and memory (MiB) milliseconds. The Tenancy usage aggregation
 * prices them with the region's cost config (GPU: gpu_price_per_unit_ms).
 *
 * @property string $id
 * @property string $ai_model_service_id
 * @property string $knative_region_id
 * @property Carbon $record_date
 * @property int $replica_ms
 * @property int $gpu_unit_ms
 * @property int $cpu_mc_ms
 * @property int $memory_mib_ms
 * @property Carbon|null $last_sampled_at
 */
class AiModelServiceUsage extends Model
{
    use HasUlids;

    protected $table = 'ai_model_service_usage';

    protected $fillable = [
        'ai_model_service_id', 'knative_region_id', 'record_date', 'replica_ms', 'gpu_unit_ms',
        'cpu_mc_ms', 'memory_mib_ms', 'last_sampled_at',
    ];

    protected $casts = [
        'record_date' => 'date',
        'replica_ms' => 'integer',
        'gpu_unit_ms' => 'integer',
        'cpu_mc_ms' => 'integer',
        'memory_mib_ms' => 'integer',
        'last_sampled_at' => 'datetime',
    ];
}
