<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The peaks of one project on one day, per pod (App\Services\Rightsizing\PeakCollector): the
 * highest and the 95th percentile working-set memory (MiB) and CPU (millicores) of the project's
 * workload container, the OOM kills and the share of throttled CPU periods — with the size the
 * project ran with. A row without samples records a day that was read and had no pod running.
 *
 * @property string $id
 * @property string $project_id
 * @property string|null $region_id
 * @property Carbon $day
 * @property string $trigger_type
 * @property int $cpu_m
 * @property int $memory_mb
 * @property numeric|null $memory_max_mb
 * @property numeric|null $memory_p95_mb
 * @property int|null $cpu_max_m
 * @property int|null $cpu_p95_m
 * @property numeric|null $throttled_ratio
 * @property int $oom_kills
 * @property int $samples
 * @property int $pods
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ResourcePeak extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id',
        'region_id',
        'day',
        'trigger_type',
        'cpu_m',
        'memory_mb',
        'memory_max_mb',
        'memory_p95_mb',
        'cpu_max_m',
        'cpu_p95_m',
        'throttled_ratio',
        'oom_kills',
        'samples',
        'pods',
    ];

    protected $casts = [
        'day' => 'date',
        'cpu_m' => 'integer',
        'memory_mb' => 'integer',
        'memory_max_mb' => 'float',
        'memory_p95_mb' => 'float',
        'cpu_max_m' => 'integer',
        'cpu_p95_m' => 'integer',
        'throttled_ratio' => 'float',
        'oom_kills' => 'integer',
        'samples' => 'integer',
        'pods' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
