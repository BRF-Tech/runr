<?php

namespace App\Workflows\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily metering per workflow: step executions (every attempt of every step the function
 * reported, sleeps / waits / invokes included) and started runs.
 *
 * @property string $id
 * @property Carbon $date
 * @property string $project_id
 * @property string $workflow_id
 * @property int $step_executions
 * @property int $runs_started
 */
class WorkflowUsage extends Model
{
    use HasUlids;

    protected $table = 'workflow_usage';

    protected $fillable = ['date', 'project_id', 'workflow_id', 'step_executions', 'runs_started'];

    protected $casts = [
        'date' => 'date',
        'step_executions' => 'integer',
        'runs_started' => 'integer',
    ];

    public static function record(string $projectId, string $workflowId, int $steps = 0, int $runs = 0): void
    {
        if ($steps === 0 && $runs === 0) {
            return;
        }

        $date = now()->toDateString();

        for ($try = 0; $try < 2; $try++) {
            // whereDate: the date cast stores "Y-m-d 00:00:00" where the column is text (SQLite)
            $updated = static::query()
                ->whereDate('date', $date)
                ->where('workflow_id', $workflowId)
                ->update([
                    'step_executions' => DB::raw('step_executions + '.$steps),
                    'runs_started' => DB::raw('runs_started + '.$runs),
                    'updated_at' => now(),
                ]);

            if ($updated > 0) {
                return;
            }

            try {
                static::query()->create([
                    'date' => $date,
                    'project_id' => $projectId,
                    'workflow_id' => $workflowId,
                    'step_executions' => $steps,
                    'runs_started' => $runs,
                ]);

                return;
            } catch (QueryException) {
                // created concurrently: the unique (date, workflow_id) row exists now — update it
            }
        }
    }
}
