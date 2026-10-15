<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $knative_region_id
 * @property string $subdomain
 * @property string|null $custom_domain
 * @property string $status
 * @property int|null $rate_limit_average
 * @property int|null $rate_limit_burst
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read KnativeRegion|null $knativeRegion
 * @property-read Collection<int, GatewayRoute> $routes
 * @property-read int|null $routes_count
 * @property-read User|null $user
 *
 * @method static Builder<static>|Gateway newModelQuery()
 * @method static Builder<static>|Gateway newQuery()
 * @method static Builder<static>|Gateway query()
 * @method static Builder<static>|Gateway whereCreatedAt($value)
 * @method static Builder<static>|Gateway whereCustomDomain($value)
 * @method static Builder<static>|Gateway whereId($value)
 * @method static Builder<static>|Gateway whereKnativeRegionId($value)
 * @method static Builder<static>|Gateway whereStatus($value)
 * @method static Builder<static>|Gateway whereSubdomain($value)
 * @method static Builder<static>|Gateway whereUpdatedAt($value)
 * @method static Builder<static>|Gateway whereUserId($value)
 *
 * @mixin Eloquent
 */
class Gateway extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id',
        'knative_region_id',
        'subdomain',
        'custom_domain',
        'status',
        'rate_limit_average',
        'rate_limit_burst',
    ];

    protected $casts = [
        'rate_limit_average' => 'integer',
        'rate_limit_burst' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function knativeRegion(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(GatewayRoute::class)->orderBy('sort_order');
    }
}
