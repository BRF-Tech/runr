<?php

namespace App\Services\GitDeploy;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The state parameter of the GitHub App installation and the GitLab OAuth round trip: who
 * started it (user, organization, project, provider), encrypted with the application key and
 * valid for TTL seconds. The callback trusts nothing else from the browser.
 */
final class OAuthState
{
    public const TTL = 900;

    public static function issue(string $userId, ?string $tenantId, string $projectId, string $providerId): string
    {
        return Crypt::encryptString(json_encode([
            'u' => $userId,
            't' => $tenantId,
            'p' => $projectId,
            'g' => $providerId,
            'x' => time() + self::TTL,
            'n' => Str::random(16),
        ]));
    }

    /**
     * @return array{user_id: string, tenant_id: ?string, project_id: string, provider_id: string}|null
     */
    public static function read(?string $state): ?array
    {
        if (! $state) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['x'] ?? 0) < time() || empty($data['u']) || empty($data['p']) || empty($data['g'])) {
            return null;
        }

        return [
            'user_id' => (string) $data['u'],
            'tenant_id' => isset($data['t']) ? (string) $data['t'] : null,
            'project_id' => (string) $data['p'],
            'provider_id' => (string) $data['g'],
        ];
    }
}
