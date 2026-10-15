<?php

namespace App\Models;

use App\Models\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sandbox quota of an organization (tenant_id) or the defaults (tenant_id null) every organization
 * without its own row — and a single-tenant install — gets. A null field falls back to the defaults
 * row, then to config('sandbox.quota'). 0 = unlimited.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property int|null $max_concurrent
 * @property int|null $max_cpu_m
 * @property int|null $max_memory_mb
 * @property int|null $max_sandbox_seconds_month
 * @property int|null $max_pool_size
 * @property array|null $allowed_classes
 */
class SandboxQuota extends Model
{
    use HasUlids, UsesCentralConnection;

    public const LIMIT_FIELDS = ['max_concurrent', 'max_cpu_m', 'max_memory_mb', 'max_sandbox_seconds_month', 'max_pool_size'];

    protected $fillable = [
        'tenant_id', 'max_concurrent', 'max_cpu_m', 'max_memory_mb', 'max_sandbox_seconds_month',
        'max_pool_size', 'allowed_classes',
    ];

    protected $casts = [
        'max_concurrent' => 'integer',
        'max_cpu_m' => 'integer',
        'max_memory_mb' => 'integer',
        'max_sandbox_seconds_month' => 'integer',
        'max_pool_size' => 'integer',
        'allowed_classes' => 'array',
    ];
}
