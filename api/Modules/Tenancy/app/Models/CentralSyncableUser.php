<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;
use Stancl\Tenancy\Events\SyncedResourceSaved;

class CentralSyncableUser extends User implements SyncMaster
{
    use ResourceSyncing;

    protected $table = 'users';

    protected $connection = 'central';

    private const EXCLUDED_FROM_SYNC = [
        'is_admin',
        'remember_token',
    ];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(
            Tenant::class,
            'tenant_users',
            'user_id',
            'tenant_id',
        )->withPivot('role')
            ->withTimestamps();
    }

    public function getTenantModelName(): string
    {
        return TenantLocalUser::class;
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'id';
    }

    public function getGlobalIdentifierKey()
    {
        return $this->getKey();
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'name',
            'username',
            'email',
            'password',
            'email_verified_at',
            'tutorial_completed_at',
            'avatar_path',
            'bio',
            'phone',
            'two_factor_confirmed_at',
            'is_disabled',
            'disabled_at',
        ];
    }

    /**
     * Always fire as central context (null tenant) to ensure downward sync
     * to ALL attached tenants, regardless of current tenant context.
     */
    public function triggerSyncEvent(): void
    {
        event(new SyncedResourceSaved($this, null));
    }
}
