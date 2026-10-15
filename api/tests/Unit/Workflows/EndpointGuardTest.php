<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\EndpointGuard;
use App\Workflows\Engine\WorkflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Endpoint overrides cannot point the engine at private / local networks (SSRF guard). */
class EndpointGuardTest extends TestCase
{
    private function guard(array $dns = []): EndpointGuard
    {
        return new EndpointGuard(fn (string $host) => $dns[$host] ?? ['93.184.216.34']);
    }

    public static function rejected(): array
    {
        return [
            'ftp scheme' => ['ftp://example.com/'],
            'no host' => ['https:///path'],
            'credentials' => ['https://user:pw@example.com/'],
            'localhost' => ['http://localhost:8080/'],
            'sub.localhost' => ['http://api.localhost/'],
            'loopback' => ['http://127.0.0.1/'],
            'private 10/8' => ['http://10.1.2.3/'],
            'private 192.168' => ['http://192.168.1.10/'],
            'private 172.16' => ['http://172.20.0.5/'],
            'link-local' => ['http://169.254.169.254/latest/meta-data'],
            'cgnat' => ['http://100.100.1.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 ula' => ['http://[fd00::1]/'],
            'mapped private' => ['http://[::ffff:10.0.0.1]/'],
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
        $this->expectException(WorkflowException::class);
        $this->guard(['internal.example' => ['10.0.0.8']])->assertAllowed('https://internal.example/');
    }

    public function test_accepts_public_endpoints(): void
    {
        $this->guard()->assertAllowed('https://fn.example.com/api/runr');
        $this->guard()->assertAllowed('http://93.184.216.34:8080/');
        $this->guard(['v6.example' => ['2606:4700:4700::1111']])->assertAllowed('https://v6.example/');
        $this->addToAssertionCount(3);
    }

    public function test_private_endpoints_allowed_by_config(): void
    {
        config(['workflows.allow_private_endpoints' => true]);

        $this->guard()->assertAllowed('http://10.1.2.3:8080/');
        $this->addToAssertionCount(1);
    }

    public function test_is_public_ip(): void
    {
        $this->assertTrue(EndpointGuard::isPublicIp('8.8.8.8'));
        $this->assertFalse(EndpointGuard::isPublicIp('100.64.0.1'));
        $this->assertFalse(EndpointGuard::isPublicIp('100.127.255.255'));
        $this->assertTrue(EndpointGuard::isPublicIp('100.128.0.1'));
        $this->assertFalse(EndpointGuard::isPublicIp('not-an-ip'));
    }
}
