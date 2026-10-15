<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $project_id
 * @property string $version_id
 * @property string $status
 * @property string|null $k8s_job_name
 * @property string|null $deployed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<array-key, mixed>|null $deploy_config
 * @property bool|null $knative_ready
 * @property string|null $knative_message
 * @property Carbon|null $ready_at
 * @property-read Project $project
 * @property-read Version $version
 *
 * @method static Builder<static>|Deployment newModelQuery()
 * @method static Builder<static>|Deployment newQuery()
 * @method static Builder<static>|Deployment query()
 * @method static Builder<static>|Deployment whereCreatedAt($value)
 * @method static Builder<static>|Deployment whereDeployConfig($value)
 * @method static Builder<static>|Deployment whereDeployedBy($value)
 * @method static Builder<static>|Deployment whereId($value)
 * @method static Builder<static>|Deployment whereK8sJobName($value)
 * @method static Builder<static>|Deployment whereKnativeMessage($value)
 * @method static Builder<static>|Deployment whereKnativeReady($value)
 * @method static Builder<static>|Deployment whereProjectId($value)
 * @method static Builder<static>|Deployment whereReadyAt($value)
 * @method static Builder<static>|Deployment whereStatus($value)
 * @method static Builder<static>|Deployment whereUpdatedAt($value)
 * @method static Builder<static>|Deployment whereVersionId($value)
 *
 * @mixin Eloquent
 */
class Deployment extends Model
{
    use HasUlids;

    const STATUSES = ['pending', 'running', 'active', 'shutting_down', 'shut_down', 'failed'];

    protected $fillable = [
        'project_id',
        'version_id',
        'status',
        'k8s_job_name',
        'deployed_by',
        'deploy_config',
        'knative_ready',
        'knative_message',
        'ready_at',
    ];

    protected $casts = [
        'deploy_config' => 'array',
        'knative_ready' => 'boolean',
        'ready_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }
}
