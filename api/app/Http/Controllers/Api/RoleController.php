<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Built-in roles (read-only) and the organization's custom roles.
 *
 * @tags Roles & Permissions
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly AccessManager $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $manage = $this->rbac->allows($user, 'roles.manage');

        $roles = collect(PermissionCatalog::builtinRoles())->values()
            ->concat(Role::query()->orderBy('name')->get()->map(fn (Role $r) => $this->customArray($r)))
            ->map(fn (array $role) => $role + [
                'grantable' => $this->rbac->canGrantRole($user, $role['key']),
                'usage' => $manage ? $this->access->roleUsage($role['key']) : null,
            ]);

        return response()->json([
            'roles' => $roles->values(),
            'default_role' => $this->access->defaultRole(),
        ]);
    }

    public function show(string $role): JsonResponse
    {
        return response()->json(['role' => $this->find($role)]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Role::class);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'key' => 'sometimes|nullable|string|max:64|alpha_dash',
            'description' => 'nullable|string|max:500',
            'permissions' => 'present|array',
            'permissions.*' => 'string',
        ]);

        $role = $this->access->createRole($request->user(), $data);

        return response()->json(['role' => $this->customArray($role)], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        $model = $this->customOrFail($role);
        Gate::authorize('update', $model);
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string',
        ]);

        $model = $this->access->updateRole($request->user(), $model, $data);

        return response()->json(['role' => $this->customArray($model)]);
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        $model = $this->customOrFail($role);
        Gate::authorize('delete', $model);

        $this->access->deleteRole($request->user(), $model);

        return response()->json(['message' => __('rbac.role_deleted')]);
    }

    /** The role members get when nothing else is chosen (new users, invitations, SSO). */
    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate(['role' => 'required|string|max:64']);
        $this->access->setDefaultRole($request->user(), $data['role']);

        return response()->json(['default_role' => $this->access->defaultRole()]);
    }

    private function find(string $key): array
    {
        $builtin = PermissionCatalog::builtinRoles()[$key] ?? null;
        if ($builtin) {
            return $builtin;
        }

        return $this->customArray($this->customOrFail($key));
    }

    private function customOrFail(string $key): Role
    {
        if (PermissionCatalog::isBuiltinRole($key)) {
            abort(422, __('rbac.builtin_read_only'));
        }

        return Role::query()->where('key', $key)->firstOrFail();
    }

    private function customArray(Role $role): array
    {
        return [
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description ?? '',
            'permissions' => PermissionCatalog::expand($role->permissions ?? []),
            'builtin' => false,
        ];
    }
}
