<?php

namespace Tests\Unit;

use App\Services\HelmService;
use PHPUnit\Framework\TestCase;

/**
 * The chart version a release runs comes from `helm list -o json` ("chart":
 * "<name>-<version>"); `helm status -o json` strips the chart, so it cannot tell.
 */
class HelmChartVersionTest extends TestCase
{
    /** `helm list -n observability --filter ^loki-stack$ -o json` of helm 3.16 */
    private const LIST = '[{"name":"loki-stack","namespace":"observability","revision":"2","updated":"2026-09-30 08:01:44.118904203 +0000 UTC","status":"deployed","chart":"loki-stack-2.10.3","app_version":"v2.9.3"}]';

    public function test_the_version_is_read_from_the_chart_column(): void
    {
        $this->assertSame('2.10.3', HelmService::chartVersionFromList(self::LIST, 'loki-stack', 'loki-stack'));
        $this->assertSame('40.3.0', HelmService::chartVersionFromList(
            '[{"name":"traefik","namespace":"knative-serving","revision":"1","updated":"2026-09-02 09:14:03 +0000 UTC","status":"deployed","chart":"traefik-40.3.0","app_version":"v3.7.4"}]',
            'traefik',
            'traefik',
        ));
        // chart names with dashes and pre-release versions
        $this->assertSame('1.2.0-rc.1', HelmService::chartVersionFromList('[{"name":"x","chart":"my-chart-1.2.0-rc.1"}]', 'x', 'my-chart'));
    }

    public function test_no_version_when_it_cannot_be_trusted(): void
    {
        // not listed (not installed, or pending: helm list shows deployed / failed releases)
        $this->assertNull(HelmService::chartVersionFromList('[]', 'loki-stack', 'loki-stack'));
        // another release of the filter's match, another chart, a broken chart column, no JSON
        $this->assertNull(HelmService::chartVersionFromList('[{"name":"loki-stack-old","chart":"loki-stack-2.10.3"}]', 'loki-stack', 'loki-stack'));
        $this->assertNull(HelmService::chartVersionFromList('[{"name":"loki-stack","chart":"loki-6.16.0"}]', 'loki-stack', 'loki-stack'));
        $this->assertNull(HelmService::chartVersionFromList('[{"name":"loki-stack","chart":"MISSING"}]', 'loki-stack', 'loki-stack'));
        $this->assertNull(HelmService::chartVersionFromList('[{"name":"loki-stack","chart":"loki-stack-latest"}]', 'loki-stack', 'loki-stack'));
        $this->assertNull(HelmService::chartVersionFromList('Error: Kubernetes cluster unreachable', 'loki-stack', 'loki-stack'));
    }
}
