<?php

namespace Tests\Unit\Workflows;

use App\Services\Dns\DnsResolver;
use App\Services\Observability\OutboundUrlGuard;
use App\Workflows\Engine\EndpointGuard;
use App\Workflows\Engine\WorkflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Endpoint overrides cannot point the engine at private / local networks (SSRF guard): the ranges
 * of RunR's outbound guard (OutboundUrlGuard), checked when the override is saved and before
 * every request to it (target(): pinned to the checked addresses).
 */
class EndpointGuardTest extends TestCase
{
    /** @param  array<string, list<string>>  $dns */
    private function guard(array $dns = []): EndpointGuard
    {
        return new EndpointGuard(new OutboundUrlGuard(new class($dns) extends DnsResolver
        {
            /** @param  array<string, list<string>>  $dns */
            public function __construct(private readonly array $dns) {}

            public function addresses(string $name): array
            {
                return $this->dns[$name] ?? ['93.184.216.34'];
            }
        }));
    }

    public static function rejected(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com/'],
            'no host' => ['https:///path'],
            'credentials' => ['https://user:pw@example.com/'],
            'localhost' => ['http://localhost:8080/'],
            'sub.localhost' => ['http://api.localhost/'],
            'localhost.localdomain' => ['http://localhost.localdomain/'],
            'loopback' => ['http://127.0.0.1/'],
            'private 10/8' => ['http://10.1.2.3/'],
            'private 192.168' => ['http://192.168.1.10/'],
            'private 172.16' => ['http://172.20.0.5/'],
            'link-local' => ['http://169.254.169.254/latest/meta-data'],
            'cgnat' => ['http://100.100.1.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 ula' => ['http://[fd00::1]/'],
            'ipv6 link-local' => ['http://[fe80::1]/'],
            'ipv6 site-local' => ['http://[fec0::1]/'],
            'mapped private' => ['http://[::ffff:10.0.0.1]/'],
            'compatible metadata' => ['http://[::a9fe:a9fe]/'],
            '6to4 metadata' => ['http://[2002:a9fe:a9fe::]/'],
            'nat64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/'],
            'nat64 local-use' => ['http://[64:ff9b:1::1]/'],
            'teredo' => ['http://[2001::1]/'],
            'cluster service' => ['http://fn.workflows.svc.cluster.local/'],
            'decimal ipv4' => ['http://2130706433/'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_rejects(string $url): void
    {
        $this->expectException(WorkflowException::class);
        $this->guard()->assertAllowed($url);
    }

    public function test_rejects_hostnames_resolving_to_private_addresses(): void
    {
        foreach (['10.0.0.8', '::ffff:127.0.0.1', '64:ff9b::a9fe:a9fe'] as $ip) {
            try {
                $this->guard(['internal.example' => [$ip]])->assertAllowed('https://internal.example/');
                $this->fail("{$ip} accepted");
            } catch (WorkflowException $e) {
                $this->assertSame('endpoint_private', $e->errorCode);
            }
        }
    }

    public function test_accepts_public_endpoints(): void
    {
        $this->guard()->assertAllowed('https://fn.example.com/api/runr');
        $this->guard()->assertAllowed('http://93.184.216.34:8080/');
        $this->guard(['v6.example' => ['2606:4700:4700::1111']])->assertAllowed('https://v6.example/');
        $this->guard()->assertAllowed('http://[::ffff:93.184.216.34]/');
        $this->addToAssertionCount(4);
    }

    public function test_a_name_that_does_not_resolve_yet_may_be_saved_but_not_called(): void
    {
        $guard = $this->guard(['later.example' => []]);
        $guard->assertAllowed('https://later.example/');

        $this->expectException(WorkflowException::class);
        $guard->target('https://later.example/');
    }

    public function test_the_request_is_pinned_to_the_checked_addresses(): void
    {
        $options = $this->guard(['fn.example.com' => ['93.184.216.34', '2606:4700:4700::1111']])->target('https://fn.example.com/api/runr')->options();

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('fn.example.com:443:93.184.216.34,[2606:4700:4700::1111]', $options['curl'][CURLOPT_RESOLVE][0]);
    }

    public function test_private_endpoints_allowed_by_config(): void
    {
        config(['workflows.allow_private_endpoints' => true]);

        $this->guard()->assertAllowed('http://10.1.2.3:8080/');
        $this->assertSame(['allow_redirects' => false], $this->guard()->target('http://10.1.2.3:8080/')->options());
    }

    public function test_is_public_ip(): void
    {
        $this->assertTrue(EndpointGuard::isPublicIp('8.8.8.8'));
        $this->assertFalse(EndpointGuard::isPublicIp('100.64.0.1'));
        $this->assertFalse(EndpointGuard::isPublicIp('100.127.255.255'));
        $this->assertTrue(EndpointGuard::isPublicIp('100.128.0.1'));
        $this->assertFalse(EndpointGuard::isPublicIp('not-an-ip'));
        $this->assertFalse(EndpointGuard::isPublicIp('2002:a9fe:a9fe::'));
    }
}
