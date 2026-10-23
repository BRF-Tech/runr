<?php

namespace App\Services\OrgTransfer\Contracts;

use App\Models\User;

/**
 * Invites the members listed in a package into the importing organization
 * (docs/org-export.md#members). Single-tenant installations have no invitations — the
 * members are a manual step there (ManualMemberInvitations); the Tenancy module binds an
 * implementation that adds existing accounts and e-mails an invitation to everyone else, as the
 * Members page does.
 */
interface MemberInvitations
{
    /** Whether this installation can invite at all. */
    public function supported(): bool;

    /**
     * Invite $email with $role on behalf of $inviter.
     *
     * @return string added (an existing account joined) | invited (an e-mail went out) | member (already in) | pending (an invitation is open)
     */
    public function invite(string $email, string $role, User $inviter): string;
}
