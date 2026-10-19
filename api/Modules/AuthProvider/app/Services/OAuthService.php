<?php

namespace Modules\AuthProvider\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\Concerns\OAuthCallbackTrait;
use Modules\AuthProvider\Services\Concerns\OAuthLinkTrait;
use Modules\AuthProvider\Services\Concerns\OAuthRedirectTrait;

/**
 * OAuth 2.0 / OpenID Connect sign-in and account linking.
 *
 * A flow is bound to one provider row of one organization: the redirect enters the tenant (when
 * the sign-in page names one), picks the provider (by ID, else the driver's first enabled one)
 * and stores both in the single-use server-side state; the callback enters the same tenant and
 * builds the same provider's driver. A link flow additionally names the signed-in user; its
 * callback stages the identity until that user's console confirms it (OAuthLinkTrait).
 */
class OAuthService
{
    use OAuthCallbackTrait;
    use OAuthLinkTrait;
    use OAuthRedirectTrait;

    public const MODE_LOGIN = 'login';

    public const MODE_LINK = 'link';

    public function __construct(
        protected ProviderConfigService $configService,
        protected SocialiteDriverFactory $drivers,
        protected OAuthStateStore $states,
        protected TenantContext $tenantContext,
    ) {}

    /**
     * Sign-in: the provider's authorization URL. $tenantSlug: the tenant whose providers are meant;
     * $providerId: one of several providers of the driver (default: the first in sign-in order).
     *
     * @throws OAuthFlowException
     */
    public function getRedirectUrl(string $driver, ?string $tenantSlug = null, ?string $providerId = null): string
    {
        $tenantSlug = is_string($tenantSlug) && $tenantSlug !== '' ? $tenantSlug : null;
        if ($tenantSlug !== null && ! $this->tenantContext->enter($tenantSlug)) {
            throw new OAuthFlowException(__('api.oauth.provider_not_found'), 404);
        }

        $provider = $this->configService->findProvider($driver, $providerId)
            ?? throw new OAuthFlowException(__('api.oauth.provider_not_found'), 404);

        return $this->buildRedirectUrl($provider, ['mode' => self::MODE_LOGIN], $tenantSlug);
    }

    /**
     * Link: the authorization URL whose callback attaches the identity to $user, in the
     * organization the request runs in.
     *
     * @throws OAuthFlowException
     */
    public function getLinkUrl(User $user, string $driver, ?string $providerId = null): string
    {
        $provider = $this->configService->findProvider($driver, $providerId)
            ?? throw new OAuthFlowException(__('api.oauth.provider_not_found'), 404);

        return $this->buildRedirectUrl(
            $provider,
            ['mode' => self::MODE_LINK, 'user_id' => (string) $user->getKey()],
            $this->tenantContext->currentSlug(),
        );
    }

    /**
     * The provider's redirect back (GET /oauth/{driver}/callback).
     *
     * @return array{mode: string, provider?: AuthProvider, error?: string, user?: User, token?: string, link_code?: string}
     */
    public function handleProviderCallback(string $driver, string $encryptedState, ?string $providerError = null): array
    {
        $payload = $this->states->decode($encryptedState);
        if ($payload === null) {
            return ['mode' => self::MODE_LOGIN, 'error' => __('api.oauth.invalid_state')];
        }

        if ($payload['tenant'] !== null && ! $this->tenantContext->enter($payload['tenant'])) {
            return ['mode' => self::MODE_LOGIN, 'error' => __('api.oauth.provider_not_found')];
        }

        $flow = $this->states->pull($payload['sid']);
        if ($flow === null || ($flow['driver'] ?? null) !== $driver || ($flow['tenant'] ?? null) !== $payload['tenant']) {
            return ['mode' => self::MODE_LOGIN, 'error' => __('api.oauth.invalid_state')];
        }

        $mode = ($flow['mode'] ?? null) === self::MODE_LINK ? self::MODE_LINK : self::MODE_LOGIN;
        $providerId = (string) ($flow['provider_id'] ?? '');
        $provider = $providerId !== '' ? $this->configService->findProvider($driver, $providerId) : null;
        if (! $provider) {
            return ['mode' => $mode, 'error' => __('api.oauth.provider_not_found')];
        }

        $result = ['mode' => $mode, 'provider' => $provider];

        if ($providerError !== null && $providerError !== '') {
            Log::warning('OAuth sign-in refused by the provider', [
                'provider' => $provider->getKey(),
                'driver' => $driver,
                'error' => mb_substr($providerError, 0, 100),
            ]);

            return $result + ['error' => $providerError === 'access_denied' ? __('api.oauth.denied') : __('api.oauth.provider_error')];
        }

        $external = $this->fetchExternalUser($provider, $flow);
        if (is_string($external)) {
            return $result + ['error' => $external];
        }

        return $result + ($mode === self::MODE_LINK
            ? $this->stageLink($provider, $driver, $external, $flow)
            : $this->resolveUser($provider, $driver, $external));
    }

    /**
     * Resolve (link / provision) the user behind an externally authenticated subject and issue an
     * API token — the path the OAuth callback takes. Also used by the SAML ACS (driver `saml`), so
     * SAML users get the same account linking, auto-register rule and tenant scoping.
     *
     * @param  SocialiteUser  $externalUser
     */
    public function resolveExternalUser(AuthProvider $provider, string $driver, $externalUser): array
    {
        return $this->resolveUser($provider, $driver, $externalUser);
    }

    /** @return bool whether a tenant with this slug was found and tenancy initialized */
    public function initializeTenantContext(string $slug): bool
    {
        return $this->tenantContext->enter($slug);
    }
}
