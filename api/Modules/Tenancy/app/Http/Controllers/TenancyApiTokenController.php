<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\ApiTokenController;
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
        $currentTokenId = $request->user()->currentAccessToken()?->id;

        // All tokens in this tenant (with creator info)
        $tokens = PersonalAccessToken::where('tenant_id', $tenant?->id)
            ->where('name', '!=', 'api')
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->with('tokenable:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
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

        $deleted = $request->user()->tokens()
            ->where('id', $tokenId)
            ->where('tenant_id', $tenant?->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => __('api.token.not_found')], 404);
        }

        return response()->json(['message' => __('api.token.deleted')]);
    }
}
