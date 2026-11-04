<?php

namespace App\Http\Controllers\Api;

use App\Events\PlatformEvent;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Support\Auth\LoginTokens;
use App\Support\Auth\UserTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class AccountController extends Controller
{
    /**
     * Get the signed-in user's account
     *
     * The signed-in user with `frozen`: a shared account whose own settings are locked (FrozenAccounts).
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->append('frozen'));
    }

    /** Update the signed-in user's profile */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => __('api.account.profile_updated'),
            'user' => $user->fresh(),
        ]);
    }

    /** Upload the signed-in user's avatar */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $ext = $request->file('avatar')->getClientOriginalExtension();
        $path = $request->file('avatar')->storeAs('avatars', "{$user->id}.{$ext}", 'public');

        $user->update(['avatar_path' => $path]);

        return response()->json([
            'message' => __('api.account.avatar_uploaded'),
            'avatar_url' => $user->fresh()->avatar_url,
        ]);
    }

    /** Remove the signed-in user's avatar */
    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json(['message' => __('api.account.avatar_removed')]);
    }

    /** Change the signed-in user's password */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => __('api.account.wrong_password')], 422);
        }

        $user->update(['password' => $request->input('password')]);

        // Whoever signed in elsewhere (with the old password) is signed out: the user's other
        // sign-in sessions end, the event signs their browsers out. API tokens stay.
        LoginTokens::endSessions($user, LoginTokens::currentId($user));

        return response()->json(['message' => __('api.account.password_changed')]);
    }

    /**
     * Disable the signed-in user's account
     *
     * Every other token of the account ends — sign-in sessions and API tokens alike — and the
     * current one is refused from then on: a disabled account keeps no working credential. The
     * browsers of the ended sessions are signed out at once (`session.revoked`).
     */
    public function disableAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = LoginTokens::currentId($user);

        $user->update([
            'is_disabled' => true,
            'disabled_at' => now(),
        ]);

        $endedIds = $user->tokens()
            ->when($currentTokenId !== null, fn ($q) => $q->whereKeyNot($currentTokenId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($endedIds !== []) {
            $user->tokens()->whereKey($endedIds)->delete();
            event(new PlatformEvent('session.revoked', ['token_ids' => $endedIds]));
        }

        return response()->json(['message' => __('api.account.disabled')]);
    }

    /** Delete the signed-in user's account */
    public function deleteAccount(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => __('api.account.wrong_password')], 422);
        }

        // Every sign-in session and API token of the account goes with it, in one transaction
        UserTokens::deleteUser($user);

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        return response()->json(['message' => __('api.account.deleted')]);
    }
}
