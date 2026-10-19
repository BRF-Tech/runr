<?php

namespace Modules\AuthProvider\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\OAuthFlowException;

trait OAuthRedirectTrait
{
    /**
     * Authorization URL of $provider. The flow (mode, user, provider row, and for OIDC the PKCE
     * verifier and nonce) is stored server-side under the state.
     *
     * @param  array<string, mixed>  $flow
     *
     * @throws OAuthFlowException
     */
    protected function buildRedirectUrl(AuthProvider $provider, array $flow, ?string $tenantSlug): string
    {
        $flow['provider_id'] = (string) $provider->getKey();
        $flow['driver'] = $provider->driver;

        if ($provider->driver === 'oidc') {
            $flow['code_verifier'] = Str::random(64);
            $flow['nonce'] = Str::random(40);
        }

        $state = $this->states->issue($flow, $tenantSlug);

        try {
            return $this->drivers->make($provider, $flow)
                ->with(['state' => $state])
                ->redirect()
                ->getTargetUrl();
        } catch (\Throwable $e) {
            // OIDC: discovery document unreachable or invalid
            Log::warning('OAuth sign-in could not start', [
                'provider' => $provider->getKey(),
                'driver' => $provider->driver,
                'reason' => $e->getMessage(),
            ]);

            throw new OAuthFlowException(__('api.oauth.provider_unavailable'), 502);
        }
    }
}
