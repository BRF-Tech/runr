<?php

namespace App\Models\ApiProduct;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An API key of a consumer (docs/api-product.md#api-keys). The key itself is shown once, when it is
 * created or rotated; RunR keeps its SHA-256 hash (what the region's gateway-auth compares) and
 * the first characters (prefix) to tell keys apart. scopes null = every route the consumer may
 * call; otherwise [{route_id, methods}] (methods null = every method of the route). plan_id
 * overrides the consumer's plan for this key — its limits are then counted for the key alone.
 *
 * @property string $id
 * @property string $consumer_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property list<array{route_id: string, methods: list<string>|null}>|null $scopes
 * @property string|null $plan_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property string|null $rotated_from_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ApiConsumer $consumer
 * @property-read ApiPlan|null $plan
 */
class ApiKey extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'api_keys';

    protected $fillable = [
        'consumer_id', 'name', 'prefix', 'key_hash', 'scopes', 'plan_id',
        'expires_at', 'revoked_at', 'last_used_at', 'rotated_from_id', 'created_by',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(ApiConsumer::class, 'consumer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ApiPlan::class, 'plan_id');
    }

    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return self::STATUS_REVOKED;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return self::STATUS_ACTIVE;
    }

    public function isUsable(): bool
    {
        return $this->status() === self::STATUS_ACTIVE;
    }
}
