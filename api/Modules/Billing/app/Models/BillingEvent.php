<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A billing event (docs/billing.md#webhook-events) of an organization's event log, delivered to
 * its billing webhook. payload is exactly the JSON body the webhook receives.
 *
 *   delivery_status  pending (to be delivered, retried with backoff) | delivered | failed (retries
 *                    used up) | skipped (no webhook configured when it happened)
 *
 * @property string $id
 * @property string $type
 * @property string|null $tenant_id
 * @property array<string, mixed> $payload
 * @property string|null $dedupe_key
 * @property string $delivery_status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 */
class BillingEvent extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $table = 'billing_events';

    protected $fillable = [
        'id', 'type', 'tenant_id', 'payload', 'dedupe_key', 'delivery_status', 'attempts', 'next_attempt_at', 'delivered_at', 'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'created_at' => $this->payload['created_at'] ?? $this->created_at?->toIso8601String(),
            'payload' => $this->payload,
            'delivery' => [
                'status' => $this->delivery_status,
                'attempts' => $this->attempts,
                'delivered_at' => $this->delivered_at?->toIso8601String(),
                'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
                'last_error' => $this->last_error,
            ],
        ];
    }
}
