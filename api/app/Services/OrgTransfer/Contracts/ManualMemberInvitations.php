<?php

namespace App\Services\OrgTransfer\Contracts;

use App\Models\User;

/** No invitations (single-tenant): the import lists the members as a manual step. */
final class ManualMemberInvitations implements MemberInvitations
{
    public function supported(): bool
    {
        return false;
    }

    public function invite(string $email, string $role, User $inviter): string
    {
        return 'manual';
    }
}
