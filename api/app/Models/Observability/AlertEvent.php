<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A fired alert. fingerprint identifies "the same problem" (rule + subject): while an event with
 * that fingerprint is firing, the condition repeats are deduplicated (re-notified after the
 * rule's cooldown only), and the resolve notification closes it.
 *
 * @property string $id
 * @property string|null $alert_rule_id
 * @property string $fingerprint
 * @property string $type
 * @property string $severity
 * @property string $status
 * @property string $title
 * @property string|null $message
 * @property array|null $data
 * @property string|null $project_id
 * @property Carbon $fired_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $last_notified_at
 */
class AlertEvent extends Model
{
    use HasUlids;

    public const FIRING = 'firing';

    public const RESOLVED = 'resolved';

    protected $table = 'alert_events';

    protected $fillable = ['alert_rule_id', 'fingerprint', 'type', 'severity', 'status', 'title', 'message', 'data', 'project_id', 'fired_at', 'resolved_at', 'last_notified_at'];

    protected $casts = [
        'data' => 'array',
        'fired_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_notified_at' => 'datetime',
    ];

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'alert_rule_id' => $this->alert_rule_id,
            'type' => $this->type,
            'severity' => $this->severity,
            'status' => $this->status,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data ?? (object) [],
            'project_id' => $this->project_id,
            'fired_at' => $this->fired_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
