<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A background process started through POST /sandboxes/{id}/processes. Its output and exit code
 * live in the sandbox (/tmp/.runr/proc/<id>/{out,err,exit}); the row tracks the OS pid and state.
 *
 * @property string $id
 * @property string $sandbox_id
 * @property int|null $pid
 * @property string $command
 * @property string|null $cwd
 * @property string $status
 * @property int|null $exit_code
 * @property string|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 */
class SandboxProcess extends Model
{
    use HasUlids, UsesCentralConnection;

    public const STATUS_RUNNING = 'running';

    public const STATUS_EXITED = 'exited';

    public const STATUS_KILLED = 'killed';

    protected $fillable = ['sandbox_id', 'pid', 'command', 'cwd', 'status', 'exit_code', 'created_by', 'started_at', 'ended_at'];

    protected $casts = [
        'pid' => 'integer',
        'exit_code' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function sandbox(): BelongsTo
    {
        return $this->belongsTo(Sandbox::class);
    }

    /** Directory of the process's output files inside the sandbox. */
    public function dir(): string
    {
        return '/tmp/.runr/proc/'.strtolower($this->id);
    }
}
