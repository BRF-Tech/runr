<?php

namespace App\Http\Controllers\Api;

use App\Events\ProjectOwnerChanged;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectStopService;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PUT /api/projects/{project}/owner {user_id} → {data: project, previous_owner_id}
 *
 * The owner pays the project's bills (docs/billing.md#who-pays) — also the bill of the month that
 * is still open when the transfer happens: an open bill belongs to whoever owns the project when
 * the month closes. It is the creator until transferred. The current owner hands the project over,
 * or someone with projects.manage_access on it (organization admins, platform administrators).
 *
 * The new owner must be a member of the project's organization who can see the project through
 * their own role or project access — a platform administrator's access to every organization does
 * not count (they are not made the payer of a tenant's project, nor shown to its members). On the
 * platform's own projects (multi-tenant, no organization) the owners are platform administrators.
 * The new owner is notified.
 */
class ProjectOwnershipController extends Controller
{
    public function __construct(private readonly Rbac $rbac) {}

    public function update(Request $request, Project $project): JsonResponse
    {
        $actor = $request->user();
        $previous = $project->payerId();

        if ((string) $actor->getAuthIdentifier() !== $previous && ! $this->rbac->allows($actor, 'projects.manage_access', $project)) {
            return response()->json(['message' => __('api.project.owner_not_allowed')], 403);
        }

        $data = $request->validate([
            'user_id' => ['required'],
        ]);

        // users.id is an integer: anything else names no user (and would be a query error on PostgreSQL)
        $owner = is_scalar($data['user_id']) && ctype_digit((string) $data['user_id'])
            ? User::query()->find((int) $data['user_id'])
            : null;
        if (! $owner || ! $this->canOwn($owner, $project)) {
            throw ValidationException::withMessages(['user_id' => __('api.project.owner_cannot_see_project')]);
        }

        $ownerId = (string) $owner->getKey();
        if ($ownerId !== $previous) {
            $project->forceFill(['owner_id' => $ownerId])->save();
            event(new ProjectOwnerChanged($project, $previous, $ownerId, (string) $actor->getAuthIdentifier()));

            app(ProjectStopService::class)->notifyUser($ownerId, $project,
                __('api.project.owner_assigned_title', ['name' => $project->name]),
                __('api.project.owner_assigned_body', ['name' => $project->name]),
                'info', 'project.owner_assigned');
        }

        $project->load(['knativeRegion', 'latestVersion', 'latestDeployment.version', 'creator:id,name', 'owner:id,name,username']);

        return response()->json([
            'data' => (new ProjectResource($project))->resolve($request),
            'previous_owner_id' => $previous,
        ]);
    }

    /**
     * Whether $user may own the project: a member of the current organization holding
     * projects.view on the project through their own role, their groups or project access — not
     * through the platform administrator flag. In the multi-tenant central context (the platform's
     * projects) only platform administrators act, so only they can own. The permissions are those
     * of the user, not of the request's API token (a user loaded here has none).
     */
    private function canOwn(User $user, Project $project): bool
    {
        $this->rbac->flush();
        $organization = $this->rbac->organization();

        if (! $organization->exists()) {
            return $this->rbac->isPlatformAdmin($user);
        }

        if (! $organization->isMember($user->getKey())) {
            return false;
        }

        $permission = 'projects.view';
        if (isset($this->rbac->orgPermissions($user)[$permission])) {
            return true;
        }

        return PermissionCatalog::isProjectScoped($permission)
            && isset($this->rbac->projectPermissions($user)[(string) $project->getKey()][$permission]);
    }
}
