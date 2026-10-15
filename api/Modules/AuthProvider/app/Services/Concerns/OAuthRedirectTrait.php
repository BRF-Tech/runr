<?php

namespace Modules\AuthProvider\Services\Concerns;

use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;

trait OAuthRedirectTrait
{
    protected function buildRedirectUrl(string $driver, array $statePayload): string
    {
        $statePayload['exp'] = now()->addMinutes(
            config('authprovider.state_ttl_minutes', 5)
        )->timestamp;

        $encryptedState = Crypt::encryptString(json_encode($statePayload));

        $socialiteDriver = Socialite::driver($driver);

        $provider = $this->configService->getProvider($driver);
        if ($provider && $provider->scopes) {
            $socialiteDriver->scopes($provider->scopes);
        }

        return $socialiteDriver
            ->stateless()
            ->with(['state' => $encryptedState])
            ->redirect()
            ->getTargetUrl();
    }

    protected function decryptState(string $encryptedState): ?array
    {
        try {
            $json = Crypt::decryptString($encryptedState);
            $payload = json_decode($json, true);

            if (! $payload || ! isset($payload['exp'])) {
                return null;
            }

            // Check TTL
            if (now()->timestamp > $payload['exp']) {
                return null;
            }

            return $payload;
        } catch (\Exception) {
            return null;
        }
    }
}
