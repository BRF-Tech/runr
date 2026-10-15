<?php

namespace Modules\Tenancy\Models;

use App\Models\KnativeRegion;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostConfig extends Model
{
    use HasUlids;

    protected $connection = 'central';

    protected $fillable = [
        'region_id',
        'cpu_price_per_mc_ms',
        'memory_price_per_mib_ms',
        'gpu_price_per_unit_ms',
        'cpu_price_per_core_month',
        'memory_price_per_gb_month',
        'multiplier_http',
        'multiplier_queue',
        'multiplier_schedule',
        'currency',
        'is_active',
        // Bindings (docs/bindings.md): storage per GB-hour, dedicated Postgres instance hour
        'kv_price_per_gb_hour',
        'object_price_per_gb_hour',
        'postgres_price_per_gb_hour',
        'postgres_instance_price_per_hour',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'cpu_price_per_mc_ms' => 'decimal:12',
        'memory_price_per_mib_ms' => 'decimal:12',
        'gpu_price_per_unit_ms' => 'decimal:12',
        'cpu_price_per_core_month' => 'decimal:4',
        'memory_price_per_gb_month' => 'decimal:4',
        'multiplier_http' => 'decimal:2',
        'multiplier_queue' => 'decimal:2',
        'multiplier_schedule' => 'decimal:2',
        'kv_price_per_gb_hour' => 'decimal:8',
        'object_price_per_gb_hour' => 'decimal:8',
        'postgres_price_per_gb_hour' => 'decimal:8',
        'postgres_instance_price_per_hour' => 'decimal:8',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(KnativeRegion::class, 'region_id');
    }

    /**
     * Get the active cost config for a region (or global default).
     */
    public static function forRegion(?string $regionId): self
    {
        $config = null;

        if ($regionId) {
            $config = static::where('region_id', $regionId)
                ->where('is_active', true)
                ->first();
        }

        return $config ?? static::whereNull('region_id')
            ->where('is_active', true)
            ->first() ?? static::unpriced();
    }

    /**
     * Stand-in when no cost config exists: usage is recorded without cost (zero prices,
     * multiplier 1) in the default currency. A bare `new static()` had currency null, which
     * usage_records.currency (NOT NULL) rejected — every aggregation run failed.
     */
    public static function unpriced(): self
    {
        return new static([
            'cpu_price_per_mc_ms' => 0,
            'memory_price_per_mib_ms' => 0,
            'gpu_price_per_unit_ms' => 0,
            'multiplier_http' => 1,
            'multiplier_queue' => 1,
            'multiplier_schedule' => 1,
            'kv_price_per_gb_hour' => 0,
            'object_price_per_gb_hour' => 0,
            'postgres_price_per_gb_hour' => 0,
            'postgres_instance_price_per_hour' => 0,
            'currency' => static::defaultCurrency(),
            'is_active' => false,
        ]);
    }

    public static function defaultCurrency(): string
    {
        $currency = strtoupper(trim((string) config('tenancy.billing.default_currency', 'USD')));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    /**
     * Currency of this config; the default one when the row has none.
     */
    public function effectiveCurrency(): string
    {
        $currency = strtoupper(trim((string) $this->currency));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : static::defaultCurrency();
    }

    /**
     * Get the multiplier for a given entry point type.
     */
    public function getMultiplier(string $entryPoint): float
    {
        return match ($entryPoint) {
            'http' => (float) $this->multiplier_http,
            'queue' => (float) $this->multiplier_queue,
            'schedule' => (float) $this->multiplier_schedule,
            default => 1.0,
        };
    }

    /** Price of one GB-hour of a binding type's storage (kv / object / postgres). */
    public function bindingStoragePrice(string $type): float
    {
        return match ($type) {
            'kv' => (float) $this->kv_price_per_gb_hour,
            'object' => (float) $this->object_price_per_gb_hour,
            'postgres' => (float) $this->postgres_price_per_gb_hour,
            default => 0.0,
        };
    }
}
