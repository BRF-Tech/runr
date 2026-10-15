<?php

namespace Modules\AuthProvider\Services;

use Modules\AuthProvider\Models\AuthProvider;

class ProviderConfigService
{
    public function resolveConfig(string $driver): ?array
    {
        // SAML is not a Socialite driver (SamlService builds its own settings per provider).
        if ($driver === AuthProvider::DRIVER_SAML) {
            return null;
        }

        try {
            $provider = AuthProvider::where('driver', $driver)
                ->where('is_enabled', true)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (! $provider) {
            return null;
        }

        $config = [
            'client_id' => $provider->client_id,
            'client_secret' => $provider->client_secret,
            'redirect' => $this->callbackUrl($driver),
        ];

        if ($driver === 'oidc' && $provider->oidc_discovery_url) {
            $config['discovery_url'] = $provider->oidc_discovery_url;
            $config['issuer'] = $provider->oidc_issuer;
        }

        if ($driver === 'google' && $provider->google_hosted_domain) {
            $config['hosted_domain'] = $provider->google_hosted_domain;
        }

        if ($driver === 'microsoft') {
            $config['tenant'] = $provider->microsoft_tenant ?? 'common';
        }

        if ($driver === 'gitlab' && $provider->gitlab_instance_uri) {
            $config['instance_uri'] = $provider->gitlab_instance_uri;
        }

        if ($provider->scopes) {
            $config['scopes'] = $provider->scopes;
        }

        return $config;
    }

    public function callbackUrl(string $driver): string
    {
        return url("/oauth/{$driver}/callback");
    }

    public function getProvider(string $driver): ?AuthProvider
    {
        try {
            return AuthProvider::where('driver', $driver)
                ->where('is_enabled', true)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }
}
