<?php

namespace App\Models;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiPlan;
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
 * @property string|null $custom_domain_pending
 * @property string|null $custom_domain_token
 * @property Carbon|null $custom_domain_verified_at
 * @property bool $developer_page
 * @property bool $developer_page_metrics
 * @property string|null $description
 * @property string|null $status_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read KnativeRegion|null $knativeRegion
 * @property-read Collection<int, GatewayRoute> $routes
 * @property-read int|null $routes_count
 * @property-read User|null $user
 * @property-read Collection<int, ApiConsumer> $consumers
 * @property-read Collection<int, ApiPlan> $plans
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
        // API product (docs/api-product.md): own domain (custom_domain once verified), developer page
        'custom_domain_pending',
        'custom_domain_token',
        'custom_domain_verified_at',
        'developer_page',
        'description',
        // the developer page shows the routes' latency and success rate (no counts, no consumers)
        'developer_page_metrics',
        // why the gateway is pending / in error (GatewayService::syncToCluster)
        'status_message',
    ];

    protected $hidden = ['custom_domain_token'];

    protected $casts = [
        'rate_limit_average' => 'integer',
        'rate_limit_burst' => 'integer',
        'custom_domain_verified_at' => 'datetime',
        'developer_page' => 'boolean',
        'developer_page_metrics' => 'boolean',
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

    /** Consumers of this gateway only (the organization's consumers are accepted too). */
    public function consumers(): HasMany
    {
        return $this->hasMany(ApiConsumer::class);
    }

    /** Plans of this gateway only (the organization's plans are offered too). */
    public function plans(): HasMany
    {
        return $this->hasMany(ApiPlan::class);
    }

    /** Whether a route asks callers for an API key or a JWT (the region's gateway-auth checks them). */
    public function hasAuthenticatedRoutes(): bool
    {
        return $this->routes->contains(fn (GatewayRoute $route) => $route->requiresAuth());
    }
}
