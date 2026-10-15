<?php

namespace Modules\Tenancy\Models;

use App\Models\KnativeRegion;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Reserved capacity of a tenant in a region for a monthly price (a capacity-deal charge per month).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $region_id
 * @property int $cpu_m
 * @property int $memory_mb
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $monthly_price
 * @property string|null $currency
 * @property string $status
 * @property string|null $notes
 * @property string|null $infra_cost
 */
class CapacityDeal extends Model
{
    use HasUlids;

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'region_id',
        'cpu_m',
        'memory_mb',
        'start_date',
        'end_date',
        'monthly_price',
        'currency',
        'status',
        'notes',
        'infra_cost',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'monthly_price' => 'decimal:2',
        'infra_cost' => 'decimal:2',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'region_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    public function isExpired(): bool
    {
        return $this->end_date->isPast();
    }
}
