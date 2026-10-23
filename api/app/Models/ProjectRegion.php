<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One region of a global project (docs/global-projects.md) — the primary (the project's
 * knative_region_id) included — and the state of the project's copy there.
 *
 * The primary's copy is the project's production deployment (Deployment rows, as for a regional
 * project); a secondary's copy follows it: when a deployment of the primary is ready, RunR rolls
 * its version out to every secondary region (GlobalReplicaDeployer) — built into that region's
 * registry when the version has a build image — and records here what the copy serves.
 *
 * @property string $id
 * @property string $project_id
 * @property string $knative_region_id
 * @property int $position order of the regions; HA fails over in this order after the primary
 * @property string $status pending | building | deploying | ready | failed | removing | fenced
 * @property string|null $status_message
 * @property string|null $served_version_id the version the copy serves (null: nothing yet)
 * @property string|null $target_version_id the version being rolled out to it (null: none)
 * @property string|null $deployment_id the primary deployment the rollout follows
 * @property string|null $build_image the version's image in this region's registry
 * @property string|null $rollout_token the rollout the queued polls belong to
 * @property int $attempts polls of the current rollout step
 * @property bool|null $healthy
 * @property Carbon|null $health_checked_at
 * @property Carbon|null $ready_at
 * @property bool $triggers_active the region runs the trigger workloads (the trigger region: the primary)
 * @property string|null $data_status null (no regional data) | syncing | ready | failed
 * @property string|null $data_message
 * @property Carbon|null $unhealthy_since the first failed health check of a run of failures
 * @property Carbon|null $fenced_at the region lost the primary in a failover; it rejoins once it answers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project|null $project
 * @property-read KnativeRegion|null $knativeRegion
 * @property-read Version|null $servedVersion
 * @property-read Version|null $targetVersion
 */
class ProjectRegion extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_BUILDING = 'building';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REMOVING = 'removing';

    /**
     * The region was the primary and lost it in a failover (docs/global-projects.md, "Failover"):
     * out of the DNS answers, its triggers stopped and its data rebuilt from the new primary once
     * its cluster answers again; then it rolls the active version out and serves again.
     */
    public const STATUS_FENCED = 'fenced';

    /** data_status: the copy reaches the project's bindings (replicas streaming, links up). */
    public const DATA_READY = 'ready';

    public const DATA_SYNCING = 'syncing';

    public const DATA_FAILED = 'failed';

    /** A rollout is running in the region. */
    public const ROLLING = [self::STATUS_BUILDING, self::STATUS_DEPLOYING];

    protected $fillable = [
        'project_id',
        'knative_region_id',
        'position',
        'status',
        'status_message',
        'served_version_id',
        'target_version_id',
        'deployment_id',
        'build_image',
        'rollout_token',
        'attempts',
        'healthy',
        'health_checked_at',
        'ready_at',
        'triggers_active',
        'data_status',
        'data_message',
        'unhealthy_since',
        'fenced_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'attempts' => 'integer',
        'healthy' => 'boolean',
        'health_checked_at' => 'datetime',
        'ready_at' => 'datetime',
        'triggers_active' => 'boolean',
        'unhealthy_since' => 'datetime',
        'fenced_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'knative_region_id');
    }

    public function servedVersion(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'served_version_id');
    }

    public function targetVersion(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'target_version_id');
    }

    public function isPrimary(): bool
    {
        return $this->project !== null && (string) $this->project->knative_region_id === (string) $this->knative_region_id;
    }

    /**
     * The copy answers requests: it serves a version and is not being removed. A copy that rolls
     * a new version out keeps serving the one before until the new revision is ready.
     */
    public function isServing(): bool
    {
        return $this->served_version_id !== null && ! in_array($this->status, [self::STATUS_REMOVING, self::STATUS_FENCED], true);
    }

    /**
     * The copy may be in the DNS answers: it serves, and the project's regional data is reachable
     * from it (no data, or replicas and links ready — docs/global-projects.md, "Data").
     */
    public function isAnswerable(): bool
    {
        $data = $this->getAttribute('data_status');

        return $this->isServing() && ($data === null || $data === self::DATA_READY);
    }
}
