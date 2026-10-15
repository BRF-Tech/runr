<?php

namespace Tests\Unit\GitDeploy;

use App\Models\Git\GitProvider;
use App\Services\GitDeploy\Providers\GitHubAppAuth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** GitHub App JWT (RS256, iss = app id) and the cached installation token. */
class GitHubAppAuthTest extends TestCase
{
    private function provider(): GitProvider
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return new GitProvider(['type' => 'github', 'app_id' => '12345', 'private_key' => $pem]);
    }

    public function test_the_jwt_is_signed_with_the_app_key(): void
    {
        $provider = $this->provider();
        $jwt = (new GitHubAppAuth)->jwt($provider, 1_760_000_000);

        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4));

        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode($decode($header), true));
        $this->assertSame(['iat' => 1_759_999_940, 'exp' => 1_760_000_540, 'iss' => '12345'], json_decode($decode($payload), true));

        $public = openssl_pkey_get_details(openssl_pkey_get_private($provider->private_key))['key'];
        $this->assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), $public, OPENSSL_ALGO_SHA256));
    }

    public function test_an_invalid_key_is_reported(): void
    {
        $this->expectExceptionMessage('not a valid PEM key');

        (new GitHubAppAuth)->jwt(new GitProvider(['type' => 'github', 'app_id' => '1', 'private_key' => 'nope']));
    }

    public function test_the_installation_token_is_requested_once_and_cached(): void
    {
        Http::fake(['api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_installation'], 201)]);
        $provider = $this->provider();
        $provider->id = 'P1';
        $auth = new GitHubAppAuth;

        $this->assertSame('ghs_installation', $auth->installationToken($provider, '777'));
        $this->assertSame('ghs_installation', $auth->installationToken($provider, '777'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer ey'));
    }
}
