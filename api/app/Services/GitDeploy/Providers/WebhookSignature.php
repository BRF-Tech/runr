<?php

namespace App\Services\GitDeploy\Providers;

/**
 * Webhook authenticity per provider, constant-time comparisons only:
 *  - GitHub:        X-Hub-Signature-256: sha256=<hex HMAC-SHA256(body, app webhook secret)>
 *  - GitLab:        X-Gitlab-Token: <the secret registered with the project hook>
 *  - Forgejo/Gitea: X-Forgejo-Signature / X-Gitea-Signature: <hex HMAC-SHA256(body, secret)>
 *                   (X-Hub-Signature-256 is accepted too)
 */
final class WebhookSignature
{
    public static function github(string $body, ?string $header, ?string $secret): bool
    {
        if (! $secret || ! $header || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $header);
    }

    public static function gitlab(?string $header, ?string $secret): bool
    {
        if (! $secret || $header === null || $header === '') {
            return false;
        }

        return hash_equals($secret, $header);
    }

    /**
     * @param  array<string, string|null>  $headers  lower-case header name → value
     */
    public static function forgejo(string $body, array $headers, ?string $secret): bool
    {
        if (! $secret) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);

        foreach (['x-forgejo-signature', 'x-gitea-signature'] as $name) {
            $value = $headers[$name] ?? null;
            if (is_string($value) && $value !== '' && hash_equals($expected, strtolower(trim($value)))) {
                return true;
            }
        }

        $hub = $headers['x-hub-signature-256'] ?? null;

        return is_string($hub) && hash_equals('sha256='.$expected, $hub);
    }
}
