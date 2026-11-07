<?php

namespace App\Support\Rbac;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who hears about the installation itself — a refused runtime patch feed, the platform-wide count
 * behind a critical patch, a region reconcile an upgrade left unfinished, the alarms of the
 * install's bot protection: its operators (Rbac::operatesPlatform()). The platform administrators
 * (users.is_admin, on the platform's connection) and, in the single-tenant edition — which has no
 * such flag — the members holding platform.manage through their own role or a group's (the Owners
 * by default).
 */
class PlatformOperators
{
    public function __construct(private readonly Rbac $rbac) {}

    /**
     * @param  string|null  $connection  where the platform's accounts are: "central" with tenancy,
     *                                   the default connection (null) otherwise
     * @return Collection<int, User>
     */
    public function users(?string $connection = null): Collection
    {
        $users = DB::connection($connection)->getSchemaBuilder()->hasColumn('users', 'is_admin')
            ? User::on($connection)->where('is_admin', true)->get()
            : new Collection;

        if (PermissionCatalog::edition() !== 'single') {
            return $users;
        }

        $known = $users->map(fn (User $user) => (string) $user->getKey())->all();
        $ids = array_values(array_diff($this->rbac->memberIdsHolding(PermissionCatalog::PLATFORM_MANAGE, groups: true), $known));
        if ($ids === []) {
            return $users;
        }

        return $users->concat(User::on($connection)->whereIn('id', $ids)->orderBy('id')->get())->values();
    }
}
