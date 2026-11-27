<?php

namespace Tests\Feature\AuthProvider;

use App\Services\Dns\DnsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\AuthProvider\Services\Oidc\OidcException;
use Modules\AuthProvider\Services\Oidc\OidcProvider;
use Tests\TestCase;

/**
 * GHSA-2c93-rcvw-qpq2: an organization's identity provider (publicTargetsOnly, a tenant's
 * context) is called at the addresses the guard checked right before the request — the discovery
 * document, the key set, the token and userinfo endpoints — never at a name resolved again, and
 * never at an internal address in one of the IPv6 forms carrying one.
 */
class OidcOutboundGuardTest extends TestCase
{
    /** @var array<string, list<string>> */
    public array $dns = [];

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly OidcOutboundGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->dns[$name] ?? [];
            }
        });

        Http::fake(function ($request, array $options) {
            $this->sent[] = ['url' => $request->url(), 'options' => $options];

            return str_ends_with($request->url(), '/.well-known/openid-configuration')
                ? Http::response([
                    'issuer' => 'https://idp.example.com',
                    'authorization_endpoint' => 'https://idp.example.com/authorize',
                    'token_endpoint' => 'https://idp.example.com/token',
                    'jwks_uri' => 'https://keys.example.com/jwks',
                ])
                : Http::response(['keys' => []]);
        });
    }

    private function provider(bool $publicTargetsOnly): OidcProvider
    {
        return (new OidcProvider(Request::create('/'), 'client', 'secret', 'https://runr.example.test/callback'))
            ->configure('https://idp.example.com', null, $publicTargetsOnly);
    }

    public function test_requests_of_a_tenant_are_pinned_to_the_checked_addresses(): void
    {
        $this->dns = ['idp.example.com' => ['93.184.216.34', '2606:4700:4700::1111']];

        $this->assertSame('https://idp.example.com/token', $this->provider(true)->discovery()['token_endpoint']);

        $this->assertCount(1, $this->sent);
        $this->assertSame('https://idp.example.com/.well-known/openid-configuration', $this->sent[0]['url']);
        $this->assertFalse($this->sent[0]['options']['allow_redirects']);
        $this->assertSame('idp.example.com:443:93.184.216.34,[2606:4700:4700::1111]', $this->sent[0]['options']['curl'][CURLOPT_RESOLVE][0]);
    }

    public function test_an_internal_address_is_refused_before_anything_is_sent(): void
    {
        foreach (['169.254.169.254', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::', 'fec0::1'] as $internal) {
            $this->dns = ['idp.example.com' => [$internal]];
            try {
                $this->provider(true)->discovery();
                $this->fail("{$internal} reached");
            } catch (OidcException $e) {
                $this->assertStringContainsString(__('observability.url.private'), $e->getMessage());
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function test_the_key_set_is_checked_at_its_own_request(): void
    {
        $this->dns = ['idp.example.com' => ['93.184.216.34'], 'keys.example.com' => ['10.0.0.9']];

        $this->expectException(OidcException::class);
        try {
            $this->provider(true)->validateIdToken('eyJhbGciOiJSUzI1NiIsImtpZCI6ImsxIn0.eyJzdWIiOiIxIn0.c2ln');
        } finally {
            $this->assertSame(['https://idp.example.com/.well-known/openid-configuration'], array_column($this->sent, 'url'), 'the internal key set is never fetched');
        }
    }

    public function test_the_platforms_own_provider_is_not_restricted(): void
    {
        $this->dns = [];

        $this->provider(false)->discovery();

        $this->assertCount(1, $this->sent);
        $this->assertArrayNotHasKey('curl', $this->sent[0]['options']);
    }
}
