<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An event stored for a trigger until its retention ends (docs/triggers.md): what a webhook inbox
 * received (kind webhook) or a platform event sent to a function (kind platform).
 *
 * The project's event gateway claims pending events (a lease, so a gateway that died mid-delivery
 * gives them back), delivers them as CloudEvents with retries and reports the outcome
 * (App\Triggers\Inbox\InboxService). A replay sets an event pending again.
 *
 * @property string $id
 * @property string $project_id
 * @property string $trigger_id
 * @property string $kind webhook | platform
 * @property string $event_type CloudEvents type
 * @property string|null $subject
 * @property string|null $delivery_id the sender's delivery id / the platform event's identity (dedupe key)
 * @property string|null $content_type
 * @property array<string, string>|null $headers request headers of a webhook (secrets removed)
 * @property string|null $body base64 of the raw body
 * @property int $body_size
 * @property string $status pending | delivering | delivered | failed
 * @property int $attempts delivery attempts of the current round (a replay starts a new round)
 * @property int $replays
 * @property string|null $last_error
 * @property Carbon|null $lease_until
 * @property Carbon|null $received_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProjectTrigger $trigger
 *
 * @method static Builder<static>|TriggerEvent query()
 */
class TriggerEvent extends Model
{
    use HasUlids;

    public const KIND_WEBHOOK = 'webhook';

    public const KIND_PLATFORM = 'platform';

    public const PENDING = 'pending';

    public const DELIVERING = 'delivering';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::DELIVERING, self::DELIVERED, self::FAILED];

    protected $fillable = [
        'project_id', 'trigger_id', 'kind', 'event_type', 'subject', 'delivery_id', 'content_type',
        'headers', 'body', 'body_size', 'status', 'attempts', 'replays', 'last_error', 'lease_until',
        'received_at', 'delivered_at', 'expires_at',
    ];

    protected $hidden = ['body'];

    protected $attributes = [
        'status' => self::PENDING,
        'attempts' => 0,
        'replays' => 0,
        'body_size' => 0,
    ];

    protected $casts = [
        'headers' => 'array',
        'attempts' => 'integer',
        'replays' => 'integer',
        'body_size' => 'integer',
        'lease_until' => 'datetime',
        'received_at' => 'datetime',
        'delivered_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(ProjectTrigger::class, 'trigger_id');
    }

    /** The raw body. */
    public function rawBody(): string
    {
        if ($this->body === null || $this->body === '') {
            return '';
        }

        return (string) base64_decode($this->body, true);
    }

    /** Metadata of the event (no body). */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'trigger_id' => $this->trigger_id,
            'kind' => $this->kind,
            'type' => $this->event_type,
            'subject' => $this->subject,
            'delivery_id' => $this->delivery_id,
            'content_type' => $this->content_type,
            'size' => $this->body_size,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'replays' => $this->replays,
            'last_error' => $this->last_error,
            'received_at' => $this->received_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
