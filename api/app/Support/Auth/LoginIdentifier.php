<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Support\PlatformConnection;
use Illuminate\Http\Request;

/**
 * The account a password sign-in names (docs/security.md, "Password sign-in"). The console's
 * sign-in field, `runr login --with-password --username` and `login` of POST /api/auth/login take
 * the user name or the e-mail address of the account:
 *
 *   - white space around it is ignored;
 *   - with an "@" it is an e-mail address first, then a user name. A new user name cannot contain
 *     "@" (alpha_dash, ADMIN_USERNAME); an account that has one from an earlier release signs in
 *     with it as long as it is no other account's e-mail address;
 *   - without an "@" it is a user name;
 *   - letter case does not matter. Of accounts whose e-mail addresses (or user names) differ in case
 *     only — registration, the user management and the profile refuse such a value
 *     (App\Rules\UniqueIgnoringCase) — the one spelled exactly as typed signs in; typed in a third
 *     spelling, neither does.
 *
 * One identifier therefore names one account at most, and the password is checked against that
 * account only. The `auth` rate limiter counts the attempts per account (App\Support\ApiRateLimits),
 * so switching between the user name, the e-mail address and their spellings shares one budget.
 */
final class LoginIdentifier
{
    /** Longest identifier looked up (users.username and users.email are varchar(255)). */
    public const MAX_LENGTH = 255;

    /** Request attribute: the lookups of this request (the rate limiter's and the sign-in's). */
    private const MEMO = 'auth.login_identifier';

    /**
     * The account $login names, or null: no account, or several that differ in letter case only.
     * $connection: the users' connection (default: the platform's, App\Support\PlatformConnection).
     */
    public static function resolve(?string $login, ?string $connection = null): ?User
    {
        $login = trim((string) $login);
        if ($login === '' || mb_strlen($login) > self::MAX_LENGTH) {
            return null;
        }

        $connection ??= PlatformConnection::name();

        if (str_contains($login, '@')) {
            return self::match('email', $login, $connection) ?? self::match('username', $login, $connection);
        }

        return self::match('username', $login, $connection);
    }

    /** resolve() once per request: the rate limiter and the sign-in share the lookup. */
    public static function forRequest(Request $request, mixed $login): ?User
    {
        $login = is_string($login) ? trim($login) : '';
        $memo = $request->attributes->get(self::MEMO);
        $memo = is_array($memo) ? $memo : [];

        if (! array_key_exists($login, $memo)) {
            $memo[$login] = self::resolve($login);
            $request->attributes->set(self::MEMO, $memo);
        }

        return $memo[$login];
    }

    /**
     * The rate-limit key of a sign-in attempt: the account the identifier names, else the
     * identifier itself (normalize()).
     */
    public static function throttleKey(Request $request, mixed $login): string
    {
        $user = self::forRequest($request, $login);

        return $user ? 'account:'.$user->getKey() : self::normalize($login);
    }

    /** Trimmed, lower-cased and cut to MAX_LENGTH. */
    public static function normalize(mixed $login): string
    {
        return is_string($login) ? mb_substr(mb_strtolower(trim($login)), 0, self::MAX_LENGTH) : '';
    }

    /** The exact spelling, else the one account whose $column equals $value ignoring case. */
    private static function match(string $column, string $value, ?string $connection): ?User
    {
        return AccountLookup::find($column, $value, $connection);
    }
}
