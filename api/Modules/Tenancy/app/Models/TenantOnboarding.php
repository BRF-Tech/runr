<?php

namespace Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Onboarding wizard state of a tenant created by self-service signup.
 *
 * @property string $tenant_id
 * @property string $status pending | completed
 * @property string|null $region_id
 * @property string|null $created_by
 * @property Carbon|null $completed_at
 */
class TenantOnboarding extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $connection = 'central';

    protected $table = 'tenant_onboarding';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'status', 'region_id', 'created_by', 'completed_at'];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
