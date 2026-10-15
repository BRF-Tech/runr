<?php

namespace Modules\Tenancy\Models;

use App\Services\Bindings\BindingQuotas;
use Illuminate\Database\Eloquent\Model;

/**
 * Binding limits of one tenant (central). Null columns fall back to config bindings.quotas;
 * 0 means unlimited.
 */
class TenantBindingQuota extends Model
{
    protected $connection = 'central';

    protected $table = 'tenant_binding_quotas';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'max_bindings', 'kv_mb', 'object_gb', 'postgres_gb', 'dedicated_postgres'];

    protected $casts = [
        'max_bindings' => 'integer',
        'kv_mb' => 'integer',
        'object_gb' => 'integer',
        'postgres_gb' => 'integer',
        'dedicated_postgres' => 'integer',
    ];

    /** @return array<string, int|null> */
    public function limits(): array
    {
        return $this->only(BindingQuotas::KEYS);
    }
}
