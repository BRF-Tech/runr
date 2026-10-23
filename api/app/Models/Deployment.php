<?php

namespace App\Models;

use App\Casts\Encrypted;
use App\Support\SchemaColumns;
use Eloquent;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property Carbon|null $ended_at When the deployment stopped running (left pending / running / active)
 * @property array<string, mixed>|null $sizing The size it was applied with (App\Services\Helpers\WorkloadSizing)
 * @property string|null $runtime_image Runtime image the deployment applied (by digest when a patch is known)
 * @property string|null $runtime_digest Its digest; set before the apply, the deployment runs exactly this one (a patch rollback)
 * @property string|null $runtime_patch_id The runtime patch of that digest (docs/runtimes.md#runtime-patches)
 * @property string|null $environment_id The environment it runs in; null: the project's own (primary) environment
 * @property array<string, mixed>|null $config_snapshot The effective config it was applied with (encrypted: env var values)
 * @property string|null $config_source current | restored (a rollback that restores the config a deployment ran with)
 * @property Carbon|null $imported_at Brought by an organization import from another organization: history only, never live or metered (docs/org-export.md#run-history)
 * @property-read Project $project
 * @property-read ProjectEnvironment|null $environment
 * @property-read Version $version
 * @property-read DeploymentRollout|null $rollout the blue-green / canary rollout that brought this deployment
 * @property-read DeploymentRollout|null $stableRollout the newest rollout that started from this deployment
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

    /** States in which a deployment runs (or is about to run) workloads in its region. */
    const LIVE = ['pending', 'running', 'active'];

    /** config_source: the project's (environment's) config at deploy time */
    public const CONFIG_CURRENT = 'current';

    /** config_source: the config snapshot of an earlier deployment (a rollback, docs/versioning.md) */
    public const CONFIG_RESTORED = 'restored';

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
        'ended_at',
        'sizing',
        'runtime_image',
        'runtime_digest',
        'runtime_patch_id',
        'environment_id',
        'config_snapshot',
        'config_source',
    ];

    protected $casts = [
        'deploy_config' => 'array',
        'knative_ready' => 'boolean',
        'ready_at' => 'datetime',
        'ended_at' => 'datetime',
        'sizing' => 'array',
        'config_snapshot' => Encrypted::class.':array', // env var values (docs/security.md)
        'imported_at' => 'datetime',
    ];

    /**
     * Deployments RunR ran itself — not the history an organization import brought along
     * (imported_at, docs/org-export.md#run-history). Skipped on a schema without the column.
     *
     * @param  Builder<Deployment>  $query
     * @return Builder<Deployment>
     */
    public static function ranHere(Builder $query): Builder
    {
        return SchemaColumns::has(new self, 'imported_at') ? $query->whereNull('imported_at') : $query;
    }

    /**
     * ended_at follows the status (metering counts a deployment while it runs, UsageAggregator):
     * set when the deployment leaves a live state — only then: a change between two states that are
     * not live (shutting_down → shut_down, failed → shut_down) keeps the time it stopped running,
     * and a time already stored is never overwritten — cleared when it becomes live again. Skipped
     * on a tenant schema whose migration (TenantMigrations/0062) has not run yet.
     */
    protected static function booted(): void
    {
        static::saving(function (Deployment $deployment) {
            if (! $deployment->isDirty('status') || ! SchemaColumns::has($deployment, 'ended_at')) {
                return;
            }

            $live = in_array($deployment->status, self::LIVE, true);
            $wasLive = $deployment->exists && in_array($deployment->getOriginal('status'), self::LIVE, true);
            if (! $live && $wasLive && $deployment->ended_at === null) {
                $deployment->ended_at = now();
            } elseif ($live && $deployment->ended_at !== null) {
                $deployment->ended_at = null;
            }
        });
    }

    /**
     * The status a conditional write (transition()) needs the row to still have; null for an
     * ordinary save.
     */
    private ?string $claimedStatus = null;

    /**
     * Change the status only while the deployment is still in one of $from (re-read from the
     * database, not the copy in memory): a stop or teardown that changed it in the meantime wins.
     * Returns whether the change was made; the model holds the stored row afterwards either way.
     *
     * The change is an ordinary save, written only while the row still has the status it was read
     * with: the model events (saving, updating, updated, saved) fire as with update(), so every
     * observer and listener runs — the git deploy and its commit status (GitDeployServiceProvider),
     * the workflow sync of an activated deployment (WorkflowServiceProvider), the cache of the
     * deployments endpoints (CacheInvalidationObserver). A claim lost to a concurrent write fires
     * no updated / saved.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $attributes  the new status and whatever goes with it
     */
    public function transition(array $from, array $attributes): bool
    {
        // A concurrent write between the read and the claim: read again while the status still allows it
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $stored = static::query()->find($this->getKey());
            if (! $stored) {
                return false;
            }
            $this->setRawAttributes($stored->getAttributes(), true);
            if (! in_array($this->status, $from, true)) {
                return false;
            }

            $this->forceFill($attributes);
            $this->claimedStatus = (string) $this->getOriginal('status');
            try {
                if ($this->save()) {
                    // ended_at is written only where none was stored (performUpdate): read back what is
                    if ($this->wasChanged('ended_at') && ($row = static::query()->find($this->getKey()))) {
                        $this->setRawAttributes($row->getAttributes(), true);
                    }

                    return true;
                }
            } finally {
                $this->claimedStatus = null;
            }
        }

        $stored = static::query()->find($this->getKey());
        if ($stored) {
            $this->setRawAttributes($stored->getAttributes(), true);
        }

        return false;
    }

    /**
     * An update; inside transition() written only while the row still has the status it was read
     * with — a claim that matched no row fires no updated event and fails the save.
     *
     * @param  Builder<static>  $query
     * @return bool
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->claimedStatus === null) {
            return parent::performUpdate($query);
        }

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        $dirty = $this->getDirtyForUpdate();
        if ($dirty === []) {
            return true;
        }

        // The time it stopped running is set once: a concurrent write that stored one keeps it
        $write = $dirty;
        if (isset($write['ended_at'])) {
            $write['ended_at'] = $query->getQuery()->raw('COALESCE(ended_at, '.$this->getConnection()->escape((string) $write['ended_at']).')');
        }

        if ($this->setKeysForSaveQuery($query)->where('status', $this->claimedStatus)->update($write) === 0) {
            return false;
        }

        $this->syncChanges();
        $this->fireModelEvent('updated', false);

        return true;
    }

    /** Whether the deployment (as stored now) runs or is about to run workloads. */
    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'environment_id');
    }

    /** Whether it runs in the project's own (primary) environment. */
    public function isPrimary(): bool
    {
        return $this->getAttribute('environment_id') === null || $this->getAttribute('environment_id') === '';
    }

    /**
     * Limit a deployments query to one environment of a project: $environmentId, or the primary
     * environment (environment_id null) — on a schema without environments every deployment is
     * the primary's.
     *
     * @template TQuery of BuilderContract
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function forEnvironment(BuilderContract $query, ?string $environmentId): BuilderContract
    {
        if (! SchemaColumns::has(new self, 'environment_id')) {
            return $environmentId === null ? $query : $query->whereRaw('1 = 0');
        }

        return $environmentId === null
            ? $query->whereNull('environment_id')
            : $query->where('environment_id', $environmentId);
    }

    /** The deployments of the same environment of the same project (this one included). */
    public function siblings(): Builder
    {
        return self::forEnvironment(self::query()->where('project_id', $this->project_id), $this->isPrimary() ? null : (string) $this->environment_id);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    /** The blue-green / canary rollout that brought this deployment (docs/deploy-strategies.md). */
    public function rollout(): HasOne
    {
        return $this->hasOne(DeploymentRollout::class, 'deployment_id')->latestOfMany();
    }

    /** The newest rollout that started from this deployment (it served while the new one rolled out). */
    public function stableRollout(): HasOne
    {
        return $this->hasOne(DeploymentRollout::class, 'stable_deployment_id')->latestOfMany();
    }

    /**
     * This deployment's part in a rollout that still pins the project's traffic: candidate (the
     * new one), stable (served while the new one rolls out) or previous (kept warm after the
     * promotion); null outside of one. Reads the relations, loaded by the caller.
     */
    public function rolloutRole(): ?string
    {
        if (! DeploymentRollout::available()) {
            return null;
        }

        $own = $this->rollout;
        if ($own instanceof DeploymentRollout && $own->holdsTraffic()) {
            return 'candidate';
        }

        $from = $this->stableRollout;
        if ($from instanceof DeploymentRollout && $from->holdsTraffic()) {
            return $from->status === DeploymentRollout::PROMOTED ? 'previous' : 'stable';
        }

        return null;
    }
}
