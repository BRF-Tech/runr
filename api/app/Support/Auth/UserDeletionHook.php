<?php

namespace App\Support\Auth;

use App\Models\User;
use Closure;

/**
 * Something that refers to a user and has to go when the account is deleted
 * (UserTokens::deleteUser): the Tenancy module's copies of the user in the organizations they
 * belonged to. Registered by tagging the class with HOOKS in the container.
 */
interface UserDeletionHook
{
    public const HOOKS = 'runr.user-deletion-hooks';

    /**
     * Called before $user and their tokens are deleted, while what refers to the account still
     * exists; the closure returned runs once the deletion is committed.
     */
    public function deleting(User $user): ?Closure;
}
