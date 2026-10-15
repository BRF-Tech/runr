<?php

namespace App\Http\Controllers\Api;

use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use App\Support\Rbac\TokenAbilities;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal API tokens. A token carries '*' (everything its owner may do) or a list of
 * permissions; either way it never exceeds what the owner may do at the time it is used.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()?->id;

        $tokens = $request->user()->tokens()
            ->where('name', '!=', 'api')
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'expires_at' => $token->expires_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
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

        return response()->json([
            'plain_token' => $token->plainTextToken,
            'token' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'abilities' => $token->accessToken->abilities,
                'expires_at' => $token->accessToken->expires_at?->toISOString(),
                'created_at' => $token->accessToken->created_at?->toISOString(),
            ],
        ], 201);
    }

    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return response()->json(['message' => __('api.token.not_found')], 404);
        }

        return response()->json(['message' => __('api.token.deleted')]);
    }

    /**
     * Permissions a new token of the signed-in user may carry (held organization-wide or on some
     * project), with their catalog group for the scope picker.
     */
    protected function availableAbilities(Request $request): array
    {
        $catalog = PermissionCatalog::permissions();

        return array_map(fn (string $permission) => [
            'key' => $permission,
            'group' => $catalog[$permission]['group'],
            'scope' => $catalog[$permission]['scope'],
        ], app(Rbac::class)->grantablePermissions($request->user()));
    }

    /**
     * Name, abilities ('*' or permissions the user holds; 0.4.x scope names are translated) and
     * the optional expiry of a new token.
     *
     * @return array{0: string, 1: list<string>, 2: ?Carbon}
     */
    protected function validatedToken(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_in:api'],
            'abilities' => 'sometimes|array',
            'abilities.*' => 'string|max:64',
            'expires_at' => 'sometimes|nullable|date|after:now',
        ]);

        $abilities = TokenAbilities::resolve($request->user(), $data['abilities'] ?? [PermissionCatalog::WILDCARD]);

        $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;

        return [$data['name'], $abilities, $expiresAt];
    }
}
