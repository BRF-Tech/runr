<?php

namespace Modules\AuthProvider\Services;

use Laravel\Socialite\Contracts\User;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\Concerns\OAuthCallbackTrait;
use Modules\AuthProvider\Services\Concerns\OAuthRedirectTrait;

class OAuthService
{
    use OAuthCallbackTrait;
    use OAuthRedirectTrait;

    public function __construct(
        protected ProviderConfigService $configService
    ) {}

    public function getRedirectUrl(string $driver, ?string $tenantSlug = null): string
    {
        $state = ['context' => $tenantSlug ? 'tenant' : 'central'];

        if ($tenantSlug) {
            $state['tenant_slug'] = $tenantSlug;
        }

        return $this->buildRedirectUrl($driver, $state);
    }

    public function handleProviderCallback(string $driver, string $encryptedState): array
    {
        $state = $this->decryptState($encryptedState);

        if (! $state) {
            return ['error' => __('api.oauth.invalid_state')];
        }

        // If tenant context, initialize tenancy
        if (($state['context'] ?? '') === 'tenant' && ! empty($state['tenant_slug'])) {
            $this->initializeTenantContext($state['tenant_slug']);
        }

        return $this->handleCallback($driver, $state);
    }

    /**
     * Resolve (link / provision) the user behind an externally authenticated subject and issue an
     * API token — the path the OAuth callback takes. Also used by the SAML ACS (driver `saml`), so
     * SAML users get the same account linking, auto-register rule and tenant scoping.
     *
     * @param  User  $externalUser
     */
    public function resolveExternalUser(AuthProvider $provider, string $driver, $externalUser): array
    {
        return $this->resolveUser($provider, $driver, $externalUser);
    }

    /** @return bool whether a tenant with this slug was found and tenancy initialized */
    public function initializeTenantContext(string $slug): bool
    {
        // Only if Tenancy module is active
        if (! function_exists('tenancy')) {
            return false;
        }

        $tenantClass = 'Modules\\Tenancy\\Models\\Tenant';
        if (! class_exists($tenantClass)) {
            return false;
        }

        $tenant = $tenantClass::where('slug', $slug)->first();
        if ($tenant) {
            tenancy()->initialize($tenant);

            return true;
        }

        return false;
    }
}
