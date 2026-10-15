<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Sandbox API settings of a region (Settings → Sandboxes). A region without a row uses the
 * defaults: enabled, every installed class allowed, the strongest one by default, runc refused.
 *
 * @property string $knative_region_id
 * @property bool $enabled
 * @property array|null $allowed_classes
 * @property string|null $default_class
 * @property bool $allow_runc
 * @property int|null $default_ttl_seconds
 * @property int|null $max_ttl_seconds
 * @property int|null $max_cpu_m
 * @property int|null $max_memory_mb
 * @property int|null $max_disk_mb
 */
class SandboxRegionSetting extends Model
{
    use UsesCentralConnection;

    protected $primaryKey = 'knative_region_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'knative_region_id', 'enabled', 'allowed_classes', 'default_class', 'allow_runc',
        'default_ttl_seconds', 'max_ttl_seconds', 'max_cpu_m', 'max_memory_mb', 'max_disk_mb',
    ];

    protected $attributes = [
        'enabled' => true,
        'allow_runc' => false,
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'allow_runc' => 'boolean',
        'allowed_classes' => 'array',
        'default_ttl_seconds' => 'integer',
        'max_ttl_seconds' => 'integer',
        'max_cpu_m' => 'integer',
        'max_memory_mb' => 'integer',
        'max_disk_mb' => 'integer',
    ];

    /** The region's row, or an unsaved one with the defaults. */
    public static function forRegion(string $regionId): self
    {
        return static::find($regionId) ?? new static(['knative_region_id' => $regionId]);
    }

    public function maxTtl(): int
    {
        $platform = (int) config('sandbox.limits.max_ttl_seconds', 86400);

        return $this->max_ttl_seconds ? min($this->max_ttl_seconds, $platform) : $platform;
    }

    public function defaultTtl(): int
    {
        return min($this->default_ttl_seconds ?: (int) config('sandbox.defaults.ttl_seconds', 900), $this->maxTtl());
    }

    public function maxCpuM(): int
    {
        $platform = (int) config('sandbox.limits.max_cpu_m', 4000);

        return $this->max_cpu_m ? min($this->max_cpu_m, $platform) : $platform;
    }

    public function maxMemoryMb(): int
    {
        $platform = (int) config('sandbox.limits.max_memory_mb', 8192);

        return $this->max_memory_mb ? min($this->max_memory_mb, $platform) : $platform;
    }

    public function maxDiskMb(): int
    {
        $platform = (int) config('sandbox.limits.max_disk_mb', 10240);

        return $this->max_disk_mb ? min($this->max_disk_mb, $platform) : $platform;
    }
}
