<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\ApiTokenController;
use App\Support\Auth\LoginTokens;
use App\Support\Rbac\Rbac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens of a tenant: bound to the tenant they were created in (IdentifyTenant uses that
 * tenant for every request with the token), scoped like single-tenant tokens.
 */
class TenancyApiTokenController extends ApiTokenController
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;
        // tokens.manage: every token created in this tenant (with creator info); without it a
        // member lists their own only, to revoke them
        $all = app(Rbac::class)->allows($user, 'tokens.manage');

        $tokens = PersonalAccessToken::where('tenant_id', $tenant?->id)
            ->whereNotIn('name', LoginTokens::NAMES)
            ->when(! $all, fn ($q) => $q->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getKey()))
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->with('tokenable:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                // A token an MCP client holds (connected through OAuth): labelled in the list
                'mcp_client' => $token->getAttribute('oauth_client_id') !== null,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'expires_at' => $token->expires_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
                'created_by' => $token->tokenable ? ['name' => $token->tokenable->name, 'email' => $token->tokenable->email] : null,
                'mine' => (string) $token->tokenable_id === (string) $request->user()->getKey(),
            ]);

        return response()->json([
            'tokens' => $tokens,
            'available_abilities' => $this->availableAbilities($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$name, $abilities, $expiresAt] = $this->validatedToken($request);

        $token = $request->user()->createToken($name, $abilities, $expiresAt);
        $tenant = $request->attributes->get('tenant');

        if ($tenant) {
            $token->accessToken->forceFill(['tenant_id' => $tenant->id])->save();
        }

        return response()->json([
            'plain_token' => $token->plainTextToken,
            'token' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'mcp_client' => false,
                'abilities' => $token->accessToken->abilities,
                'expires_at' => $token->accessToken->expires_at?->toISOString(),
                'created_at' => $token->accessToken->created_at?->toISOString(),
                'mine' => true,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        // Never a sign-in session (they carry no tenant): the account's session routes revoke those
        $deleted = LoginTokens::apiTokensOf($request->user())
            ->whereKey($tokenId)
            ->where('tenant_id', $tenant?->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => __('api.token.not_found')], 404);
        }

        return response()->json(['message' => __('api.token.deleted')]);
    }
}
