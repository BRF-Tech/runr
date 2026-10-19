<?php

namespace App\Models;

use App\Support\SchemaColumns;
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
 * @property Carbon|null $ended_at When the deployment stopped running (left pending / running / active)
 * @property array<string, mixed>|null $sizing The size it was applied with (App\Services\Helpers\WorkloadSizing)
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

    /** States in which a deployment runs (or is about to run) workloads in its region. */
    const LIVE = ['pending', 'running', 'active'];

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
    ];

    protected $casts = [
        'deploy_config' => 'array',
        'knative_ready' => 'boolean',
        'ready_at' => 'datetime',
        'ended_at' => 'datetime',
        'sizing' => 'array',
    ];

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

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }
}
