<?php

namespace App\Http\Controllers\Api;

use App\Models\Group;
use App\Models\RoleAssignment;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Groups (teams): members of a group get the group's roles — organization-wide (role) and on
 * the projects the group was given access to.
 *
 * @tags Roles & Permissions
 */
class GroupController extends Controller
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly AccessManager $access,
    ) {}

    public function index(): JsonResponse
    {
        $counts = DB::table('rbac_group_user')->select('group_id', DB::raw('count(*) as n'))->groupBy('group_id')->pluck('n', 'group_id');
        $roles = RoleAssignment::query()->where('subject_type', RoleAssignment::SUBJECT_GROUP)->whereNull('project_id')->pluck('role', 'subject_id');
        $grants = RoleAssignment::query()->where('subject_type', RoleAssignment::SUBJECT_GROUP)->whereNotNull('project_id')
            ->select('subject_id', DB::raw('count(*) as n'))->groupBy('subject_id')->pluck('n', 'subject_id');

        $groups = Group::query()->orderBy('name')->get()->map(fn (Group $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'description' => $g->description,
            'role' => $roles[$g->id] ?? null,
            'member_count' => (int) ($counts[$g->id] ?? 0),
            'project_grants' => (int) ($grants[$g->id] ?? 0),
        ]);

        return response()->json(['groups' => $groups]);
    }

    public function show(Group $group): JsonResponse
    {
        return response()->json(['group' => $this->detail($group)]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Group::class);
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:rbac_groups,name',
            'description' => 'nullable|string|max:500',
            'role' => 'sometimes|nullable|string|max:64',
        ]);

        $group = DB::transaction(function () use ($request, $data) {
            $group = Group::query()->create(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            if (! empty($data['role'])) {
                Gate::authorize('assignRole', $group);
                $this->access->setGroupRole($request->user(), $group, $data['role']);
            }

            return $group;
        });

        return response()->json(['group' => $this->detail($group)], 201);
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('update', $group);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('rbac_groups', 'name')->ignore($group->id)],
            'description' => 'nullable|string|max:500',
            'role' => 'sometimes|nullable|string|max:64',
        ]);

        DB::transaction(function () use ($request, $group, $data) {
            $group->update(array_intersect_key($data, array_flip(['name', 'description'])));
            if (array_key_exists('role', $data)) {
                Gate::authorize('assignRole', $group);
                $this->access->setGroupRole($request->user(), $group, $data['role'] ?: null);
            }
        });

        return response()->json(['group' => $this->detail($group->fresh())]);
    }

    public function destroy(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('delete', $group);
        $this->access->assertCanManageGroupMembers($request->user(), $group);

        $group->delete();
        $this->access->changed();

        return response()->json(['message' => __('rbac.group_deleted')]);
    }

    public function addMembers(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('update', $group);
        $data = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required',
        ]);
        $this->access->assertCanManageGroupMembers($request->user(), $group);
        $this->access->addGroupMembers($group, $data['user_ids']);

        return response()->json(['group' => $this->detail($group)]);
    }

    public function removeMember(Request $request, Group $group, string $user): JsonResponse
    {
        Gate::authorize('update', $group);
        $this->access->assertCanManageGroupMembers($request->user(), $group);
        $this->access->removeGroupMember($group, $user);

        return response()->json(['group' => $this->detail($group)]);
    }

    private function detail(Group $group): array
    {
        $memberships = DB::table('rbac_group_user')->where('group_id', $group->id)->get(['user_id', 'source'])->keyBy('user_id');
        $members = $this->rbac->organization()->members()
            ->filter(fn ($m) => $memberships->has($m->id))
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'source' => $memberships[$m->id]->source,
            ])->values();

        $projects = RoleAssignment::query()
            ->where('subject_type', RoleAssignment::SUBJECT_GROUP)
            ->where('subject_id', $group->id)
            ->whereNotNull('project_id')
            ->with('project:id,name')
            ->get()
            ->map(fn (RoleAssignment $a) => [
                'id' => $a->id,
                'project_id' => $a->project_id,
                'project_name' => $a->project?->name,
                'role' => $a->role,
            ]);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'role' => $group->orgRole(),
            'members' => $members,
            'projects' => $projects,
        ];
    }
}
