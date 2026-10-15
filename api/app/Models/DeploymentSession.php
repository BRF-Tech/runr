<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $deployment_id
 * @property string $region_id
 * @property string $entry_point
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_ms
 * @property int|null $cpu_used_ms
 * @property int|null $memory_used_mb_ms
 * @property int $pod_count
 * @property bool $cold_start
 * @property int|null $cold_start_ms
 * @property array<array-key, mixed>|null $metadata
 * @property Carbon $created_at
 * @property-read Deployment $deployment
 * @property-read Project $project
 * @property-read KnativeRegion $region
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereColdStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereColdStartMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereCpuUsedMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereDeploymentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereEntryPoint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereMemoryUsedMbMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession wherePodCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereRegionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeploymentSession whereStartedAt($value)
 *
 * @mixin \Eloquent
 */
class DeploymentSession extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'deployment_id',
        'region_id',
        'entry_point',
        'started_at',
        'ended_at',
        'duration_ms',
        'cpu_used_ms',
        'memory_used_mb_ms',
        'pod_count',
        'cold_start',
        'cold_start_ms',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
        'cold_start' => 'boolean',
        'duration_ms' => 'integer',
        'cpu_used_ms' => 'integer',
        'memory_used_mb_ms' => 'integer',
        'pod_count' => 'integer',
        'cold_start_ms' => 'integer',
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
