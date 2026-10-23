<?php

namespace Modules\Tenancy\Services\OrgTransfer;

use App\Models\User;
use App\Services\OrgTransfer\Contracts\MemberInvitations;
use App\Support\Auth\AccountLookup;
use App\Support\Rbac\AccessManager;
use Illuminate\Support\Facades\Mail;
use Modules\Tenancy\Mail\TenantInvitationMail;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;
use Modules\Tenancy\Models\TenantUser;

/**
 * The package's members invited into the importing tenant (docs/org-export.md#members) as the
 * Members page does it (TenantUserController::store()): an existing account joins with the
 * role, everyone else gets an invitation e-mail. Roles the inviter could not grant, and Owner,
 * are refused by the access manager.
 */
class TenantMemberInvitations implements MemberInvitations
{
    public function __construct(private AccessManager $access) {}

    public function supported(): bool
    {
        return tenancy()->tenant instanceof Tenant;
    }

    public function invite(string $email, string $role, User $inviter): string
    {
        $tenant = tenancy()->tenant;
        if (! $tenant instanceof Tenant) {
            return 'manual';
        }

        $this->access->assertAssignableRole($role);
        $this->access->assertCanGrant($inviter, $role);

        // An address names its account in any letter case, as the sign-in does
        $user = AccountLookup::byEmail($email, 'central');
        if ($user) {
            if (TenantUser::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
                return 'member';
            }
            TenantUser::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

            return 'added';
        }

        // Accounts that differ from it in letter case only: none is it (an administrator decides)
        if (AccountLookup::emailTaken($email, 'central')) {
            return 'manual';
        }

        $email = AccountLookup::normalizeEmail($email);
        $open = TenantInvitation::query()->where('tenant_id', $tenant->id)->whereRaw('lower(email) = ?', [$email])
            ->whereNull('accepted_at')->where('expires_at', '>', now())->exists();
        if ($open) {
            return 'pending';
        }

        [$plainToken, $tokenHash] = TenantInvitation::newToken();
        $invitation = TenantInvitation::query()->create([
            'tenant_id' => $tenant->id,
            'email' => $email,
            'role' => $role,
            'token' => $tokenHash,
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = rtrim((string) config('tenancy.frontend_url', 'http://localhost:8061'), '/')."/invitations/{$plainToken}/accept";
        Mail::to($email)->queue(new TenantInvitationMail($invitation, $acceptUrl));

        return 'invited';
    }
}
