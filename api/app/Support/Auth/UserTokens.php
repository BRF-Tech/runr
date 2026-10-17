<?php

namespace App\Support\Auth;

use App\Mcp\Models\McpSession;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

/**
 * The personal access tokens of a user — sign-in sessions and API tokens (docs/security.md,
 * "Sign-in sessions and API tokens") — go with the user: deleting an account, by its owner or by
 * an administrator, deletes every token of it in the same transaction, and then the MCP server
 * sessions those tokens opened and what the deletion hooks (UserDeletionHook) clean up.
 */
final class UserTokens
{
    /** Deletes $user and every token of theirs in one transaction, then their MCP sessions. */
    public static function deleteUser(User $user): void
    {
        // Asked before the delete: what the account still refers to (memberships) goes with it
        $after = [];
        foreach (app()->tagged(UserDeletionHook::HOOKS) as $hook) {
            if ($hook instanceof UserDeletionHook && ($run = $hook->deleting($user)) instanceof Closure) {
                $after[] = $run;
            }
        }

        $user->getConnection()->transaction(function () use ($user) {
            self::query($user->getKey(), $user->getConnectionName())->delete();
            $user->delete();
        });

        // Bound to the deleted tokens (they answer nothing without them); central in both editions
        McpSession::query()->where('user_id', (string) $user->getKey())->delete();

        foreach ($after as $run) {
            $run();
        }
    }

    /**
     * Every token of the user $userId on $connection (null: the default one).
     *
     * @return Builder<PersonalAccessToken>
     */
    public static function query(int|string $userId, ?string $connection = null): Builder
    {
        return self::ofUsers($connection)->where('tokenable_id', $userId);
    }

    /**
     * The tokens of users on $connection (null: the default one). A token's tokenable_type is the
     * class of the user model that created it: App\Models\User or, in the multi-tenant edition,
     * one of its subclasses.
     *
     * @return Builder<PersonalAccessToken>
     */
    public static function ofUsers(?string $connection = null): Builder
    {
        $model = self::model();

        return $model::on($connection)->whereIn('tokenable_type', self::userTypes($model::on($connection)));
    }

    /**
     * Tokens whose user no longer exists, left behind by user deletions of earlier releases
     * (tokens:prune removes them).
     *
     * @return Builder<PersonalAccessToken>
     */
    public static function orphans(): Builder
    {
        $tokens = self::ofUsers();
        $tokenTable = $tokens->getModel()->getTable();
        $users = (new User)->getTable();

        return $tokens->whereNotExists(fn ($q) => $q->selectRaw('1')->from($users)->whereColumn("{$users}.id", "{$tokenTable}.tokenable_id"));
    }

    /** @return class-string<PersonalAccessToken> */
    private static function model(): string
    {
        return Sanctum::$personalAccessTokenModel;
    }

    /**
     * The tokenable_type values among $tokens that name the user model or a subclass of it.
     *
     * @param  Builder<PersonalAccessToken>  $tokens
     * @return list<string>
     */
    private static function userTypes(Builder $tokens): array
    {
        return array_values(array_filter(
            $tokens->distinct()->pluck('tokenable_type')->all(),
            fn ($type) => is_string($type) && is_a(Relation::getMorphedModel($type) ?? $type, User::class, true),
        ));
    }
}
