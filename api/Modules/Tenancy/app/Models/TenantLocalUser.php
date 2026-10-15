<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use Stancl\Tenancy\Contracts\Syncable;

class TenantLocalUser extends User implements Syncable
{
    protected $table = 'users';

    protected $fillable = [
        'id',
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
        return CentralSyncableUser::class;
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
     * NO-OP: one-way sync only (central → tenant).
     * Tenant-side changes do NOT propagate back to central.
     */
    public function triggerSyncEvent(): void
    {
        // Intentionally empty
    }
}
