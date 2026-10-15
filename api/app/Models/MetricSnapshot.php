<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string|null $deployment_id
 * @property string $region_id
 * @property Carbon $recorded_at
 * @property string $granularity
 * @property numeric|null $cpu_cores
 * @property numeric|null $memory_mb
 * @property numeric|null $rps
 * @property numeric|null $concurrency
 * @property int|null $pod_count
 * @property int $cold_starts
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon $created_at
 * @property-read Deployment|null $deployment
 * @property-read Project $project
 * @property-read KnativeRegion $region
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereColdStarts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereConcurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereCpuCores($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereDeploymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereGranularity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereMemoryMb($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot wherePodCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereRecordedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereRegionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MetricSnapshot whereRps($value)
 *
 * @mixin \Eloquent
 */
class MetricSnapshot extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'deployment_id',
        'region_id',
        'recorded_at',
        'granularity',
        'cpu_cores',
        'memory_mb',
        'rps',
        'concurrency',
        'pod_count',
        'cold_starts',
        'metadata',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'cpu_cores' => 'decimal:6',
        'memory_mb' => 'decimal:2',
        'rps' => 'decimal:3',
        'concurrency' => 'decimal:3',
        'cold_starts' => 'integer',
        'pod_count' => 'integer',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'region_id');
    }
}
