<?php

namespace App\Workflows\Models;

use App\Workflows\Support\JsonValue;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a run, keyed by the id the SDK gives it (step_key). Completed and failed steps
 * are memoized: their output / error is sent back to the function on every later invocation.
 *
 * Status: completed | failed (final) · retrying (backoff until wake_at) · sleeping (until wake_at)
 *         · waiting (event or invoked child, until timeout_at) · cancelled
 *
 * @property string $id
 * @property string $run_id
 * @property string $step_key
 * @property string $name
 * @property string $type
 * @property string $status
 * @property int $position
 * @property int $attempt
 * @property int $max_attempts
 * @property mixed $input
 * @property mixed $output
 * @property array|null $error
 * @property array|null $attempts
 * @property Carbon|null $wake_at
 * @property Carbon|null $timeout_at
 * @property string|null $wait_event
 * @property string|null $wait_expression
 * @property string|null $child_run_id
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_ms
 * @property-read WorkflowRun $run
 */
class WorkflowStep extends Model
{
    use HasUlids;

    public const TYPE_RUN = 'run';

    public const TYPE_SLEEP = 'sleep';

    public const TYPE_WAIT = 'wait_for_event';

    public const TYPE_INVOKE = 'invoke';

    public const TYPE_SEND_EVENT = 'send_event';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const RETRYING = 'retrying';

    public const SLEEPING = 'sleeping';

    public const WAITING = 'waiting';

    public const CANCELLED = 'cancelled';

    /** Steps whose result is final and sent to the function as memoized state. */
    public const MEMOIZED = [self::COMPLETED, self::FAILED];

    /** Steps still holding the run (sleep, wait, invoke, retry backoff). */
    public const OPEN = [self::RETRYING, self::SLEEPING, self::WAITING];

    protected $table = 'workflow_steps';

    protected $fillable = [
        'run_id',
        'step_key',
        'name',
        'type',
        'status',
        'position',
        'attempt',
        'max_attempts',
        'input',
        'output',
        'error',
        'attempts',
        'wake_at',
        'timeout_at',
        'wait_event',
        'wait_expression',
        'child_run_id',
        'started_at',
        'ended_at',
        'duration_ms',
    ];

    protected $casts = [
        'input' => JsonValue::class,
        'output' => JsonValue::class,
        'error' => 'array',
        'attempts' => 'array',
        'position' => 'integer',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'duration_ms' => 'integer',
        'wake_at' => 'datetime',
        'timeout_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_id');
    }

    public function isMemoized(): bool
    {
        return in_array($this->status, self::MEMOIZED, true);
    }
}
