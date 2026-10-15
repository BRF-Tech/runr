<?php

namespace App\Mcp\OAuth;

/**
 * PKCE (RFC 7636), S256 only (OAuth 2.1 / MCP authorization): the token request proves it holds
 * the verifier whose SHA-256 the authorization request sent.
 */
final class Pkce
{
    /** RFC 7636 4.1: 43–128 characters of [A-Z a-z 0-9 - . _ ~] */
    public const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    /** base64url(SHA-256) is 43 characters without padding */
    public const CHALLENGE_PATTERN = '/^[A-Za-z0-9\-_]{43}$/';

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function verify(string $verifier, string $challenge): bool
    {
        return preg_match(self::VERIFIER_PATTERN, $verifier) === 1
            && hash_equals($challenge, self::challenge($verifier));
    }
}
