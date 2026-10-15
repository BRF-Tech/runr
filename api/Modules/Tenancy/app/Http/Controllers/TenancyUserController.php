<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\UserController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;

class TenancyUserController extends UserController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        if ($tenant) {
            return $this->tenantIndex($tenant);
        }

        if ($user->is_admin) {
            return $this->centralIndex($request);
        }

        return response()->json(['users' => []]);
    }

    public function toggleAdmin(Request $request, string $userId): JsonResponse
    {
        if (! $request->user()->is_admin) {
            abort(403);
        }

        $user = User::on('central')->findOrFail($userId);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => __('api.tenant_user.cannot_change_admin')], 422);
        }

        $user->is_admin = ! $user->is_admin;
        $user->save();

        return response()->json([
            'message' => __('api.tenant_user.admin_updated'),
            'is_admin' => $user->is_admin,
        ]);
    }

    public function reset2FA(string $userId): JsonResponse
    {
        if (! request()->user()->is_admin) {
            abort(403);
        }

        $user = User::on('central')->findOrFail($userId);

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

    protected function tenantIndex(Tenant $tenant): JsonResponse
    {
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
            'context' => 'tenant',
        ]);
    }

    protected function centralIndex(Request $request): JsonResponse
    {
        $query = User::on('central')->orderBy('name');

        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
                ->orWhere('username', 'ilike', "%{$search}%")
            );
        }

        $users = $query->get()->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'username' => $u->username,
            'is_admin' => $u->is_admin ?? false,
            'two_factor_confirmed_at' => $u->two_factor_confirmed_at?->toISOString(),
            'tenants' => method_exists($u, 'tenants')
                ? $u->tenants->map(fn ($t) => [
                    'id' => $t->id, 'name' => $t->name, 'role' => $t->pivot->role,
                ])->values()
                : [],
            'created_at' => $u->created_at?->toISOString(),
        ]);

        return response()->json([
            'users' => $users,
            'context' => 'central',
        ]);
    }
}
