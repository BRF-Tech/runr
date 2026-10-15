<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Warm pool of an organization (tenant_id; null on a single-tenant install / central context) in
 * a region: `size` generic sandboxes of one runtime/version and size kept started, claimed by a
 * create request with the same spec (env and files are injected after the claim).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $knative_region_id
 * @property string $runtime
 * @property string|null $runtime_version
 * @property string|null $sandbox_class
 * @property int $cpu_m
 * @property int $memory_mb
 * @property int $disk_mb
 * @property int $size
 * @property bool $is_enabled
 * @property int $hits
 * @property int $misses
 * @property string|null $created_by
 * @property string|null $last_error
 */
class SandboxPool extends Model
{
    use HasUlids, UsesCentralConnection;

    protected $fillable = [
        'tenant_id', 'knative_region_id', 'runtime', 'runtime_version', 'sandbox_class', 'cpu_m',
        'memory_mb', 'disk_mb', 'size', 'is_enabled', 'hits', 'misses', 'created_by', 'last_error',
    ];

    protected $casts = [
        'cpu_m' => 'integer',
        'memory_mb' => 'integer',
        'disk_mb' => 'integer',
        'size' => 'integer',
        'is_enabled' => 'boolean',
        'hits' => 'integer',
        'misses' => 'integer',
    ];

    public function sandboxes(): HasMany
    {
        return $this->hasMany(Sandbox::class, 'pool_id');
    }

    public function warmSandboxes(): HasMany
    {
        return $this->sandboxes()->whereIn('status', Sandbox::POOL_STATUSES);
    }
}
