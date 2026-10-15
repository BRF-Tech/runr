<?php

namespace App\Workflows\Models;

use App\Workflows\Support\JsonValue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of a workflow.
 *
 * Status:
 *  - queued     ready to be invoked (next_attempt_at: not before; e.g. retry backoff)
 *  - running    an engine worker holds the lease and is invoking the function
 *  - sleeping   step.sleep until next_attempt_at
 *  - waiting    step.waitForEvent / step.invoke, timing out at next_attempt_at
 *  - completed / failed / cancelled / timed_out   terminal
 *
 * @property string $id
 * @property string $workflow_id
 * @property string $project_id
 * @property string $status
 * @property string $trigger_source
 * @property string|null $event_id
 * @property object|null $event
 * @property mixed $output
 * @property array|null $error
 * @property int $attempt
 * @property int $retry_count
 * @property string|null $idempotency_key
 * @property string|null $concurrency_key
 * @property string|null $parent_run_id
 * @property string|null $parent_step_id
 * @property string|null $replay_of_run_id
 * @property string|null $replay_from_step
 * @property string|null $started_by
 * @property string|null $lease_token
 * @property Carbon|null $lease_until
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $timeout_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int $steps_count
 * @property Carbon|null $created_at
 * @property-read Workflow|null $workflow
 * @property-read Collection<int, WorkflowRun> $children
 */
class WorkflowRun extends Model
{
    use HasUlids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SLEEPING = 'sleeping';

    public const WAITING = 'waiting';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const TIMED_OUT = 'timed_out';

    public const STATUSES = [
        self::QUEUED, self::RUNNING, self::SLEEPING, self::WAITING,
        self::COMPLETED, self::FAILED, self::CANCELLED, self::TIMED_OUT,
    ];

    public const ACTIVE = [self::QUEUED, self::RUNNING, self::SLEEPING, self::WAITING];

    public const TERMINAL = [self::COMPLETED, self::FAILED, self::CANCELLED, self::TIMED_OUT];

    public const SOURCES = ['api', 'event', 'cron', 'queue', 'invoke', 'replay', 'test'];

    protected $table = 'workflow_runs';

    protected $fillable = [
        'workflow_id',
        'project_id',
        'status',
        'trigger_source',
        'event_id',
        'event',
        'output',
        'error',
        'attempt',
        'retry_count',
        'idempotency_key',
        'concurrency_key',
        'parent_run_id',
        'parent_step_id',
        'replay_of_run_id',
        'replay_from_step',
        'started_by',
        'lease_token',
        'lease_until',
        'next_attempt_at',
        'dispatched_at',
        'timeout_at',
        'started_at',
        'ended_at',
        'steps_count',
    ];

    protected $hidden = ['lease_token'];

    protected $casts = [
        'event' => JsonValue::class,
        'output' => JsonValue::class,
        'error' => 'array',
        'attempt' => 'integer',
        'retry_count' => 'integer',
        'steps_count' => 'integer',
        'lease_until' => 'datetime',
        'next_attempt_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'timeout_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class, 'run_id')->orderBy('position');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function durationMs(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        $end = $this->ended_at ?? now();

        return (int) round($this->started_at->diffInMilliseconds($end, true));
    }
}
