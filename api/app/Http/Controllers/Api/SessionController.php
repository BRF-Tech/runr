<?php

namespace App\Http\Controllers\Api;

use App\Events\PlatformEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()->id;

        $tokens = $user->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'device_name' => $token->device_name,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
                'is_current' => $token->id === $currentTokenId,
            ]);

        return response()->json(['sessions' => $tokens]);
    }

    public function destroy(Request $request, $tokenId): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()->id;

        if ((int) $tokenId === $currentTokenId) {
            return response()->json(['message' => __('api.account.cannot_revoke_current')], 422);
        }

        // Only the user's own session: the event on the platform channel signs out the browser
        // that holds the token, so it never names one that was not revoked here
        if ($user->tokens()->where('id', $tokenId)->delete() === 0) {
            abort(404);
        }

        event(new PlatformEvent('session.revoked', ['token_ids' => [(int) $tokenId]]));

        return response()->json(['message' => __('api.account.session_revoked')]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()->id;

        $revokedIds = $user->tokens()
            ->where('id', '!=', $currentTokenId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        if (! empty($revokedIds)) {
            event(new PlatformEvent('session.revoked', ['token_ids' => $revokedIds]));
        }

        return response()->json(['message' => __('api.account.all_sessions_revoked')]);
    }
}
