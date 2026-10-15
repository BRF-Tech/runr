<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Incident of an uptime check: opened after failure_threshold consecutive failures, resolved
 * after recovery_threshold consecutive successes (UptimeService). message is the public text of
 * the status page.
 *
 * @property string $id
 * @property string $uptime_check_id
 * @property string $project_id
 * @property string $status
 * @property string $title
 * @property string|null $message
 * @property string|null $cause
 * @property Carbon $started_at
 * @property Carbon|null $resolved_at
 * @property int $failed_checks
 */
class UptimeIncident extends Model
{
    use HasUlids;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $table = 'uptime_incidents';

    protected $fillable = ['uptime_check_id', 'project_id', 'status', 'title', 'message', 'cause', 'started_at', 'resolved_at', 'failed_checks'];

    protected $casts = [
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'failed_checks' => 'integer',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(UptimeCheck::class, 'uptime_check_id');
    }

    public function durationSeconds(): ?int
    {
        $end = $this->resolved_at ?? now();

        return $this->started_at ? (int) $this->started_at->diffInSeconds($end) : null;
    }

    public function toApi(bool $public = false): array
    {
        $data = [
            'id' => $this->id,
            'status' => $this->status,
            'title' => $this->title,
            'message' => $this->message,
            'started_at' => $this->started_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'duration_seconds' => $this->durationSeconds(),
        ];

        if (! $public) {
            $data += [
                'uptime_check_id' => $this->uptime_check_id,
                'project_id' => $this->project_id,
                'cause' => $this->cause,
                'failed_checks' => $this->failed_checks,
            ];
        }

        return $data;
    }
}
