<?php

namespace App\Support\Auth;

use App\Models\PendingSignup;
use App\Models\User;
use App\Support\PlatformConnection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Accounts by their e-mail address or user name the way the sign-in names them
 * (LoginIdentifier, docs/security.md "Password sign-in"): letter case does not matter, so
 * "Ada@Example.com" and "ada@example.com" are one address. Invitations, SSO sign-ins and the
 * organization import find an existing account through it, and refuse to create one that differs
 * from another only in case (App\Rules\UniqueIgnoringCase does the same for forms).
 *
 * $connection: the users' connection (default: the platform's, App\Support\PlatformConnection).
 */
final class AccountLookup
{
    /** Longest user name (the registration's and the user management's max:64). */
    public const USERNAME_MAX = 64;

    /** A new account's e-mail address as stored: trimmed and lower-cased, as a self-service signup stores it. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * The account of $value in $column: the one spelled exactly so, else the one account whose
     * value equals it ignoring case; null for none, or for several that differ in case only.
     */
    public static function find(string $column, ?string $value, ?string $connection = null): ?User
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $connection ??= PlatformConnection::name();

        $exact = User::on($connection)->where($column, $value)->first();
        if ($exact) {
            return $exact;
        }

        $folded = User::on($connection)->whereRaw("lower({$column}) = lower(?)", [$value])->limit(2)->get();

        return $folded->count() === 1 ? $folded->first() : null;
    }

    /** The account of an e-mail address in any letter case (find()). */
    public static function byEmail(?string $email, ?string $connection = null): ?User
    {
        return self::find('email', $email, $connection);
    }

    /** An account has $value in $column in any letter case (also when several do). */
    public static function taken(string $column, ?string $value, ?string $connection = null): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        return User::on($connection ?? PlatformConnection::name())->whereRaw("lower({$column}) = lower(?)", [$value])->exists();
    }

    public static function emailTaken(?string $email, ?string $connection = null): bool
    {
        return self::taken('email', $email, $connection);
    }

    /**
     * A user name for a new account of $email that no account has in any letter case and no
     * waiting signup holds: the address's local part in letters, digits, "-" and "_" (accents
     * transliterated, other characters become "-"), lower-cased, at most USERNAME_MAX characters;
     * "user" when nothing is left of it. A taken one gets "-2", "-3", … appended.
     */
    public static function usernameFor(string $email, ?string $connection = null): string
    {
        $local = Str::before(trim($email), '@');
        $base = strtolower(Str::ascii($local));
        $base = (string) preg_replace('/[^a-z0-9_-]+/', '-', $base);
        $base = trim((string) preg_replace('/-{2,}/', '-', $base), '-_');
        $base = substr($base, 0, self::USERNAME_MAX);
        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        for ($n = 2; self::usernameTaken($candidate, $connection); $n++) {
            $suffix = '-'.$n;
            $candidate = rtrim(substr($base, 0, self::USERNAME_MAX - strlen($suffix)), '-_').$suffix;
        }

        return $candidate;
    }

    private static function usernameTaken(string $username, ?string $connection): bool
    {
        if (self::taken('username', $username, $connection)) {
            return true;
        }

        // A signup that waits for its verification holds its user name (RegisterRequest)
        $pending = new PendingSignup;
        if (! Schema::connection($pending->getConnectionName())->hasTable($pending->getTable())) {
            return false;
        }

        return PendingSignup::query()->whereRaw('lower(username) = lower(?)', [$username])->where('expires_at', '>', now())->exists();
    }
}
