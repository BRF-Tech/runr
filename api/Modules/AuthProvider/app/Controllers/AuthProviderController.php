<?php

namespace Modules\AuthProvider\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Support\Rbac\AccessManager;
use App\Support\Secrets\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\ProviderConfigService;
use Modules\AuthProvider\Services\Saml\SamlConfigValidator;
use Modules\AuthProvider\Services\Saml\SamlService;

class AuthProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = AuthProvider::ordered()->get();

        return response()->json($providers->map(fn (AuthProvider $p) => $this->present($p))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|string|in:'.implode(',', config('authprovider.drivers')),
            'client_id' => 'required_unless:driver,'.AuthProvider::DRIVER_SAML.'|nullable|string',
            'client_secret' => 'required_unless:driver,'.AuthProvider::DRIVER_SAML.'|nullable|string',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string',
            'oidc_discovery_url' => 'nullable|required_if:driver,oidc|url',
            'oidc_issuer' => 'nullable|string',
            'google_hosted_domain' => 'nullable|string|max:255',
            'microsoft_tenant' => 'nullable|string|max:255',
            'gitlab_instance_uri' => 'nullable|url|max:500',
            'is_enabled' => 'boolean',
            'auto_register' => 'boolean',
            'display_order' => 'integer|min:0',
            'groups_attribute' => 'nullable|string|max:255',
            'default_role' => 'nullable|string|max:64',
        ]);
        $this->assertDefaultRole($request, $data);

        if ($data['driver'] === AuthProvider::DRIVER_SAML) {
            $data = array_merge($data, app(SamlConfigValidator::class)->validate($request->all()), [
                // SAML has no OAuth client; the columns stay non-null (stored encrypted, empty)
                'client_id' => '',
                'client_secret' => '',
                'scopes' => null,
            ]);
        }

        $provider = AuthProvider::create($data);

        return response()->json($this->present($provider), 201);
    }

    public function update(Request $request, AuthProvider $authProvider): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'client_id' => 'sometimes|string',
            'client_secret' => 'sometimes|string',
            'scopes' => 'nullable|array',
            'scopes.*' => 'string',
            // An OIDC provider cannot lose its discovery URL
            'oidc_discovery_url' => $authProvider->driver === 'oidc' ? 'sometimes|required|url' : 'nullable|url',
            'oidc_issuer' => 'nullable|string',
            'google_hosted_domain' => 'nullable|string|max:255',
            'microsoft_tenant' => 'nullable|string|max:255',
            'gitlab_instance_uri' => 'nullable|url|max:500',
            'is_enabled' => 'boolean',
            'auto_register' => 'boolean',
            'display_order' => 'integer|min:0',
            'groups_attribute' => 'nullable|string|max:255',
            'default_role' => 'nullable|string|max:64',
        ]);
        $this->assertDefaultRole($request, $data);

        if ($authProvider->isSaml()) {
            $data = array_merge(
                array_diff_key($data, array_flip(['client_id', 'client_secret', 'scopes'])),
                app(SamlConfigValidator::class)->validate($request->all(), $authProvider),
            );
        }

        $authProvider->update($data);

        return response()->json($this->present($authProvider));
    }

    public function destroy(AuthProvider $authProvider): JsonResponse
    {
        $authProvider->delete();

        return response()->json(['message' => __('api.oauth.provider_deleted')]);
    }

    public function toggle(AuthProvider $authProvider): JsonResponse
    {
        $authProvider->update(['is_enabled' => ! $authProvider->is_enabled]);

        return response()->json($this->present($authProvider));
    }

    /**
     * Role of users this provider provisions just in time (null = the organization's default
     * role): any role but Owner, one the signed-in user could grant.
     */
    protected function assertDefaultRole(Request $request, array $data): void
    {
        if (! empty($data['default_role'])) {
            $access = app(AccessManager::class);
            $access->assertAssignableRole($data['default_role']);
            $access->assertCanGrant($request->user(), $data['default_role']);
        }
    }

    /**
     * Provider as JSON. SAML providers also carry the SP endpoints to register at the IdP
     * (`saml_sp`) and whether an SP signing key is stored — the key itself is never returned.
     */
    protected function present(AuthProvider $provider): array
    {
        $data = $provider->toArray();
        // The client secret is write-only (hidden attribute): whether one is stored, and its last characters
        $secret = (string) $provider->getAttribute('client_secret');
        $data['has_client_secret'] = $secret !== '';
        $data['client_secret_hint'] = SecretRedactor::hint($secret);

        if ($provider->isSaml()) {
            $data['has_saml_sp_private_key'] = $provider->hasSamlSpPrivateKey();
            $data['saml_sp'] = app(SamlService::class)->spUrls($provider);
        } else {
            // Redirect URL to register with the OAuth client / at the OIDC identity provider
            $data['callback_url'] = app(ProviderConfigService::class)->callbackUrl($provider->driver);
        }

        return $data;
    }
}
