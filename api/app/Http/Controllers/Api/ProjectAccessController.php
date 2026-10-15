<?php

namespace App\Http\Controllers\Api;

use App\Models\Group;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Project access: a role for a user or group on this project only (only the role's
 * project-scoped permissions apply). Organization-wide roles cover every project already.
 *
 * @tags Roles & Permissions
 */
class ProjectAccessController extends Controller
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly AccessManager $access,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $members = $this->rbac->organization()->members()->keyBy(fn ($m) => (string) $m->id);
        $groups = Group::query()->pluck('name', 'id');

        $grants = RoleAssignment::query()
            ->where('project_id', $project->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (RoleAssignment $a) => [
                'id' => $a->id,
                'subject_type' => $a->subject_type,
                'subject_id' => $a->subject_id,
                'subject_name' => $a->subject_type === RoleAssignment::SUBJECT_USER
                    ? ($members[$a->subject_id]->name ?? null)
                    : ($groups[$a->subject_id] ?? null),
                'subject_email' => $a->subject_type === RoleAssignment::SUBJECT_USER ? ($members[$a->subject_id]->email ?? null) : null,
                'role' => $a->role,
                'grantable' => $this->rbac->canGrantRole($request->user(), $a->role, $project->id),
            ]);

        return response()->json([
            'grants' => $grants,
            'can_manage' => $this->rbac->allows($request->user(), 'projects.manage_access', $project),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in([RoleAssignment::SUBJECT_USER, RoleAssignment::SUBJECT_GROUP])],
            'subject_id' => 'required',
            'role' => 'required|string|max:64',
        ]);

        $grant = $this->access->grantProjectAccess(
            $request->user(), $project, $data['subject_type'], (string) $data['subject_id'], $data['role'],
        );

        return response()->json(['grant' => [
            'id' => $grant->id,
            'subject_type' => $grant->subject_type,
            'subject_id' => $grant->subject_id,
            'role' => $grant->role,
        ]], 201);
    }

    public function destroy(Request $request, Project $project, string $assignment): JsonResponse
    {
        $grant = RoleAssignment::query()->where('project_id', $project->id)->findOrFail($assignment);
        $this->access->revokeProjectAccess($request->user(), $grant);

        return response()->json(['message' => __('rbac.access_revoked')]);
    }
}
