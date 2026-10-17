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
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json([
            'message' => __('api.account.profile_updated'),
            'user' => $user->fresh(),
        ]);
    }

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

    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json(['message' => __('api.account.avatar_removed')]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['message' => __('api.account.wrong_password')], 422);
        }

        $user->update(['password' => $request->input('password')]);

        // Whoever signed in elsewhere (with the old password) is signed out: the user's other
        // sign-in sessions end, the event signs their browsers out. API tokens stay.
        /** @var HasAbilities|null $current a stored token, or none (a session without a token) */
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        $revokedIds = $user->tokens()
            ->whereIn('name', LoginTokens::NAMES)
            ->when($currentId !== null, fn ($q) => $q->whereKeyNot($currentId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($revokedIds !== []) {
            $user->tokens()->whereKey($revokedIds)->delete();
            event(new PlatformEvent('session.revoked', ['token_ids' => $revokedIds]));
        }

        return response()->json(['message' => __('api.account.password_changed')]);
    }

    public function disableAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()->id;

        $user->update([
            'is_disabled' => true,
            'disabled_at' => now(),
        ]);

        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json(['message' => __('api.account.disabled')]);
    }

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
