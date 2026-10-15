<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TenantAwareUser extends User
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->mergeFillable(['is_admin']);
        $this->mergeCasts(['is_admin' => 'boolean']);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->withPivot('role')
            ->withTimestamps();
    }
}
