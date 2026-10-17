<?php

namespace Modules\Tenancy\Support;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Auth\AccountPruner;
use App\Support\Auth\UserDeletionHook;
use App\Support\Rbac\AccessManager;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantLocalUser;
use Modules\Tenancy\Models\TenantUser;

/**
 * Each organization keeps a copy of its members in its own schema (TenantLocalUser), and their
 * groups and role assignments. A deleted account's memberships cascade in the central database;
 * its copies and role rows go here:
 *
 *  - as a UserDeletionHook: the account deleted by a platform administrator or by its user
 *    (UserTokens::deleteUser) — in every organization it belonged to, once the delete is committed;
 *  - as an AccountPruner (tokens:prune): copies and role rows whose central account no longer
 *    exists, left by deletions of earlier releases.
 *
 * Both are idempotent, and an organization whose schema is missing is skipped.
 */
final class TenantMembers implements AccountPruner, UserDeletionHook
{
    public function deleting(User $user): ?Closure
    {
        $userId = $user->getKey();
        $tenantIds = TenantUser::query()->where('user_id', $userId)->pluck('tenant_id')->all();
        if ($tenantIds === []) {
            return null;
        }

        return function () use ($tenantIds, $userId) {
            foreach ($tenantIds as $tenantId) {
                if ($tenant = Tenant::query()->find($tenantId)) {
                    self::forget($tenant, [$userId]);
                }
            }
        };
    }

    public function label(): string
    {
        return 'organization copy(ies) of deleted users';
    }

    public function prune(): int
    {
        $removed = 0;
        Tenant::query()->each(function (Tenant $tenant) use (&$removed) {
            $removed += self::forget($tenant, null);
        });

        return $removed;
    }

    /**
     * In $tenant's schema, the copies, group memberships and role assignments of the users
     * $userIds — null: of every user the schema knows whose central account no longer exists.
     *
     * @param  list<int|string>|null  $userIds
     * @return int the copies deleted
     */
    public static function forget(Tenant $tenant, ?array $userIds): int
    {
        // A missing schema would resolve the tables to the central ones (search_path … , public)
        if (! $tenant->database()->manager()->databaseExists($tenant->database()->getName())) {
            Log::warning('Organization schema missing: its copies of users are not checked', ['tenant' => $tenant->getTenantKey()]);

            return 0;
        }

        try {
            return (int) $tenant->run(function () use ($userIds) {
                $userIds ??= self::gone();
                $copies = 0;
                foreach ($userIds as $userId) {
                    $copies += TenantLocalUser::withoutEvents(fn () => TenantLocalUser::where('id', $userId)->delete());
                    app(AccessManager::class)->forgetUser($userId);
                }

                return $copies;
            });
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Users the current organization's schema refers to (copies, group members, user role
     * assignments) whose central account no longer exists.
     *
     * @return list<string>
     */
    private static function gone(): array
    {
        $known = DB::table((new TenantLocalUser)->getTable())->pluck('id');
        if (Schema::hasTable('rbac_group_user')) {
            $known = $known->merge(DB::table('rbac_group_user')->pluck('user_id'));
        }
        if (Schema::hasTable((new RoleAssignment)->getTable())) {
            $known = $known->merge(RoleAssignment::query()->where('subject_type', RoleAssignment::SUBJECT_USER)->pluck('subject_id'));
        }
        $known = $known->map(fn ($id) => (string) $id)->unique()->values();

        $existing = $known->chunk(500)->flatMap(
            fn ($ids) => DB::connection('central')->table((new User)->getTable())->whereIn('id', $ids->all())->pluck('id')
        )->map(fn ($id) => (string) $id)->all();

        return $known->diff($existing)->values()->all();
    }
}
