<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Rules\UniqueIgnoringCase;
use App\Support\Auth\UserTokens;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            // alpha_dash: never "@" (an identifier with "@" signs in as an e-mail address first);
            // unique in any letter case, as the sign-in matches (LoginIdentifier)
            'username' => ['required', 'string', 'max:64', 'alpha_dash', new UniqueIgnoringCase('users', 'username')],
            'email' => ['required', 'email', new UniqueIgnoringCase('users', 'email')],
            'password' => 'required|string|min:8',
            'role' => 'sometimes|nullable|string|max:64',
        ]);

        $role = $data['role'] ?? null;
        $access = app(AccessManager::class);
        if ($role !== null) {
            $access->assertAssignableRole($role);
            $access->assertCanGrant($request->user(), $role);
        }
        unset($data['role']);

        $user = User::create($data);

        // The new user got the default role on creation; an explicit choice replaces it
        $org = app(Rbac::class)->organization();
        if ($role !== null && $org->exists() && $org->isMember($user->id)) {
            $org->setMemberRole($user->id, $role);
            $access->changed();
        }

        return response()->json(['user' => $this->formatUser($user, $org->memberRole($user->id))], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $query = User::orderBy('name');

        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('email', 'ilike', "%{$search}%")
            );
        }

        $roles = app(Rbac::class)->organization()->members()->pluck('role', 'id');

        return response()->json([
            'users' => $query->get()->map(fn ($u) => $this->formatUser($u, $roles[$u->id] ?? null)),
        ]);
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        app(AccessManager::class)->assertCanManageMember($request->user(), $user->id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', new UniqueIgnoringCase('users', 'email', $user->id)],
        ]);
        $user->update($data);

        return response()->json(['message' => __('api.user.updated')]);
    }

    public function destroy(string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => __('api.user.cannot_delete_self')], 422);
        }

        $access = app(AccessManager::class);
        $access->assertCanManageMember(auth()->user(), $user->id);
        $access->assertNotLastOwner($user->id);

        $this->deleteUser($user);

        return response()->json(['message' => __('api.user.deleted')]);
    }

    /** The user with every sign-in session and API token of theirs, in one transaction. */
    protected function deleteUser(User $user): void
    {
        UserTokens::deleteUser($user);
    }

    public function reset2FA(string $userId): JsonResponse
    {
        $user = User::findOrFail($userId);
        app(AccessManager::class)->assertCanManageMember(auth()->user(), $user->id);

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

    protected function formatUser(User $user, ?string $role = null): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $role,
            'two_factor_confirmed_at' => $user->two_factor_confirmed_at?->toISOString(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
