<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A purchase made in an external system and recorded through the billing API (docs/billing.md),
 * with that system's reference:
 *
 *   credit  amount + currency — closed charges draw on the tenant's credit balance
 *   plan    plan_id + interval — the plan term is prepaid: no plan fee is calculated for it
 *   addon   limits — added to the tenant's plan limits from starts_at until ends_at
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $external_ref
 * @property string|null $description
 * @property string|null $amount
 * @property string|null $currency
 * @property string|null $plan_id
 * @property string|null $interval
 * @property array|null $limits
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon $purchased_at
 * @property Carbon|null $voided_at
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property-read Plan|null $plan
 */
class Purchase extends Model
{
    use HasUlids;

    public const KIND_CREDIT = 'credit';

    public const KIND_PLAN = 'plan';

    public const KIND_ADDON = 'addon';

    public const KINDS = [self::KIND_CREDIT, self::KIND_PLAN, self::KIND_ADDON];

    /** Plan limits an add-on can raise */
    public const ADDON_LIMITS = Plan::LIMITS;

    protected $connection = 'central';

    protected $table = 'billing_purchases';

    protected $fillable = [
        'tenant_id', 'kind', 'external_ref', 'description', 'amount', 'currency', 'plan_id', 'interval',
        'limits', 'starts_at', 'ends_at', 'purchased_at', 'voided_at', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'limits' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'purchased_at' => 'datetime',
        'voided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /** Add-ons in effect at $at (not voided, started, not ended). */
    public function scopeActiveAddons(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->where('kind', self::KIND_ADDON)
            ->whereNull('voided_at')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function toApiArray(): array
    {
        $this->loadMissing('plan');

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'kind' => $this->kind,
            'external_ref' => $this->external_ref,
            'description' => $this->description,
            'amount' => $this->amount === null ? null : (float) $this->amount,
            'currency' => $this->currency,
            'plan' => $this->plan ? ['id' => $this->plan->id, 'slug' => $this->plan->slug, 'name' => $this->plan->name] : null,
            'interval' => $this->interval,
            'limits' => $this->limits === null ? null : (object) $this->limits,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'purchased_at' => $this->purchased_at->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'metadata' => (object) ($this->metadata ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
