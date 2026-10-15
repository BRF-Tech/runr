<?php

namespace App\Http\Controllers\Api;

use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Roles & Permissions
 */
class PermissionController extends Controller
{
    public function __construct(private readonly Rbac $rbac) {}

    /**
     * The permission catalog of this edition (permission matrix of the Roles page).
     */
    public function catalog(): JsonResponse
    {
        $permissions = collect(PermissionCatalog::permissions())->map(fn (array $meta, string $key) => [
            'key' => $key,
            'group' => $meta['group'],
            'scope' => $meta['scope'],
            'implies' => $meta['implies'] ?? [],
        ])->values();

        return response()->json([
            'edition' => PermissionCatalog::edition(),
            'permissions' => $permissions,
            'groups' => $permissions->pluck('group')->unique()->values(),
        ]);
    }

    /**
     * Effective permissions of the signed-in user in the current organization: organization-wide
     * ones, and the extra ones from project access per project — limited by the API token used.
     */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $this->rbac->organization();
        $token = $this->rbac->tokenAbilities($user);
        $platform = $this->rbac->isPlatformAdmin($user);
        $limit = fn (array $permissions) => array_values($token === null ? $permissions : array_intersect($permissions, $token));

        $orgPermissions = $limit($platform
            ? PermissionCatalog::keys()
            : PermissionCatalog::expand(array_keys($this->rbac->orgPermissions($user))));

        $projects = [];
        if (! $platform) {
            foreach ($this->rbac->projectPermissions($user) as $projectId => $permissions) {
                $extra = $limit(array_values(array_diff(array_keys($permissions), $orgPermissions)));
                if ($extra) {
                    $projects[$projectId] = $extra;
                }
            }
        }

        return response()->json([
            'edition' => PermissionCatalog::edition(),
            'platform_admin' => $platform,
            'organization' => $org->exists(),
            'role' => $org->memberRole($user->getAuthIdentifier()),
            'roles' => $this->rbac->orgRoles($user),
            'permissions' => $orgPermissions,
            'projects' => (object) $projects,
            'token_scoped' => $token !== null,
        ]);
    }
}
