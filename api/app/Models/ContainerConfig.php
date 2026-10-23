<?php

namespace App\Models;

use App\Casts\Encrypted;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $image
 * @property array<array-key, mixed>|null $command
 * @property array<array-key, mixed>|null $args
 * @property array<array-key, mixed>|null $env_vars
 * @property array<array-key, mixed>|null $ports
 * @property int $memory_mb
 * @property string $cpu
 * @property int $timeout_seconds
 * @property string $image_pull_policy
 * @property string|null $image_pull_secret
 * @property string $trigger_type
 * @property string|null $schedule
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $queue_stream
 * @property bool $scale_to_zero
 * @property int $min_scale
 * @property int $max_scale
 * @property list<array{cron: string, min_scale: int}>|null $scale_schedule Timed warmer rows (App\Support\Projects\ScaleSchedule); null = off
 * @property array<string, mixed>|null $deploy_strategy blue-green / canary settings (App\Services\Rollouts\DeployStrategy), null = direct
 * @property-read Project $project
 *
 * @method static Builder<static>|ContainerConfig newModelQuery()
 * @method static Builder<static>|ContainerConfig newQuery()
 * @method static Builder<static>|ContainerConfig query()
 * @method static Builder<static>|ContainerConfig whereArgs($value)
 * @method static Builder<static>|ContainerConfig whereCommand($value)
 * @method static Builder<static>|ContainerConfig whereCpu($value)
 * @method static Builder<static>|ContainerConfig whereCreatedAt($value)
 * @method static Builder<static>|ContainerConfig whereEnvVars($value)
 * @method static Builder<static>|ContainerConfig whereId($value)
 * @method static Builder<static>|ContainerConfig whereImage($value)
 * @method static Builder<static>|ContainerConfig whereImagePullPolicy($value)
 * @method static Builder<static>|ContainerConfig whereImagePullSecret($value)
 * @method static Builder<static>|ContainerConfig whereMaxScale($value)
 * @method static Builder<static>|ContainerConfig whereMemoryMb($value)
 * @method static Builder<static>|ContainerConfig whereMinScale($value)
 * @method static Builder<static>|ContainerConfig wherePorts($value)
 * @method static Builder<static>|ContainerConfig whereProjectId($value)
 * @method static Builder<static>|ContainerConfig whereQueueStream($value)
 * @method static Builder<static>|ContainerConfig whereScaleToZero($value)
 * @method static Builder<static>|ContainerConfig whereSchedule($value)
 * @method static Builder<static>|ContainerConfig whereTimeoutSeconds($value)
 * @method static Builder<static>|ContainerConfig whereTriggerType($value)
 * @method static Builder<static>|ContainerConfig whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class ContainerConfig extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'image', 'command', 'args',
        'env_vars', 'ports', 'memory_mb', 'cpu',
        'timeout_seconds', 'image_pull_policy', 'image_pull_secret',
        'trigger_type', 'schedule', 'queue_stream',
        'scale_to_zero', 'min_scale', 'max_scale',
        'gpu',
        'scale_schedule',
    ];

    protected $casts = [
        'command' => 'array',
        'args' => 'array',
        'env_vars' => Encrypted::class.':array', // secret at rest (docs/security.md)
        'ports' => 'array',
        'scale_to_zero' => 'boolean',
        'gpu' => 'array',
        'scale_schedule' => 'array',
        // written only through PUT /projects/{project}/deploy-strategy (DeployStrategies): not
        // fillable, the config update creates a version
        'deploy_strategy' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
