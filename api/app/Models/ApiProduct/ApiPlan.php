<?php

namespace App\Models\ApiProduct;

use App\Models\Gateway;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Rate limits and quotas of API consumers (docs/api-product.md#plans). Every limit is optional:
 * per second (token bucket with burst), per minute (sliding window), per day and per calendar
 * month (UTC). A plan of a gateway is offered on that gateway; gateway_id null = the
 * organization's, offered on every gateway. The region's gateway-auth enforces it.
 *
 * @property string $id
 * @property string|null $gateway_id
 * @property string $name
 * @property string|null $description
 * @property int|null $rate_per_second
 * @property int|null $burst
 * @property int|null $rate_per_minute
 * @property int|null $quota_per_day
 * @property int|null $quota_per_month
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Gateway|null $gateway
 */
class ApiPlan extends Model
{
    use HasUlids;

    protected $table = 'api_plans';

    protected $fillable = [
        'gateway_id', 'name', 'description',
        'rate_per_second', 'burst', 'rate_per_minute', 'quota_per_day', 'quota_per_month',
        'created_by',
    ];

    protected $casts = [
        'rate_per_second' => 'integer',
        'burst' => 'integer',
        'rate_per_minute' => 'integer',
        'quota_per_day' => 'integer',
        'quota_per_month' => 'integer',
        'created_by' => 'integer',
    ];

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function consumers(): HasMany
    {
        return $this->hasMany(ApiConsumer::class, 'plan_id');
    }

    public function keys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'plan_id');
    }

    /**
     * The limits as the gateway-auth service reads them (null = no limit of that kind); burst
     * defaults to the per-second rate.
     *
     * @return array{per_second: int|null, burst: int|null, per_minute: int|null, per_day: int|null, per_month: int|null}
     */
    public function limits(): array
    {
        return [
            'per_second' => $this->rate_per_second,
            'burst' => $this->rate_per_second ? ($this->burst ?: $this->rate_per_second) : null,
            'per_minute' => $this->rate_per_minute,
            'per_day' => $this->quota_per_day,
            'per_month' => $this->quota_per_month,
        ];
    }

    public function inUse(): bool
    {
        return $this->consumers()->exists() || $this->keys()->whereNull('revoked_at')->exists();
    }
}
