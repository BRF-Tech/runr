<?php

namespace Modules\AuthProvider\Services;

use App\Services\Observability\OutboundUrlGuard;
use InvalidArgumentException;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GithubProvider;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\Oidc\OidcProvider;
use Modules\AuthProvider\Services\Socialite\GoogleProvider;
use SocialiteProviders\GitLab\Provider as GitLabProvider;
use SocialiteProviders\Manager\Config as SocialiteConfig;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

/**
 * The Socialite driver of one provider row, built from that row alone — never from the
 * application-wide `services.<driver>` configuration — so the redirect and the callback of a
 * sign-in use the same client, whichever organization (central or tenant) and whichever of
 * several providers of the driver it belongs to.
 */
class SocialiteDriverFactory
{
    public function __construct(
        private readonly ProviderConfigService $config,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $flow  the sign-in's server-side state (OIDC: code_verifier, nonce)
     */
    public function make(AuthProvider $provider, array $flow = []): AbstractProvider
    {
        $request = app('request');
        $clientId = (string) $provider->client_id;
        $secret = (string) $provider->client_secret;
        $redirect = $this->config->callbackUrl((string) $provider->driver);
        $guzzle = (array) config('authprovider.guzzle', []);

        switch ($provider->driver) {
            case 'google':
                $driver = (new GoogleProvider($request, $clientId, $secret, $redirect, $guzzle))
                    ->hostedDomain($provider->google_hosted_domain);
                break;
            case 'github':
                $driver = new GithubProvider($request, $clientId, $secret, $redirect, $guzzle);
                break;
            case 'microsoft':
                $driver = new MicrosoftProvider($request, $clientId, $secret, $redirect, $guzzle);
                $driver->setConfig(new SocialiteConfig($clientId, $secret, $redirect, [
                    'tenant' => trim((string) $provider->microsoft_tenant) ?: 'common',
                ]));
                break;
            case 'gitlab':
                $instance = $this->config->gitlabInstanceUri($provider);
                $driver = new GitLabProvider($request, $clientId, $secret, $redirect, $this->publicTargetOnly($instance, $guzzle));
                $driver->setConfig(new SocialiteConfig($clientId, $secret, $redirect, [
                    'instance_uri' => $instance,
                ]));
                break;
            case 'oidc':
                $driver = (new OidcProvider($request, $clientId, $secret, $redirect, $guzzle))
                    // A tenant's identity provider must be on the internet (no calls into the platform's network)
                    ->configure((string) $provider->oidc_discovery_url, $provider->oidc_issuer, $this->tenant->currentSlug() !== null)
                    ->withFlow($this->flowValue($flow, 'code_verifier'), $this->flowValue($flow, 'nonce'));
                break;
            default:
                throw new InvalidArgumentException("No OAuth driver for '{$provider->driver}'");
        }

        // Configured scopes add to the driver's defaults
        if (! empty($provider->scopes)) {
            $driver->scopes((array) $provider->scopes);
        }

        return $driver->stateless();
    }

    /**
     * A tenant's self-managed GitLab must be on the internet, like a tenant's OpenID Connect
     * provider: its URL is checked when the driver is built (OutboundUrlGuard::target()) and the
     * driver's requests — token exchange and user, both on that host — go to the addresses that
     * check saw, without redirects.
     *
     * @param  array<string, mixed>  $guzzle
     * @return array<string, mixed>
     */
    private function publicTargetOnly(string $url, array $guzzle): array
    {
        if ($this->tenant->currentSlug() === null) {
            return $guzzle;
        }

        $target = app(OutboundUrlGuard::class)->target($url);
        if (! $target->allowed()) {
            throw new InvalidArgumentException("{$url}: {$target->reason}");
        }

        return array_replace($guzzle, $target->options());
    }

    /** @param array<string, mixed> $flow */
    private function flowValue(array $flow, string $key): ?string
    {
        return is_string($flow[$key] ?? null) ? $flow[$key] : null;
    }
}
