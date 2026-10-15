<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $status
 * @property string|null $status_message
 * @property Carbon|null $created_at
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;

    /** Normal operation */
    public const STATUS_ACTIVE = 'active';

    /** DeleteTenantJob is tearing the tenant down: no new deploys/builds, writes answer 409 */
    public const STATUS_DELETING = 'deleting';

    /** Teardown left something behind: row + database kept, status_message says what */
    public const STATUS_DELETE_FAILED = 'delete_failed';

    protected $fillable = [
        'id',
        'name',
        'slug',
        'data',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Columns stored as real DB columns (not in data JSON).
     * status / status_message are written only by the deletion flow (never mass-assigned).
     */
    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'slug', 'status', 'status_message'];
    }

    public function isActive(): bool
    {
        return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Central regions assigned to this tenant (with quota pivot data).
     * Uses CentralSyncableRegion to always query central schema.
     */
    public function regions()
    {
        return $this->belongsToMany(CentralSyncableRegion::class, 'tenant_regions', 'tenant_id', 'knative_region_id')
            ->withPivot('cpu_quota_m', 'memory_quota_mb', 'gpu_quota')
            ->withTimestamps();
    }
}
