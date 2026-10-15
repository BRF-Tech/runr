<?php

namespace App\Services\GitDeploy\Providers;

use App\Models\Git\GitProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GitHub App authentication: an RS256 JWT signed with the app's private key (iss = app id,
 * valid 9 minutes) is exchanged for an installation access token (valid one hour, cached for
 * 50 minutes, encrypted in the cache).
 */
class GitHubAppAuth
{
    public function jwt(GitProvider $provider, ?int $now = null): string
    {
        $now ??= time();
        $key = openssl_pkey_get_private((string) $provider->private_key);

        if ($key === false) {
            throw new RuntimeException('The GitHub App private key is not a valid PEM key');
        }

        $segments = [
            self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) $provider->app_id])),
        ];

        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing the GitHub App token failed');
        }

        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    public function installationToken(GitProvider $provider, string $installationId): string
    {
        $cacheKey = "runr-git:github-token:{$provider->id}:{$installationId}";

        if ($cached = Cache::get($cacheKey)) {
            return decrypt($cached);
        }

        $response = Http::timeout((int) config('git-deploy.http_timeout', 20))
            ->withToken($this->jwt($provider))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post($provider->apiUrl()."/app/installations/{$installationId}/access_tokens");

        if (! $response->successful() || ! is_string($token = $response->json('token'))) {
            throw new RuntimeException("GitHub installation token request failed (HTTP {$response->status()})");
        }

        Cache::put($cacheKey, encrypt($token), now()->addMinutes(50));

        return $token;
    }

    public function forget(GitProvider $provider, string $installationId): void
    {
        Cache::forget("runr-git:github-token:{$provider->id}:{$installationId}");
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
