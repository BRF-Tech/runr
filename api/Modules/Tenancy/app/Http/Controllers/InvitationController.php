<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\TenantInvitation;
use Modules\Tenancy\Models\TenantUser;

class InvitationController extends Controller
{
    /**
     * Preview invitation details (public, no auth).
     */
    public function show(string $token): JsonResponse
    {
        // Stored as a hash (TenantInvitation::findByPlainToken)
        $invitation = TenantInvitation::findByPlainToken($token);

        if (! $invitation) {
            return response()->json(['message' => __('api.invitation.not_found')], 404);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => __('api.invitation.expired')], 410);
        }

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'tenant' => $invitation->tenant->name,
            'has_account' => User::on('central')->where('email', $invitation->email)->exists(),
        ]);
    }

    /**
     * Accept invitation (public, token-based auth).
     * If user doesn't exist: requires name + password to register.
     * If user exists: requires password to verify identity.
     */
    public function accept(string $token, Request $request): JsonResponse
    {
        $invitation = TenantInvitation::findByPlainToken($token) ?? abort(404);

        if ($invitation->isExpired()) {
            return response()->json(['message' => __('api.invitation.expired')], 410);
        }

        $user = User::on('central')->where('email', $invitation->email)->first();

        if (! $user) {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8',
            ]);

            $user = User::on('central')->create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'username' => Str::before($invitation->email, '@'),
                'password' => Hash::make($data['password']),
            ]);
        } else {
            $data = $request->validate([
                'password' => 'required|string',
            ]);

            if (! Hash::check($data['password'], $user->password)) {
                return response()->json(['message' => __('api.invitation.invalid_password')], 422);
            }
        }

        $exists = TenantUser::where('tenant_id', $invitation->tenant_id)
            ->where('user_id', $user->id)->exists();

        if (! $exists) {
            TenantUser::create([
                'tenant_id' => $invitation->tenant_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);
        }

        $invitation->update(['accepted_at' => now()]);

        $authToken = $user->createToken('invitation-accept')->plainTextToken;

        return response()->json([
            'message' => __('api.invitation.accepted'),
            'tenant' => $invitation->tenant->name,
            'token' => $authToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
