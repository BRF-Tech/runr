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
 * @property string $handler
 * @property array<array-key, mixed>|null $env_vars
 * @property int $memory_mb
 * @property int $timeout_seconds
 * @property string $trigger_type
 * @property string|null $schedule
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $cpu
 * @property string|null $queue_stream
 * @property bool $scale_to_zero
 * @property int $min_scale
 * @property int $max_scale
 * @property list<array{cron: string, min_scale: int}>|null $scale_schedule Timed warmer rows (App\Support\Projects\ScaleSchedule); null = off
 * @property array<string, mixed>|null $deploy_strategy blue-green / canary settings (App\Services\Rollouts\DeployStrategy), null = direct
 * @property-read Project $project
 *
 * @method static Builder<static>|FaasConfig newModelQuery()
 * @method static Builder<static>|FaasConfig newQuery()
 * @method static Builder<static>|FaasConfig query()
 * @method static Builder<static>|FaasConfig whereCpu($value)
 * @method static Builder<static>|FaasConfig whereCreatedAt($value)
 * @method static Builder<static>|FaasConfig whereEnvVars($value)
 * @method static Builder<static>|FaasConfig whereHandler($value)
 * @method static Builder<static>|FaasConfig whereId($value)
 * @method static Builder<static>|FaasConfig whereMaxScale($value)
 * @method static Builder<static>|FaasConfig whereMemoryMb($value)
 * @method static Builder<static>|FaasConfig whereMinScale($value)
 * @method static Builder<static>|FaasConfig whereProjectId($value)
 * @method static Builder<static>|FaasConfig whereQueueStream($value)
 * @method static Builder<static>|FaasConfig whereScaleToZero($value)
 * @method static Builder<static>|FaasConfig whereSchedule($value)
 * @method static Builder<static>|FaasConfig whereTimeoutSeconds($value)
 * @method static Builder<static>|FaasConfig whereTriggerType($value)
 * @method static Builder<static>|FaasConfig whereUpdatedAt($value)
 *
 * @mixin Eloquent
 */
class FaasConfig extends Model
{
    use HasUlids;

    protected $fillable = [
        'project_id', 'handler', 'env_vars',
        'memory_mb', 'cpu', 'timeout_seconds',
        'trigger_type', 'schedule', 'queue_stream',
        'scale_to_zero', 'min_scale', 'max_scale',
        'gpu',
        'scale_schedule',
    ];

    protected $casts = [
        'env_vars' => Encrypted::class.':array', // secret at rest (docs/security.md)
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
