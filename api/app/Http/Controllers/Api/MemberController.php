<?php

namespace App\Http\Controllers\Api;

use App\Support\Rbac\AccessManager;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Members of the current organization with their own role and their groups (both editions).
 *
 * @tags Roles & Permissions
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly Rbac $rbac,
        private readonly AccessManager $access,
    ) {}

    public function index(): JsonResponse
    {
        $groups = DB::table('rbac_group_user')
            ->join('rbac_groups', 'rbac_groups.id', '=', 'rbac_group_user.group_id')
            ->get(['rbac_group_user.user_id', 'rbac_groups.id', 'rbac_groups.name', 'rbac_group_user.source'])
            ->groupBy('user_id');

        $members = $this->rbac->organization()->members()->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'email' => $m->email,
            'username' => $m->username,
            'role' => $m->role,
            'groups' => ($groups[$m->id] ?? collect())->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name, 'source' => $g->source,
            ])->values(),
        ])->values();

        return response()->json(['members' => $members]);
    }

    /** Change a member's own organization-wide role. */
    public function updateRole(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['role' => 'required|string|max:64']);

        $this->access->setMemberRole($request->user(), $user, $data['role']);

        return response()->json([
            'message' => __('rbac.member_role_updated'),
            'role' => $this->rbac->organization()->memberRole($user),
        ]);
    }
}
