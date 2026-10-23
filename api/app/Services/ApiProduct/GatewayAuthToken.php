<?php

namespace App\Services\ApiProduct;

/**
 * Credential of a region's gateway-auth service for its two callbacks (config sync, usage
 * reports): HMAC-SHA256(APP_KEY, "runr-gateway-auth|<region id>"), written into the Secret
 * runr-gateway-auth of the region's project namespace. Nothing is stored: the API derives the token
 * again; a new APP_KEY invalidates every token (the next gateway sync writes the new one).
 */
class GatewayAuthToken
{
    public function token(string $regionId): string
    {
        return hash_hmac('sha256', 'runr-gateway-auth|'.$regionId, $this->appKey());
    }

    public function verify(string $regionId, ?string $bearer): bool
    {
        return $bearer !== null && $bearer !== '' && hash_equals($this->token($regionId), $bearer);
    }

    private function appKey(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
