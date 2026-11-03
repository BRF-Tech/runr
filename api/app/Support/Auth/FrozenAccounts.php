<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * Frozen accounts (docs/security.md, "Shared accounts"): shared sign-ins, such as a public demo
 * login whose password is published, that nobody can change through their own account routes —
 * one visitor must not lock out the others by changing the password, turning on two-factor
 * authentication, revoking the sessions or deleting the account. They are listed by e-mail address
 * in auth.frozen_accounts (RUNR_FROZEN_ACCOUNTS: comma-separated, case-insensitive; empty: none).
 *
 * The route middleware `account.frozen` (App\Http\Middleware\EnsureAccountNotFrozen) refuses the
 * self-service writes; signing in and out and the administrators' actions on the account stay.
 */
final class FrozenAccounts
{
    /** @return list<string> the listed e-mail addresses, lower case */
    public static function emails(): array
    {
        $configured = config('auth.frozen_accounts', '');
        $entries = is_array($configured) ? $configured : explode(',', (string) $configured);

        $emails = [];
        foreach ($entries as $entry) {
            $email = Str::lower(trim((string) $entry));
            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        return array_keys($emails);
    }

    public static function includes(?Authenticatable $user): bool
    {
        $email = $user instanceof User ? Str::lower(trim((string) $user->email)) : '';
        if ($email === '') {
            return false;
        }

        $emails = self::emails();

        return $emails !== [] && in_array($email, $emails, true);
    }
}
