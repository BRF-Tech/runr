<?php

namespace App\Http\Controllers\Api;

use App\Events\PlatformEvent;
use App\Support\Auth\LoginTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in user's sign-in sessions (LoginTokens; docs/security.md, "Sign-in sessions and API
 * tokens"). API tokens are not sessions: these routes neither list nor revoke one — the API token
 * routes do (ApiTokenController).
 */
class SessionController extends Controller
{
    /**
     * List the signed-in user's sign-in sessions
     *
     * The browsers and devices signed in to the account, the current one marked `is_current`. API
     * tokens are not listed, nor are sessions that ended (past their expiry or the session
     * lifetime: the sign-in guard refuses them; the daily prune removes them).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = LoginTokens::currentId($user);

        $sessions = LoginTokens::sessionsOf($user)
            ->orderByDesc('last_used_at')
            ->get()
            ->reject(fn (PersonalAccessToken $token) => LoginTokens::expired($token))
            ->values()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'ip_address' => $token->ip_address,
                'user_agent' => $token->user_agent,
                'device_name' => $token->device_name,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
                'is_current' => (int) $token->id === $currentTokenId,
            ]);

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Revoke one of the signed-in user's other sign-in sessions
     *
     * The browser that held it is signed out (`session.revoked`). 404 for an API token, which only
     * the API token routes delete; 422 for the current session.
     */
    public function destroy(Request $request, $tokenId): JsonResponse
    {
        $user = $request->user();
        $id = (int) $tokenId;
        $sessions = fn () => LoginTokens::sessionsOf($user)->whereKey($id);

        if ($id === LoginTokens::currentId($user) && $sessions()->exists()) {
            return response()->json(['message' => __('api.account.cannot_revoke_current')], 422);
        }

        // Only a session of the user's own: the event on the platform channel signs out the
        // browser that holds the token, so it never names one that was not revoked here
        if ($sessions()->delete() === 0) {
            abort(404);
        }

        event(new PlatformEvent('session.revoked', ['token_ids' => [$id]]));

        return response()->json(['message' => __('api.account.session_revoked')]);
    }

    /**
     * Revoke the signed-in user's other sign-in sessions; API tokens stay
     *
     * Every sign-in session but the current one ends and its browser is signed out
     * (`session.revoked`). The user's API tokens (CI, the CLI, MCP clients) keep working.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();

        LoginTokens::endSessions($user, LoginTokens::currentId($user));

        return response()->json(['message' => __('api.account.all_sessions_revoked')]);
    }
}
