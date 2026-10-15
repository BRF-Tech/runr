<?php

namespace Tests\Unit;

use App\Services\KnativeInstallService;
use PHPUnit\Framework\TestCase;

class KnativePrometheusTraitTest extends TestCase
{
    public function test_prometheus_url_is_cluster_internal_not_a_node_ip(): void
    {
        $url = KnativeInstallService::prometheusClusterIpUrl('observability');

        $this->assertSame('http://prometheus-operated.observability:9090', $url);
    }

    public function test_prometheus_url_is_namespace_scoped(): void
    {
        $url = KnativeInstallService::prometheusClusterIpUrl('custom-observability-ns');

        $this->assertSame('http://prometheus-operated.custom-observability-ns:9090', $url);
        $this->assertStringNotContainsString(':30090', $url);
    }
}
