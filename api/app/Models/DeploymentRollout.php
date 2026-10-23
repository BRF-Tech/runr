<?php

namespace App\Models;

use App\Services\Environments\DeployTarget;
use App\Services\GitDeploy\GitTables;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A blue-green or canary rollout of a deployment (docs/deploy-strategies.md): the
 * new deployment's revision is created next to the one that serves, with a traffic tag and a share
 * of the traffic that the rollout moves (App\Services\Rollouts\RolloutService).
 *
 * @property string $id
 * @property string $project_id
 * @property string $deployment_id the new deployment ("candidate")
 * @property string|null $stable_deployment_id the deployment that served before (the previous one once promoted)
 * @property string|null $region_id the region whose Knative Service carries the split
 * @property string $strategy blue_green | canary
 * @property string $status see STATUSES
 * @property array<string, mixed>|null $options the strategy's options at deploy time
 * @property string|null $revision
 * @property string|null $stable_revision
 * @property string $tag green | canary
 * @property int $traffic_percent the new revision's share
 * @property int|null $step_index canary: the step whose share is applied
 * @property Carbon|null $step_started_at
 * @property array<string, mixed>|null $health blue-green health checks {passes, last_status, last_error, checked_at, deadline_at}
 * @property array<string, mixed>|null $metrics canary: the last measurement {tracing, error_rate, p95_ms, volume, window_minutes, measured_at}
 * @property Carbon|null $next_check_at when the rollout is looked at next (AdvanceRolloutJob)
 * @property Carbon|null $promoted_at
 * @property Carbon|null $keep_until the previous revision is kept warm until then
 * @property Carbon|null $released_at the previous revision was released
 * @property Carbon|null $finished_at aborted, rolled back or failed
 * @property string|null $reason
 * @property list<array<string, mixed>>|null $events
 * @property string|null $created_by
 * @property string|null $environment_id the environment whose workload it rolls out (null: the project's own)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Deployment|null $deployment
 * @property-read Deployment|null $stableDeployment
 */
class DeploymentRollout extends Model
{
    use HasUlids;

    public const BLUE_GREEN = 'blue_green';

    public const CANARY = 'canary';

    public const STRATEGIES = [self::BLUE_GREEN, self::CANARY];

    public const PENDING = 'pending';

    public const PROGRESSING = 'progressing';

    public const WAITING_PROMOTION = 'waiting_promotion';

    public const PROMOTED = 'promoted';

    public const ABORTED = 'aborted';

    public const ROLLED_BACK = 'rolled_back';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::PROGRESSING, self::WAITING_PROMOTION, self::PROMOTED, self::ABORTED, self::ROLLED_BACK, self::FAILED];

    /** A rollout under way: the new revision is not promoted yet. */
    public const OPEN = [self::PENDING, self::PROGRESSING, self::WAITING_PROMOTION];

    /** Ended without promotion (or rolled back after it): traffic is on the revision that served before. */
    public const ENDED = [self::ABORTED, self::ROLLED_BACK, self::FAILED];

    /** Events kept on a rollout (the oldest go first). */
    public const MAX_EVENTS = 100;

    protected $fillable = [
        'project_id', 'deployment_id', 'stable_deployment_id', 'region_id', 'strategy', 'status',
        'options', 'revision', 'stable_revision', 'tag', 'traffic_percent', 'step_index',
        'step_started_at', 'health', 'metrics', 'next_check_at', 'promoted_at', 'keep_until',
        'released_at', 'finished_at', 'reason', 'events', 'created_by', 'environment_id',
    ];

    protected $casts = [
        'options' => 'array',
        'health' => 'array',
        'metrics' => 'array',
        'events' => 'array',
        'traffic_percent' => 'integer',
        'step_index' => 'integer',
        'step_started_at' => 'datetime',
        'next_check_at' => 'datetime',
        'promoted_at' => 'datetime',
        'keep_until' => 'datetime',
        'released_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Whether the table exists in the current schema (a tenant schema before TenantMigrations/0072 has none). */
    public static function available(): bool
    {
        return GitTables::present('deployment_rollouts');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * Promoted, the previous revision still kept warm: a rollback switches back to it at once.
     */
    public function keepsPrevious(): bool
    {
        return $this->status === self::PROMOTED && $this->released_at === null && $this->stable_deployment_id !== null
            && $this->keep_until !== null;
    }

    /**
     * Whether the rollout still pins the Knative Service's traffic: open, or promoted while the
     * previous revision is kept.
     */
    public function holdsTraffic(): bool
    {
        return $this->isOpen() || $this->keepsPrevious();
    }

    /**
     * Open, or promoted while the previous revision is kept.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereIn('status', self::OPEN)
            ->orWhere(fn ($kept) => $kept->where('status', self::PROMOTED)->whereNull('released_at')->whereNotNull('keep_until')));
    }

    /** The canary steps (percentages). @return list<int> */
    public function steps(): array
    {
        return array_values(array_map('intval', (array) ($this->options['steps'] ?? [])));
    }

    /** Appends an event to the history (kept to MAX_EVENTS); saved with the next save. */
    public function addEvent(string $type, string $message, ?string $actor = null, ?int $traffic = null): void
    {
        $events = (array) ($this->events ?? []);
        $events[] = array_filter([
            'at' => now()->toIso8601String(),
            'type' => $type,
            'message' => mb_substr($message, 0, 500),
            'traffic' => $traffic ?? $this->traffic_percent,
            'actor' => $actor,
        ], fn ($v) => $v !== null);

        $this->events = array_slice($events, -self::MAX_EVENTS);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function stableDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'stable_deployment_id');
    }

    /** The environment the rollout's deployments belong to (null: the project's own workload). */
    public function environmentId(): ?string
    {
        $own = $this->getAttribute('environment_id');
        if (is_string($own) && $own !== '') {
            return $own;
        }
        $deployment = Deployment::query()->find($this->deployment_id, ['id', 'project_id', 'environment_id']);

        return $deployment !== null && ! $deployment->isPrimary() ? (string) $deployment->environment_id : null;
    }

    /**
     * Rollouts of the workload $environmentId names (null: the project's own): by the column, and
     * for rows written before it by their deployment's environment.
     *
     * @param  Builder<DeploymentRollout>  $query
     */
    public function scopeOfEnvironment(Builder $query, string $projectId, ?string $environmentId): void
    {
        $deployments = Deployment::forEnvironment(Deployment::query()->where('project_id', $projectId), $environmentId)->select('id');
        $query->where('project_id', $projectId)->whereIn('deployment_id', $deployments);
    }

    /**
     * The workload the rollout moves the traffic of: the project's own Knative Service, or for a
     * rollout of an environment (docs/deploy-strategies.md#environments) the environment's view of
     * the project — its Knative Service <workload>, its region, its config. RolloutTraffic, the
     * health checks, the canary's metrics and the warmer act on it.
     */
    public function workload(): Project
    {
        $project = $this->project;
        $environmentId = $this->environmentId();
        if ($environmentId === null) {
            return $project;
        }

        $deployment = Deployment::query()->find($this->deployment_id);
        if ($deployment !== null) {
            return DeployTarget::project($project, $deployment);
        }
        // The deployment is gone: the environment's view all the same — never the project's own
        // workload, whose traffic the rollout never moved
        $environment = ProjectEnvironment::query()->find($environmentId)
            ?? new ProjectEnvironment(['project_id' => $project->getKey(), 'name' => 'gone', 'workload_name' => (string) $this->revision]);

        return DeployTarget::environment($project, $environment);
    }
}
