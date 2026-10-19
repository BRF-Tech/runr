<?php

namespace Modules\Billing\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Billing\Support\DefaultPrices;
use Modules\Billing\Support\Money;

/**
 * A price version (docs/billing.md#prices): the prices of one region (region_id) or the default
 * prices of the organization (region_id null) from effective_from on, until a later version of
 * the same scope takes over. Every version carries every price; 0 is an explicit "free".
 *
 * @property string $id
 * @property string|null $region_id
 * @property Carbon $effective_from
 * @property float $cpu_vcpu_hour
 * @property float $memory_gb_hour
 * @property float $gpu_hour
 * @property float $cpu_vcpu_month
 * @property float $memory_gb_month
 * @property float $gpu_month
 * @property float $kv_gb_hour
 * @property float $object_gb_hour
 * @property float $postgres_gb_hour
 * @property float $postgres_instance_hour
 * @property float $multiplier_http
 * @property float $multiplier_queue
 * @property float $multiplier_schedule
 * @property string|null $note
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class Price extends Model
{
    use HasUlids;

    protected $table = 'billing_prices';

    protected $fillable = [
        'region_id', 'effective_from', 'note', 'created_by',
        'cpu_vcpu_hour', 'memory_gb_hour', 'gpu_hour', 'cpu_vcpu_month', 'memory_gb_month', 'gpu_month',
        'kv_gb_hour', 'object_gb_hour', 'postgres_gb_hour', 'postgres_instance_hour',
        'multiplier_http', 'multiplier_queue', 'multiplier_schedule',
    ];

    protected function casts(): array
    {
        $casts = ['effective_from' => 'date'];
        foreach (DefaultPrices::fields() as $field) {
            $casts[$field] = 'float';
        }

        return $casts;
    }

    /** @return array<string, float> */
    public function values(): array
    {
        $values = [];
        foreach (DefaultPrices::fields() as $field) {
            $values[$field] = (float) $this->getAttribute($field);
        }

        return $values;
    }

    /**
     * The exact decimals of every price (the stored values, not their float casts).
     *
     * @return array<string, BigDecimal>
     */
    public function exactValues(): array
    {
        $raw = $this->getAttributes();
        $values = [];
        foreach (DefaultPrices::fields() as $field) {
            $values[$field] = Money::dec($raw[$field] ?? null);
        }

        return $values;
    }

    public function effectiveDate(): string
    {
        return $this->effective_from->toDateString();
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'region_id' => $this->region_id,
            'effective_from' => $this->effectiveDate(),
        ] + $this->values() + [
            'note' => $this->note,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
