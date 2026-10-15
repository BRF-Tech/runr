<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One notification of one channel (the delivery log): an alert, its resolve message or a test.
 * Failed sends are retried with backoff (DeliverAlertJob) until delivery_tries.
 *
 * @property string $id
 * @property string|null $alert_event_id
 * @property string $alert_channel_id
 * @property string $kind
 * @property string $status
 * @property int $attempts
 * @property int|null $response_code
 * @property string|null $last_error
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 */
class AlertDelivery extends Model
{
    use HasUlids;

    public const KIND_ALERT = 'alert';

    public const KIND_RESOLVE = 'resolve';

    public const KIND_TEST = 'test';

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $table = 'alert_deliveries';

    protected $fillable = ['alert_event_id', 'alert_channel_id', 'kind', 'status', 'attempts', 'response_code', 'last_error', 'next_attempt_at', 'sent_at'];

    protected $casts = [
        'attempts' => 'integer',
        'response_code' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(AlertEvent::class, 'alert_event_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertChannel::class, 'alert_channel_id');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'alert_event_id' => $this->alert_event_id,
            'alert_channel_id' => $this->alert_channel_id,
            'channel_name' => $this->channel?->name,
            'channel_type' => $this->channel?->type,
            'kind' => $this->kind,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'response_code' => $this->response_code,
            'last_error' => $this->last_error,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'event' => $this->event ? [
                'title' => $this->event->title,
                'severity' => $this->event->severity,
                'status' => $this->event->status,
            ] : null,
        ];
    }
}
