<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Billing\Support\Money;

/**
 * A fixed resource request of a project in a fixed region (docs/billing.md#fixed-regions): the
 * resources asked for, priced from the region's monthly unit prices when requested. Once approved
 * it is the project's allocation from active_from (the approval day) until active_until (the day
 * before a newer approval, or the day the project was deleted or moved).
 *
 *   pending → approved → superseded | ended
 *   pending → rejected | cancelled
 *
 * Rows with tenant_id are central copies of a tenant's allocations in platform regions — the
 * platform bills the tenant for them (source_id = the row in the tenant's schema).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $source_id
 * @property string $project_id
 * @property string|null $project_name
 * @property string $region_id
 * @property int $cpu_m
 * @property int $memory_mb
 * @property int $gpu_count
 * @property string $status
 * @property float $monthly_price
 * @property string $currency
 * @property string|null $note
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property Carbon|null $active_from
 * @property Carbon|null $active_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FixedRequest extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const SUPERSEDED = 'superseded';

    public const ENDED = 'ended';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::CANCELLED, self::SUPERSEDED, self::ENDED];

    /** Statuses of a request that was an allocation for some time. */
    public const ALLOCATED = [self::APPROVED, self::SUPERSEDED, self::ENDED];

    protected $table = 'billing_fixed_requests';

    protected $fillable = [
        'id', 'tenant_id', 'source_id', 'project_id', 'project_name', 'region_id', 'cpu_m', 'memory_mb', 'gpu_count',
        'status', 'monthly_price', 'currency', 'note', 'requested_by', 'decided_by', 'decided_at',
        'decision_reason', 'active_from', 'active_until', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'cpu_m' => 'integer',
        'memory_mb' => 'integer',
        'gpu_count' => 'integer',
        'monthly_price' => 'float',
        'decided_at' => 'datetime',
        'active_from' => 'date',
        'active_until' => 'date',
    ];

    /** Rows of the organization itself (never the central copies of tenant allocations). */
    public function scopeOwn(Builder $query): Builder
    {
        return $query->whereNull('tenant_id');
    }

    /** The approved allocations in effect now. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED)->whereNull('active_until');
    }

    public function isActive(): bool
    {
        return $this->status === self::APPROVED && $this->active_until === null;
    }

    /** Is the allocation in effect on $date (Y-m-d)? Open-ended allocations cover every later day. */
    public function coversDay(string $date): bool
    {
        if (! in_array($this->status, self::ALLOCATED, true) || ! $this->active_from) {
            return false;
        }

        return $this->active_from->toDateString() <= $date
            && ($this->active_until === null || $this->active_until->toDateString() >= $date);
    }

    /**
     * @param  array<string, string>  $regionNames  region id → name
     * @param  array<string, array{id: string, name: string|null}>  $users  user id → {id, name}
     */
    public function toApiArray(array $regionNames = [], array $users = []): array
    {
        $user = fn (?string $id) => $id === null ? null : ($users[$id] ?? ['id' => $id, 'name' => null]);

        return [
            'id' => $this->id,
            'project' => ['id' => $this->project_id, 'name' => $this->project_name],
            'region' => ['id' => $this->region_id, 'name' => $regionNames[$this->region_id] ?? null],
            'cpu_m' => $this->cpu_m,
            'memory_mb' => $this->memory_mb,
            'gpu_count' => $this->gpu_count,
            'vcpu' => round($this->cpu_m / 1000, 3),
            'memory_gb' => round($this->memory_mb / 1024, 3),
            'monthly_price' => Money::roundedFloat(Money::dec($this->getAttributes()['monthly_price'] ?? null), Money::minorUnit($this->currency)),
            'currency' => $this->currency,
            'status' => $this->status,
            'note' => $this->note,
            'requested_by' => $user($this->requested_by),
            'requested_at' => $this->created_at?->toIso8601String(),
            'decided_by' => $user($this->decided_by),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_reason' => $this->decision_reason,
            'active_from' => $this->active_from?->toDateString(),
            'active_until' => $this->active_until?->toDateString(),
        ];
    }
}
