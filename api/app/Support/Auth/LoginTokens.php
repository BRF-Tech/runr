<?php

namespace App\Support\Auth;

use App\Events\PlatformEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * Sign-in sessions (docs/security.md, "Sign-in sessions and API tokens"): the API tokens a
 * sign-in issues — a password sign-in of the console or the CLI, an SSO sign-in, a self-service
 * signup, an accepted invitation. Their names tell them apart from the API tokens users create
 * (those cannot take these names since 0.5.5; a token a user named so before counts as a session).
 * The account's session routes list and revoke sign-in sessions only, the API token routes API
 * tokens only (sessionsOf, apiTokensOf).
 *
 * A session ends auth.login_token_ttl_days after the sign-in (RUNR_LOGIN_TOKEN_TTL_DAYS): a new
 * one carries that expires_at, and the age rule ends the sessions signed in before they carried
 * one or before the lifetime was lowered. API tokens keep the expiry they were created with.
 */
final class LoginTokens
{
    /** Token names of sign-in sessions */
    public const NAMES = ['api', 'invitation-accept'];

    /** An API token past its expiry is still listed (as expired) this long before it is pruned */
    public const EXPIRED_TOKEN_RETENTION_DAYS = 30;

    /** 0: sessions last until they are signed out or revoked */
    public static function ttlDays(): int
    {
        return max(0, (int) config('auth.login_token_ttl_days', 30));
    }

    /** A new sign-in session of $user. */
    public static function issue(User $user, string $name = 'api'): NewAccessToken
    {
        $days = self::ttlDays();

        return $user->createToken($name, ['*'], $days > 0 ? now()->addDays($days) : null);
    }

    public static function isLoginToken(PersonalAccessToken $token): bool
    {
        return in_array($token->name, self::NAMES, true);
    }

    /**
     * The sign-in sessions of $user: what the account's Active Sessions list, revoke and revoke
     * all others, and what a password change ends. The user's other tokens are API tokens.
     *
     * @return MorphMany<PersonalAccessToken, User>
     */
    public static function sessionsOf(User $user): MorphMany
    {
        return $user->tokens()->whereIn('name', self::NAMES);
    }

    /**
     * The API tokens of $user (personal tokens, the CLI's, MCP clients'): what the API token
     * routes list and delete. Never a sign-in session.
     *
     * @return MorphMany<PersonalAccessToken, User>
     */
    public static function apiTokensOf(User $user): MorphMany
    {
        return $user->tokens()->whereNotIn('name', self::NAMES);
    }

    /** The stored token the request of $user came with; null without one (a cookie session). */
    public static function currentId(User $user): ?int
    {
        /** @var HasAbilities|null $current */
        $current = $user->currentAccessToken();

        return $current instanceof PersonalAccessToken ? (int) $current->getKey() : null;
    }

    /**
     * Ends the sign-in sessions of $user except $keep (the current token): the session.revoked
     * event signs out the browsers that held them. API tokens stay.
     *
     * @return list<int> the ids of the ended sessions
     */
    public static function endSessions(User $user, ?int $keep = null): array
    {
        $ids = self::sessionsOf($user)
            ->when($keep !== null, fn ($q) => $q->whereKeyNot($keep))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($ids !== []) {
            $user->tokens()->whereKey($ids)->delete();
            event(new PlatformEvent('session.revoked', ['token_ids' => $ids]));
        }

        return $ids;
    }

    /** A sign-in session older than the lifetime, whatever its expires_at says. */
    public static function outlived(PersonalAccessToken $token): bool
    {
        $days = self::ttlDays();

        return $days > 0
            && self::isLoginToken($token)
            && $token->created_at !== null
            && $token->created_at->lte(now()->subDays($days));
    }

    /**
     * Sanctum's own checks (expires_at, sanctum.expiration) and the sign-in session lifetime, for
     * the places that authenticate a token outside Sanctum's guard (git remote, hosted MCP servers).
     */
    public static function expired(PersonalAccessToken $token): bool
    {
        if ($token->expires_at && $token->expires_at->isPast()) {
            return true;
        }

        $minutes = config('sanctum.expiration');
        if ($minutes && $token->created_at && $token->created_at->lte(now()->subMinutes((int) $minutes))) {
            return true;
        }

        return self::outlived($token);
    }

    /**
     * Removes the sign-in sessions that ended, the API tokens that expired more than
     * EXPIRED_TOKEN_RETENTION_DAYS ago, the tokens of users that no longer exist, and what the
     * registered AccountPruners remove (more: their counts by label).
     *
     * @return array{sessions: int, tokens: int, orphans: int, more: array<string, int>}
     */
    public static function prune(): array
    {
        $days = self::ttlDays();

        $sessions = self::query()
            ->whereIn('name', self::NAMES)
            ->where(function (Builder $q) use ($days) {
                $q->where('expires_at', '<', now());
                if ($days > 0) {
                    $q->orWhere('created_at', '<=', now()->subDays($days));
                }
            })
            ->delete();

        $tokens = self::query()
            ->whereNotIn('name', self::NAMES)
            ->where('expires_at', '<', now()->subDays(self::EXPIRED_TOKEN_RETENTION_DAYS))
            ->delete();

        $orphans = UserTokens::orphans()->delete();

        $more = [];
        foreach (app()->tagged(AccountPruner::PRUNERS) as $pruner) {
            if ($pruner instanceof AccountPruner) {
                $more[$pruner->label()] = $pruner->prune();
            }
        }

        return ['sessions' => $sessions, 'tokens' => $tokens, 'orphans' => $orphans, 'more' => $more];
    }

    /** @return Builder<PersonalAccessToken> */
    private static function query(): Builder
    {
        /** @var class-string<PersonalAccessToken> $model */
        $model = Sanctum::$personalAccessTokenModel;

        return $model::query();
    }
}
