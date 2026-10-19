<?php

namespace Modules\AuthProvider\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Services\ProviderConfigService;

/** The signed-in user's linked external accounts (Account settings → Linked accounts). */
class SocialAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = SocialAccount::where('user_id', $request->user()->id)
            ->orderBy('created_at')
            ->get();

        $names = AuthProvider::query()->pluck('name', 'id');

        return response()->json($accounts->map(fn (SocialAccount $a) => self::presentAccount($a, $names->all()))->values());
    }

    /** Providers the signed-in user can link (enabled OAuth / OIDC providers of the organization). */
    public function providers(ProviderConfigService $config): JsonResponse
    {
        return response()->json($config->linkable()->map(fn (AuthProvider $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'driver' => $p->driver,
        ])->values());
    }

    public function destroy(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        if ((string) $socialAccount->user_id !== (string) $request->user()->id) {
            return response()->json(['message' => __('api.oauth.unauthorized')], 403);
        }

        $socialAccount->delete();

        return response()->json(['message' => __('api.oauth.account_unlinked')]);
    }

    /**
     * A linked account as JSON. OIDC and SAML subjects carry the provider ID they belong to
     * (`<provider id>:<subject>`): `auth_provider_id` / `provider_name` name that provider.
     *
     * @param  array<string, string>|null  $providerNames  provider ID → name
     */
    public static function presentAccount(SocialAccount $account, ?array $providerNames = null): array
    {
        $providerId = null;
        if (in_array($account->provider, ['oidc', AuthProvider::DRIVER_SAML], true) && str_contains($account->provider_user_id, ':')) {
            $providerId = strstr($account->provider_user_id, ':', true) ?: null;
        }

        $providerName = null;
        if ($providerId !== null) {
            $providerName = $providerNames !== null
                ? ($providerNames[$providerId] ?? null)
                : AuthProvider::query()->whereKey($providerId)->value('name');
        }

        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'provider_user_id' => $account->provider_user_id,
            'auth_provider_id' => $providerName !== null ? $providerId : null,
            'provider_name' => $providerName,
            'email' => $account->email,
            'name' => $account->name,
            'avatar_url' => $account->avatar_url,
            'created_at' => $account->created_at?->toJSON(),
        ];
    }
}
