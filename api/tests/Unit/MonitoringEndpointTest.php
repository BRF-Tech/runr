<?php

namespace Tests\Unit;

use App\Services\Helpers\MonitoringEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Operator-supplied Prometheus/Loki must be an in-cluster Service of the region's cluster; RunR
 * reads it through the region's K8s API service proxy (no HTTP from the central API to arbitrary
 * URLs — tenant owners can set these fields).
 */
class MonitoringEndpointTest extends TestCase
{
    public static function validUrls(): array
    {
        return [
            'svc' => ['http://prom.monitoring.svc:9090'],
            'svc.cluster.local' => ['http://prometheus-operated.monitoring.svc.cluster.local:9090'],
            'https with path' => ['https://loki-gateway.observability.svc:443/loki'],
            'path with trailing' => ['http://thanos-query.monitoring.svc:10902/prometheus/'],
        ];
    }

    public static function invalidUrls(): array
    {
        return [
            'cloud metadata IP' => ['http://169.254.169.254:80/latest/meta-data'],
            'metadata host' => ['http://metadata.google.internal:80'],
            'external host' => ['https://prometheus.example.com:9090'],
            'svc then external' => ['http://a.b.svc.cluster.local.evil.test:80'],
            'IP' => ['http://10.0.0.5:30090'],
            'no namespace' => ['http://prometheus.svc:9090'],
            'no port' => ['http://prom.monitoring.svc'],
            'port out of range' => ['http://prom.monitoring.svc:70000'],
            'userinfo' => ['http://user:pw@prom.monitoring.svc:9090'],
            'query string' => ['http://prom.monitoring.svc:9090/?x=1'],
            'other scheme' => ['ftp://prom.monitoring.svc:21'],
            'uppercase host' => ['http://Prom.Monitoring.svc:9090'],
            'trailing newline' => ["http://prom.monitoring.svc:9090\n"],
            'too long' => ['http://prom.monitoring.svc:9090/'.str_repeat('a', 240)],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_accepts_in_cluster_service_urls(string $url): void
    {
        $this->assertTrue(MonitoringEndpoint::isValidInput($url), $url);
    }

    #[DataProvider('invalidUrls')]
    public function test_rejects_everything_else(string $url): void
    {
        $this->assertFalse(MonitoringEndpoint::isValidInput($url), $url);
    }

    public function test_parses_service_proxy_coordinates(): void
    {
        $e = MonitoringEndpoint::parse('https://loki-gateway.observability.svc.cluster.local:8443/loki/');
        $this->assertSame(['https', 'loki-gateway', 'observability', 8443, 'loki'], [$e->scheme, $e->service, $e->namespace, $e->port, $e->path]);
        $this->assertSame('loki/loki/api/v1/query_range', $e->proxyPath('loki/api/v1/query_range'));
        $this->assertSame('https://loki-gateway.observability.svc:8443/loki/loki/api/v1/query_range', $e->directUrl('/loki/api/v1/query_range'));

        // RunR's own stored form (no .svc)
        $own = MonitoringEndpoint::parse('http://prometheus-operated.observability:9090');
        $this->assertSame(['prometheus-operated', 'observability', 9090, ''], [$own->service, $own->namespace, $own->port, $own->path]);
        $this->assertSame('api/v1/query', $own->proxyPath('api/v1/query'));

        // A legacy NodePort URL or anything else is not a Service: callers use RunR's default
        $this->assertNull(MonitoringEndpoint::parse('http://10.0.0.5:30090'));
        $this->assertNull(MonitoringEndpoint::parse('http://169.254.169.254/latest'));
        $this->assertNull(MonitoringEndpoint::parse(null));
    }
}
