<?php

namespace App\Workflows\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One trigger of a workflow, kept as rows for lookups: `event` (start on an event, optional
 * `expression` filter), `cron` (start on a schedule) and `cancel` (cancel running runs on an
 * event, `expression` compares the trigger event with the incoming one).
 *
 * @property string $id
 * @property string $workflow_id
 * @property string $type
 * @property string|null $event
 * @property string|null $cron
 * @property string|null $timezone
 * @property string|null $expression
 * @property Carbon|null $last_fired_at
 * @property-read Workflow|null $workflow
 */
class WorkflowTrigger extends Model
{
    use HasUlids;

    public const TYPE_EVENT = 'event';

    public const TYPE_CRON = 'cron';

    public const TYPE_CANCEL = 'cancel';

    protected $table = 'workflow_triggers';

    protected $fillable = ['workflow_id', 'type', 'event', 'cron', 'timezone', 'expression', 'last_fired_at'];

    protected $casts = [
        'last_fired_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
