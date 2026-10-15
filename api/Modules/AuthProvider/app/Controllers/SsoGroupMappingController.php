<?php

namespace Modules\AuthProvider\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\Group;
use App\Support\Rbac\AccessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SsoGroupMapping;

/**
 * External IdP group → group of the organization and / or organization-wide role
 * (docs/permissions.md, "SSO group mapping"). Applied on every SSO sign-in.
 */
class SsoGroupMappingController extends Controller
{
    public function __construct(private readonly AccessManager $access) {}

    public function index(): JsonResponse
    {
        $mappings = SsoGroupMapping::query()->with(['group:id,name', 'provider:id,name,driver'])
            ->orderBy('external_group')->get()
            ->map(fn (SsoGroupMapping $m) => $this->present($m));

        return response()->json(['mappings' => $mappings]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $mapping = SsoGroupMapping::query()->create($data);

        return response()->json(['mapping' => $this->present($mapping->load(['group:id,name', 'provider:id,name,driver']))], 201);
    }

    public function update(Request $request, SsoGroupMapping $mapping): JsonResponse
    {
        if ($mapping->role) {
            $this->access->assertCanGrant($request->user(), $mapping->role);
        }
        $mapping->update($this->validated($request));

        return response()->json(['mapping' => $this->present($mapping->fresh(['group:id,name', 'provider:id,name,driver']))]);
    }

    public function destroy(Request $request, SsoGroupMapping $mapping): JsonResponse
    {
        if ($mapping->role) {
            $this->access->assertCanGrant($request->user(), $mapping->role);
        }
        $mapping->delete();

        return response()->json(['message' => __('rbac.mapping_deleted')]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'auth_provider_id' => 'nullable|string|max:26',
            'external_group' => 'required|string|max:500',
            'group_id' => 'nullable|string|max:26',
            'role' => 'nullable|string|max:64',
        ]);

        if (! empty($data['auth_provider_id']) && ! AuthProvider::query()->whereKey($data['auth_provider_id'])->exists()) {
            throw ValidationException::withMessages(['auth_provider_id' => __('rbac.unknown_provider')]);
        }
        if (! empty($data['group_id']) && ! Group::query()->whereKey($data['group_id'])->exists()) {
            throw ValidationException::withMessages(['group_id' => __('rbac.unknown_subject')]);
        }
        if (empty($data['group_id']) && empty($data['role'])) {
            throw ValidationException::withMessages(['group_id' => __('rbac.mapping_needs_target')]);
        }
        if (! empty($data['role'])) {
            // Signing in with the mapped group hands the role out: a role the editor could grant
            $this->access->assertAssignableRole($data['role']);
            $this->access->assertCanGrant($request->user(), $data['role']);
        }
        if (! empty($data['group_id'])) {
            $this->access->assertCanManageGroupMembers($request->user(), Group::query()->findOrFail($data['group_id']));
        }

        return [
            'auth_provider_id' => ($data['auth_provider_id'] ?? null) ?: null,
            'external_group' => trim($data['external_group']),
            'group_id' => ($data['group_id'] ?? null) ?: null,
            'role' => ($data['role'] ?? null) ?: null,
        ];
    }

    private function present(SsoGroupMapping $m): array
    {
        return [
            'id' => $m->id,
            'auth_provider_id' => $m->auth_provider_id,
            'provider' => $m->provider ? ['id' => $m->provider->id, 'name' => $m->provider->name, 'driver' => $m->provider->driver] : null,
            'external_group' => $m->external_group,
            'group_id' => $m->group_id,
            'group' => $m->group ? ['id' => $m->group->id, 'name' => $m->group->name] : null,
            'role' => $m->role,
        ];
    }
}
