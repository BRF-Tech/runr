<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's plan (one row per tenant that has or had a plan — docs/billing.md). PlanService owns
 * the transitions:
 *
 *   trialing → active        the trial (the first period) ended
 *   active → active          the period rolled over (plan fee calculated, unless prepaid)
 *   any → canceled           the plan ended without a default plan to fall back to
 *
 * prepaid: the running term was bought in an external system (plan purchase) — no plan fee is
 * calculated for it and the plan ends with the term unless another plan purchase extends it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $plan_id
 * @property string $interval
 * @property string $status
 * @property bool $prepaid
 * @property string|null $external_ref
 * @property bool $trial_used
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property bool $cancel_at_period_end
 * @property array|null $metadata
 * @property Carbon|null $updated_at
 * @property-read Plan|null $plan
 */
class TenantSubscription extends Model
{
    use HasUlids;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = ['trialing', 'active', 'canceled'];

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id', 'plan_id', 'interval', 'status', 'prepaid', 'external_ref', 'trial_used', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'cancel_at_period_end', 'metadata',
    ];

    protected $attributes = [
        'interval' => 'month',
        'status' => self::STATUS_ACTIVE,
        'prepaid' => false,
    ];

    protected $casts = [
        'prepaid' => 'boolean',
        'trial_used' => 'boolean',
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'metadata' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public static function forTenant(string $tenantId): self
    {
        return static::query()->firstOrCreate(['tenant_id' => $tenantId], ['status' => self::STATUS_ACTIVE, 'interval' => 'month']);
    }

    public static function findForTenant(string $tenantId): ?self
    {
        return static::query()->where('tenant_id', $tenantId)->first();
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata ?? [], $key, $default);
    }

    public function setMeta(string $key, mixed $value): void
    {
        $meta = (array) ($this->metadata ?? []);
        data_set($meta, $key, $value);
        $this->metadata = $meta;
    }

    public function toApiArray(): array
    {
        $this->loadMissing('plan');

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'plan_id' => $this->plan_id,
            'plan' => $this->plan?->toPublicArray(),
            'interval' => $this->interval,
            'status' => $this->status,
            'prepaid' => (bool) $this->prepaid,
            'external_ref' => $this->external_ref,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
