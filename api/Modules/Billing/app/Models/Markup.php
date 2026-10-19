<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tenant's markup version (docs/billing.md#markup): the percentage the tenant adds to the
 * platform price of a shared region (region_id) or by default (region_id null) from effective_from
 * on. Versioned like prices: a change takes effect on its date, the old percentage holds until then.
 *
 * @property string $id
 * @property string|null $region_id
 * @property float $percent
 * @property Carbon $effective_from
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class Markup extends Model
{
    use HasUlids;

    protected $table = 'billing_markups';

    protected $fillable = ['region_id', 'percent', 'effective_from', 'created_by'];

    protected $casts = [
        'percent' => 'float',
        'effective_from' => 'date',
    ];

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'region_id' => $this->region_id,
            'percent' => round((float) $this->percent, 3),
            'effective_from' => $this->effective_from->toDateString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
