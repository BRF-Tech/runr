<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A production deploy of a version that waits for the version's build (and, for a version without
 * a build of its own, for the runtime image RunR builds in the region), then goes through the
 * deploy checks of every deploy — App\Services\Projects\QueuedDeploys.
 *
 * @property string $id
 * @property string $project_id
 * @property string $version_id
 * @property string $status waiting | starting | deployed | failed | cancelled
 * @property string|null $reason what queued it (QueuedDeploys::REASON_*)
 * @property string $deployed_by
 * @property string|null $initiator_id
 * @property array<string, mixed>|null $deploy_config the deploy's overrides of the project config
 * @property string|null $replaces_deployment_id the live deployment it takes over from
 * @property string|null $replaces_reason the reason of the waiting deploy whose place it took, when that one ends the deployment it replaces on a failure (QueuedDeploys::REASON_REGION_MIGRATION)
 * @property string|null $previous_build_image the version's build image before the build it waits for
 * @property string|null $deployment_id the deployment it started
 * @property string|null $message what happened (or what it waits for)
 * @property int $attempts checks while a runtime image builds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project|null $project
 * @property-read Version|null $version
 *
 * @method static Builder<static>|QueuedDeploy query()
 */
class QueuedDeploy extends Model
{
    use HasUlids;

    public const WAITING = 'waiting';

    /** Its deployment is being started (claimed by one step, QueuedDeploys::process()) */
    public const STARTING = 'starting';

    public const DEPLOYED = 'deployed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::WAITING, self::STARTING, self::DEPLOYED, self::FAILED, self::CANCELLED];

    protected $fillable = [
        'project_id', 'version_id', 'status', 'reason', 'deployed_by', 'initiator_id', 'deploy_config',
        'replaces_deployment_id', 'replaces_reason', 'previous_build_image', 'deployment_id', 'message', 'attempts',
    ];

    protected $attributes = [
        'status' => self::WAITING,
        'attempts' => 0,
    ];

    protected $casts = [
        'deploy_config' => 'array',
        'attempts' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /** @param  Builder<QueuedDeploy>  $query */
    public function scopeWaiting(Builder $query): void
    {
        $query->where('status', self::WAITING);
    }
}
