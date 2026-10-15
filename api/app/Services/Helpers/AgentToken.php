<?php

namespace App\Services\Helpers;

/**
 * Per-region runr-agent token.
 * The plain token only lives in the region's Secret (observability/runr-agent-token);
 * RunR stores its SHA-256 hash (knative_regions.agent_token_hash) and compares
 * in constant time. The token is 256 bits of randomness, so a plain SHA-256 is enough.
 */
final class AgentToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Missing token, missing stored hash or mismatch → false.
     */
    public static function verify(?string $storedHash, ?string $token): bool
    {
        if (! $storedHash || $token === null || $token === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($token));
    }

    /**
     * Short, non-reversible revision marker for the Deployment pod template.
     * Changes with the token, so the agent restarts and re-reads the Secret.
     */
    public static function revision(string $tokenHash): string
    {
        return substr(hash('sha256', 'runr-agent-rev:'.$tokenHash), 0, 16);
    }
}
