<?php

namespace App\Support\Auth;

/**
 * Further housekeeping of `php artisan tokens:prune` (LoginTokens::prune): the Tenancy module
 * removes tokens its organizations refuse for good and copies of deleted users. Registered by
 * tagging the class with PRUNERS in the container.
 */
interface AccountPruner
{
    public const PRUNERS = 'runr.account-pruners';

    /** What prune() removes, as the command's output names it ("token(s) of …"). */
    public function label(): string;

    /** Removes it; returns how many. */
    public function prune(): int;
}
