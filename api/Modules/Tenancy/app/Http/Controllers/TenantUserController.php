<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\User;
use App\Support\Rbac\AccessManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Modules\Tenancy\Mail\TenantInvitationMail;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;
use Modules\Tenancy\Models\TenantUser;
use Modules\Tenancy\Support\TenantTokens;

class TenantUserController extends Controller
{
    /**
     * The tenant in the path must be the tenant the request runs in (its roles decided the
     * route's permission: members.view / members.invite / roles.manage / members.remove — see
     * config/route_permissions.php). Platform admins may manage any tenant.
     */
    private function authorizeTenant(string $tenantId, bool $requireAdmin = false): void
    {
        $user = auth()->user();
        if ($user->is_admin) {
            return;
        }

        $current = tenancy()->initialized ? (string) tenancy()->tenant?->getTenantKey() : null;
        $member = TenantUser::where('tenant_id', $tenantId)->where('user_id', $user->id)->exists();

        if (! $member || $current !== $tenantId) {
            abort(403, __('api.tenant.not_member'));
        }
    }

    private function access(): AccessManager
    {
        return app(AccessManager::class);
    }

    public function index(string $tenantId): JsonResponse
    {
        $this->authorizeTenant($tenantId);
        $tenant = Tenant::findOrFail($tenantId);

        $users = $tenant->users()->withPivot('role', 'created_at')->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role,
                'type' => 'user',
                'two_factor_confirmed_at' => $u->two_factor_confirmed_at?->toISOString(),
            ]);

        $invitations = TenantInvitation::where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'email' => $inv->email,
                'role' => $inv->role,
                'type' => 'invitation',
                'expires_at' => $inv->expires_at->toISOString(),
            ]);

        return response()->json([
            'users' => $users,
            'invitations' => $invitations,
        ]);
    }

    public function searchEmail(Request $request, string $tenantId): JsonResponse
    {
        $this->authorizeTenant($tenantId, requireAdmin: true);
        $data = $request->validate(['email' => 'required|email']);
        $tenant = Tenant::findOrFail($tenantId);
        $email = $data['email'];

        $user = User::on('central')->where('email', $email)->first();

        if ($user) {
            $alreadyMember = TenantUser::where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)->exists();

            return response()->json([
                'found' => true,
                'already_member' => $alreadyMember,
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            ]);
        }

        $pendingInvite = TenantInvitation::where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        return response()->json([
            'found' => false,
            'already_member' => false,
            'pending_invitation' => $pendingInvite,
        ]);
    }

    public function store(Request $request, string $tenantId): JsonResponse
    {
        $this->authorizeTenant($tenantId, requireAdmin: true);
        $tenant = Tenant::findOrFail($tenantId);

        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'sometimes|nullable|string|max:64',
        ]);

        // Any role of the tenant except Owner, one the inviting member could grant themselves
        $role = $tenant->run(function () use ($request, $data) {
            $role = ($data['role'] ?? null) ?: $this->access()->defaultRole();
            $this->access()->assertAssignableRole($role);
            $this->access()->assertCanGrant($request->user(), $role);

            return $role;
        });
        $user = User::on('central')->where('email', $data['email'])->first();

        // Existing user → add directly
        if ($user) {
            if (TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
                return response()->json(['message' => __('api.tenant_user.already_in')], 422);
            }
            TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

            return response()->json(['message' => __('api.tenant_user.added'), 'action' => 'added'], 201);
        }

        // Non-existing user → create invitation
        $existing = TenantInvitation::where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            return response()->json(['message' => __('api.tenant_user.invitation_pending')], 422);
        }

        [$plainToken, $tokenHash] = TenantInvitation::newToken();
        $invitation = TenantInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $data['email'],
            'role' => $role,
            'token' => $tokenHash,
            'expires_at' => now()->addDays(7),
        ]);

        $acceptUrl = rtrim(config('tenancy.frontend_url', 'http://localhost:8061'), '/')."/invitations/{$plainToken}/accept";
        Mail::to($data['email'])->queue(new TenantInvitationMail($invitation, $acceptUrl));

        return response()->json(['message' => __('api.tenant_user.invitation_sent'), 'action' => 'invited'], 201);
    }

    public function update(Request $request, string $tenantId, string $userId): JsonResponse
    {
        $this->authorizeTenant($tenantId, requireAdmin: true);
        $data = $request->validate([
            'role' => 'required|string|max:64',
        ]);

        $tenantUser = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)->firstOrFail();

        if ((string) $tenantUser->user_id === (string) auth()->id()) {
            return response()->json(['message' => __('api.tenant_user.cannot_change_self')], 422);
        }

        // Built-in or custom role; Owner only by owners; the last owner keeps the role
        Tenant::findOrFail($tenantId)->run(
            fn () => $this->access()->setMemberRole($request->user(), $userId, $data['role'])
        );

        return response()->json(['message' => __('api.tenant_user.role_updated')]);
    }

    public function destroy(string $tenantId, string $userId): JsonResponse
    {
        $this->authorizeTenant($tenantId, requireAdmin: true);
        $tenantUser = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)->firstOrFail();

        if ((string) $tenantUser->user_id === (string) auth()->id()) {
            return response()->json(['message' => __('api.tenant_user.cannot_remove_self')], 422);
        }

        if ($tenantUser->role === 'owner' && TenantUser::where('tenant_id', $tenantId)->where('role', 'owner')->count() <= 1) {
            return response()->json(['message' => __('api.tenant_user.cannot_remove_owner')], 422);
        }

        // Members with a role the remover could not grant (an owner, for an admin) stay
        Tenant::findOrFail($tenantId)->run(fn () => $this->access()->assertCanManageMember(auth()->user(), $userId));

        // With the API tokens the member created in this tenant (they work in it only)
        TenantTokens::removeMember($tenantUser);

        return response()->json(['message' => __('api.tenant_user.removed')]);
    }

    public function cancelInvitation(string $tenantId, string $invitationId): JsonResponse
    {
        $this->authorizeTenant($tenantId, requireAdmin: true);
        $invitation = TenantInvitation::where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->whereNull('accepted_at')
            ->firstOrFail();

        $invitation->delete();

        return response()->json(['message' => __('api.tenant_user.invitation_cancelled')]);
    }

    public function reset2FA(string $tenantId, string $userId): JsonResponse
    {
        $this->authorizeTenant($tenantId, requireAdmin: true);

        $tenantUser = TenantUser::where('tenant_id', $tenantId)
            ->where('user_id', $userId)->firstOrFail();

        Tenant::findOrFail($tenantId)->run(fn () => $this->access()->assertCanManageMember(auth()->user(), $userId));

        $user = User::on('central')->findOrFail($tenantUser->user_id);

        if (! $user->two_factor_confirmed_at) {
            return response()->json(['message' => __('api.user.2fa_not_enabled')], 422);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        return response()->json(['message' => __('api.user.2fa_reset')]);
    }

    public function resendInvitation(string $tenantId, string $invitationId): JsonResponse
    {
        $this->authorizeTenant($tenantId, requireAdmin: true);
        $invitation = TenantInvitation::where('tenant_id', $tenantId)
            ->where('id', $invitationId)
            ->whereNull('accepted_at')
            ->firstOrFail();

        // A new token: the previous link stops working; only its hash is stored
        [$plainToken, $tokenHash] = TenantInvitation::newToken();
        $invitation->update(['expires_at' => now()->addDays(7), 'token' => $tokenHash]);

        $acceptUrl = rtrim(config('tenancy.frontend_url', 'http://localhost:8061'), '/')."/invitations/{$plainToken}/accept";
        Mail::to($invitation->email)->queue(new TenantInvitationMail($invitation, $acceptUrl));

        return response()->json(['message' => __('api.tenant_user.invitation_resent')]);
    }
}
