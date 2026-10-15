<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A plan (docs/billing.md): prices per month / year in one currency — the plan fee RunR
 * calculates per period —, a limit bundle that maps onto the tenant quota mechanism, feature flags,
 * trial days, public (listed for tenants and on the public plan list) or hidden. Limits are null
 * when unlimited.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $currency
 * @property string $price_monthly
 * @property string|null $price_yearly
 * @property int|null $cpu_quota_m
 * @property int|null $memory_quota_mb
 * @property int|null $max_projects
 * @property int|null $gpu_quota
 * @property int|null $invocations_per_month
 * @property int|null $storage_quota_mb
 * @property array|null $features
 * @property int $trial_days
 * @property bool $is_public
 * @property bool $is_default
 * @property bool $is_archived
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plan extends Model
{
    use HasUlids;

    public const INTERVALS = ['month', 'year'];

    /** Limits of a plan (null = unlimited) */
    public const LIMITS = ['cpu_quota_m', 'memory_quota_mb', 'max_projects', 'gpu_quota', 'invocations_per_month', 'storage_quota_mb'];

    /**
     * Feature flags a plan can switch off. A flag that is not set on a plan is allowed.
     * priority_support is informational (shown on the plan card); metered_usage (on only when set)
     * adds the tenant's metered usage to the plan fee as a monthly usage charge.
     */
    public const FEATURES = ['custom_domains', 'private_connections', 'gpu', 'api_tokens', 'sso', 'metered_usage', 'priority_support'];

    protected $connection = 'central';

    protected $fillable = [
        'slug', 'name', 'description', 'currency', 'price_monthly', 'price_yearly',
        'cpu_quota_m', 'memory_quota_mb', 'max_projects', 'gpu_quota', 'invocations_per_month', 'storage_quota_mb',
        'features', 'trial_days', 'is_public', 'is_default', 'is_archived', 'sort_order',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'cpu_quota_m' => 'integer',
        'memory_quota_mb' => 'integer',
        'max_projects' => 'integer',
        'gpu_quota' => 'integer',
        'invocations_per_month' => 'integer',
        'storage_quota_mb' => 'integer',
        'features' => 'array',
        'trial_days' => 'integer',
        'is_public' => 'boolean',
        'is_default' => 'boolean',
        'is_archived' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_archived', false)->where('is_public', true);
    }

    public static function defaultPlan(): ?self
    {
        return static::query()->available()->where('is_default', true)->orderBy('sort_order')->first();
    }

    public function price(string $interval): ?float
    {
        $price = $interval === 'year' ? $this->price_yearly : $this->price_monthly;

        return $price === null ? null : round((float) $price, 2);
    }

    public function supportsInterval(string $interval): bool
    {
        return in_array($interval, self::INTERVALS, true) && $this->price($interval) !== null;
    }

    public function isFree(string $interval = 'month'): bool
    {
        return ($this->price($interval) ?? 0.0) <= 0.0;
    }

    public function limit(string $name): ?int
    {
        $value = $this->getAttribute($name);

        return $value === null ? null : (int) $value;
    }

    public function allowsFeature(string $feature): bool
    {
        $features = (array) ($this->features ?? []);

        return ! array_key_exists($feature, $features) || (bool) $features[$feature];
    }

    /** Metered usage is charged on top of the plan fee (feature flag metered_usage, off unless set). */
    public function metersUsage(): bool
    {
        return (bool) (((array) ($this->features ?? []))['metered_usage'] ?? false);
    }

    /**
     * Shape for tenants (subscription page, public plan list) and the billing API.
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'currency' => $this->currency,
            'price_monthly' => $this->price('month') ?? 0.0,
            'price_yearly' => $this->price('year'),
            'trial_days' => (int) $this->trial_days,
            'is_default' => (bool) $this->is_default,
            'limits' => array_combine(self::LIMITS, array_map(fn ($l) => $this->limit($l), self::LIMITS)),
            'features' => (object) array_map('boolval', (array) ($this->features ?? [])),
        ];
    }

    /**
     * Shape for the platform admin.
     */
    public function toAdminArray(): array
    {
        return $this->toPublicArray() + [
            'is_public' => (bool) $this->is_public,
            'is_archived' => (bool) $this->is_archived,
            'sort_order' => (int) $this->sort_order,
            'tenant_count' => (int) ($this->subscriptions_count ?? $this->subscriptions()->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
