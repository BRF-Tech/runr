<?php

namespace Tests\Feature\AuthProvider;

use App\Services\Dns\DnsResolver;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Services\SocialiteDriverFactory;
use Modules\AuthProvider\Services\TenantContext;
use Tests\TestCase;

/**
 * A tenant's self-managed GitLab sign-in is held to the outbound guard like a tenant's OpenID
 * Connect provider: the instance URL must be public, and the driver's requests go to the
 * addresses the guard checked (curl pin, no redirects). The platform's own provider may name
 * any instance.
 */
class GitLabInstanceGuardTest extends TestCase
{
    /** @var array<string, list<string>> */
    public array $dns = [];

    private MockHandler $mock;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(DnsResolver::class, new class($test) extends DnsResolver
        {
            public function __construct(private readonly GitLabInstanceGuardTest $test) {}

            public function addresses(string $name): array
            {
                return $this->test->dns[$name] ?? [];
            }
        });

        $this->mock = new MockHandler;
        config(['authprovider.guzzle' => ['timeout' => 15, 'handler' => HandlerStack::create($this->mock)]]);
    }

    private function inTenant(?string $slug): void
    {
        $this->app->instance(TenantContext::class, new class($slug) extends TenantContext
        {
            public function __construct(private readonly ?string $slug) {}

            public function currentSlug(): ?string
            {
                return $this->slug;
            }
        });
    }

    private function gitlab(string $instance): AuthProvider
    {
        return new AuthProvider(['name' => 'GitLab', 'driver' => 'gitlab', 'client_id' => 'id', 'client_secret' => 'secret', 'gitlab_instance_uri' => $instance]);
    }

    public function test_a_tenants_instance_is_pinned_to_the_checked_addresses(): void
    {
        $this->inTenant('acme');
        $this->dns['gitlab.acme.example'] = ['93.184.216.34', '2606:4700:4700::1111'];
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"t"}'));

        $driver = app(SocialiteDriverFactory::class)->make($this->gitlab('https://gitlab.acme.example'));
        $driver->getAccessTokenResponse('code');

        $options = $this->mock->getLastOptions();
        $this->assertSame('https://gitlab.acme.example/oauth/token', (string) $this->mock->getLastRequest()->getUri());
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('gitlab.acme.example:443:93.184.216.34,[2606:4700:4700::1111]', $options['curl'][CURLOPT_RESOLVE][0]);
    }

    public function test_a_tenants_instance_on_an_internal_address_is_refused(): void
    {
        $this->inTenant('acme');

        foreach (['10.0.0.8', '::ffff:169.254.169.254', '64:ff9b::a9fe:a9fe'] as $internal) {
            $this->dns['gitlab.acme.example'] = [$internal];
            try {
                app(SocialiteDriverFactory::class)->make($this->gitlab('https://gitlab.acme.example'));
                $this->fail("{$internal} accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(__('observability.url.private'), $e->getMessage());
            }
        }
        $this->assertNull($this->mock->getLastRequest(), 'nothing sent');
    }

    public function test_the_platforms_provider_is_not_restricted(): void
    {
        $this->inTenant(null);
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"t"}'));

        app(SocialiteDriverFactory::class)->make($this->gitlab('https://gitlab.internal.example'))->getAccessTokenResponse('code');

        $this->assertArrayNotHasKey('curl', $this->mock->getLastOptions());
    }
}
