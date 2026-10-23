<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\User;
use App\Support\Auth\AccountLookup;
use App\Support\Auth\LoginTokens;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Tenancy\Models\TenantInvitation;
use Modules\Tenancy\Models\TenantUser;

/**
 * Accepting an invitation to a tenant (docs/tenancy.md#members). The invited address names an
 * existing account in any letter case, as the sign-in does (AccountLookup); without one the
 * invitation creates it, with a user name of its own (AccountLookup::usernameFor).
 */
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
            'has_account' => AccountLookup::emailTaken($invitation->email, 'central'),
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

        $user = AccountLookup::byEmail($invitation->email, 'central');

        if (! $user) {
            // Accounts whose addresses differ from the invited one in letter case only: none of
            // them is it, and a third would sign in with neither (LoginIdentifier)
            if (AccountLookup::emailTaken($invitation->email, 'central')) {
                return response()->json(['message' => __('api.invitation.email_ambiguous'), 'code' => 'email_ambiguous'], 409);
            }

            $data = $request->validate([
                'name' => 'required|string|max:255',
                'password' => 'required|string|min:8',
            ]);

            $user = $this->createAccount($invitation->email, $data['name'], $data['password']);
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

        $authToken = LoginTokens::issue($user, 'invitation-accept')->plainTextToken;

        return response()->json([
            'message' => __('api.invitation.accepted'),
            'tenant' => $invitation->tenant->name,
            'token' => $authToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * The invited address's account, with a user name no account has: one that another account
     * took meanwhile (two acceptances at once) is chosen again.
     */
    private function createAccount(string $email, string $name, string $password): User
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return User::on('central')->create([
                    'name' => $name,
                    'email' => AccountLookup::normalizeEmail($email),
                    'username' => AccountLookup::usernameFor($email, 'central'),
                    'password' => Hash::make($password),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3 || AccountLookup::emailTaken($email, 'central')) {
                    throw $e;
                }
            }
        }
    }
}
