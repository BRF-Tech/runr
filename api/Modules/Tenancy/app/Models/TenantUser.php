<?php

namespace Modules\Tenancy\Models;

use App\Models\User;
use App\Support\Rbac\AccessManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class TenantUser extends Model
{
    protected $table = 'tenant_users';

    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
    ];

    protected static function booted(): void
    {
        // When a user is added to a tenant → sync user to that tenant schema
        static::created(function (TenantUser $tu) {
            try {
                CentralSyncableUser::find($tu->user_id)?->triggerSyncEvent();
            } catch (\Throwable $e) {
                Log::warning('User sync to tenant failed', [
                    'tenant_id' => $tu->tenant_id,
                    'user_id' => $tu->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // When a user is removed from a tenant → delete from tenant schema
        static::deleted(function (TenantUser $tu) {
            $tenant = Tenant::find($tu->tenant_id);
            $tenant?->run(function () use ($tu) {
                TenantLocalUser::withoutEvents(fn () => TenantLocalUser::where('id', $tu->user_id)->delete()
                );
                // Groups and project access of the former member go with them
                app(AccessManager::class)->forgetUser($tu->user_id);
            });
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
