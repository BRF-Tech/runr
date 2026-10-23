<?php

namespace App\Workflows\Models;

use App\Workflows\Support\JsonValue;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An event received by the events API, emitted by a workflow (step.sendEvent), bridged from a
 * queue or produced by a cron tick. Events start workflows (event triggers), resume
 * step.waitForEvent and cancel runs (cancel_on) — of their environment (`environment`, a name;
 * null: production) only (docs/workflows.md#environments).
 *
 * @property string $id
 * @property string $name
 * @property mixed $data
 * @property string|null $external_id
 * @property string $source
 * @property string|null $project_id
 * @property string|null $environment
 * @property string|null $sent_by
 * @property int $ts
 * @property int $runs_started
 * @property int $waits_resumed
 * @property int $runs_cancelled
 * @property Carbon|null $created_at
 */
class WorkflowEvent extends Model
{
    use HasUlids;

    public const SOURCES = ['api', 'workflow', 'queue', 'cron', 'test'];

    protected $table = 'workflow_events';

    protected $fillable = [
        'name',
        'data',
        'external_id',
        'source',
        'project_id',
        'environment',
        'sent_by',
        'ts',
        'runs_started',
        'waits_resumed',
        'runs_cancelled',
    ];

    protected $casts = [
        'data' => JsonValue::class,
        'ts' => 'integer',
        'runs_started' => 'integer',
        'waits_resumed' => 'integer',
        'runs_cancelled' => 'integer',
    ];

    /** The event as the function sees it (trigger event, waitForEvent result). */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'data' => $this->data ?? (object) [],
            'ts' => $this->ts,
        ];
    }
}
