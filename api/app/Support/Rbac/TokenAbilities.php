<?php

namespace App\Support\Rbac;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * The abilities a new API token of $user may carry (docs/permissions.md, "API tokens"): '*' or
 * permissions the user holds organization-wide or on some project, implied permissions added,
 * 0.4.x scope names translated. Used by personal API tokens and by the tokens the MCP OAuth
 * server issues.
 */
final class TokenAbilities
{
    /**
     * @param  list<string>  $requested
     * @return list<string> '*' alone, or catalog permissions
     *
     * @throws ValidationException field "abilities"
     */
    public static function resolve(Authenticatable $user, array $requested): array
    {
        $rbac = app(Rbac::class);

        if (in_array(PermissionCatalog::WILDCARD, $requested, true)) {
            // A token used to create a token passes on at most its own scope
            $limit = $rbac->tokenAbilities($user);

            return $limit === null ? [PermissionCatalog::WILDCARD] : $limit;
        }

        $translated = PermissionCatalog::translateLegacyAbilities($requested);
        $abilities = PermissionCatalog::expand($translated);
        $unknown = array_diff($translated, PermissionCatalog::keys());
        $beyond = array_diff($abilities, $rbac->grantablePermissions($user));

        if ($unknown || $beyond || ! $abilities) {
            throw ValidationException::withMessages([
                'abilities' => __('rbac.token_abilities_invalid', ['abilities' => implode(', ', $unknown ?: $beyond) ?: '-']),
            ]);
        }

        return $abilities;
    }
}
