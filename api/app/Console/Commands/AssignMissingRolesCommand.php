<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Console\Command;

/**
 * Single-tenant: users without an organization role get the default role, as a user created by
 * this version does (AccessManager::onboardSingleTenantUser) — docs/permissions.md → Upgrade.
 *
 * The upgrade migration gives every existing user a role before the new version starts, but the
 * previous version keeps serving until its pods are replaced and creates users without one
 * (sign-ups, Users page); they could sign in yet use nothing. The Helm bootstrap job runs this after
 * every install and upgrade. A user always has a role in this version (it can be changed, not
 * removed), so the command only fills that gap. Multi-tenant installs keep roles on the tenant
 * membership: nothing to do. Idempotent.
 */
class AssignMissingRolesCommand extends Command
{
    protected $signature = 'rbac:assign-missing';

    protected $description = 'Give single-tenant users without a role the default role (users the previous version created during an upgrade)';

    public function handle(AccessManager $access): int
    {
        if (config('tenancy.enabled')) {
            $this->line('Multi-tenant install: roles are kept on the tenant memberships, nothing to do.');

            return self::SUCCESS;
        }

        $org = new SingleTenantOrganization;
        $assigned = 0;

        User::orderBy('id')->each(function (User $user) use ($org, $access, &$assigned) {
            if ($org->memberRole($user->getKey()) !== null) {
                return;
            }
            $access->onboardSingleTenantUser($user);
            $role = $org->memberRole($user->getKey());
            $this->line("  {$user->username}: {$role}");
            $assigned++;
        });

        $this->info("Assigned a role to {$assigned} user(s) without one.");

        return self::SUCCESS;
    }
}
