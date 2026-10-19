<?php

namespace Tests\Unit;

use App\Rules\IpOrCidr;
use App\Services\HelmService;
use App\Services\Helpers\IngressTrust;
use App\Services\KnativeInstallService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Region Traefik trust: PROXY protocol and X-Forwarded-* from nobody by default,
 * from the region's ingress_trusted_ips when set.
 */
class IngressTrustTest extends TestCase
{
    private function traefikValues(array $trustedIps = []): array
    {
        $service = (new \ReflectionClass(KnativeInstallService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'traefikHelmValues');
        $method->setAccessible(true);

        return $method->invoke($service, 'knative-serving', $trustedIps);
    }

    public function test_the_region_traefik_trusts_nobody_by_default(): void
    {
        $ports = $this->traefikValues()['ports'];

        // no trustedIPs: the chart's default, the empty list
        foreach (['web', 'websecure'] as $entryPoint) {
            $this->assertSame(['insecure' => false], $ports[$entryPoint]['proxyProtocol'], $entryPoint);
            $this->assertSame(['insecure' => false], $ports[$entryPoint]['forwardedHeaders'], $entryPoint);
        }
        // the rest of the entry point is unchanged
        $this->assertSame(8443, $ports['websecure']['port']);
        $this->assertTrue($ports['websecure']['http']['tls']['enabled']);
        // read / write timeouts follow the default request_timeout (RequestTimeout)
        $this->assertSame('310s', $ports['web']['transport']['respondingTimeouts']['readTimeout']);
        $this->assertArrayNotHasKey('proxyProtocol', $ports['metrics']);
    }

    public function test_ingress_trusted_ips_go_to_both_lists_of_both_entry_points(): void
    {
        $ports = $this->traefikValues(['10.0.0.0/24', '192.0.2.7'])['ports'];

        foreach (['web', 'websecure'] as $entryPoint) {
            $this->assertSame(['10.0.0.0/24', '192.0.2.7'], $ports[$entryPoint]['proxyProtocol']['trustedIPs']);
            $this->assertSame(['10.0.0.0/24', '192.0.2.7'], $ports[$entryPoint]['forwardedHeaders']['trustedIPs']);
        }
    }

    public function test_the_values_file_never_gives_the_chart_a_map_for_trusted_ips(): void
    {
        // HelmService writes valuesArray as YAML, where an empty PHP array becomes {}; Traefik's
        // schema wants a list there ("Invalid type. Expected: array, given: object")
        foreach ([[], ['10.0.0.0/24']] as $ips) {
            $yaml = HelmService::valuesYaml($this->traefikValues($ips));
            $this->assertDoesNotMatchRegularExpression('/trustedIPs:\s*\{/', $yaml);
            $values = Yaml::parse($yaml);

            foreach (['web', 'websecure'] as $entryPoint) {
                foreach (['proxyProtocol', 'forwardedHeaders'] as $kind) {
                    $trust = $values['ports'][$entryPoint][$kind];
                    if ($ips === []) {
                        $this->assertArrayNotHasKey('trustedIPs', $trust, "{$entryPoint}.{$kind}");
                    } else {
                        $this->assertSame($ips, $trust['trustedIPs'], "{$entryPoint}.{$kind}");
                    }
                    $this->assertFalse($trust['insecure']);
                }
            }
        }
    }

    public function test_rendered_args_match_the_traefik_chart(): void
    {
        $this->assertSame([], IngressTrust::renderedArgs([]));
        $this->assertSame([
            '--entryPoints.web.forwardedHeaders.trustedIPs=10.0.0.0/24,192.0.2.7',
            '--entryPoints.web.proxyProtocol.trustedIPs=10.0.0.0/24,192.0.2.7',
            '--entryPoints.websecure.forwardedHeaders.trustedIPs=10.0.0.0/24,192.0.2.7',
            '--entryPoints.websecure.proxyProtocol.trustedIPs=10.0.0.0/24,192.0.2.7',
        ], IngressTrust::renderedArgs(['10.0.0.0/24', '192.0.2.7']));

        // what regions before 0.4.1 run: trust from everyone
        $deployed = [
            '--entryPoints.web.address=:8000/tcp',
            '--entryPoints.web.proxyProtocol.trustedIPs=0.0.0.0/0',
            '--entryPoints.web.forwardedHeaders.trustedIPs=0.0.0.0/0',
            '--entryPoints.websecure.address=:8443/tcp',
            '--entryPoints.websecure.proxyProtocol.trustedIPs=0.0.0.0/0',
            '--entryPoints.websecure.forwardedHeaders.trustedIPs=0.0.0.0/0',
            '--entryPoints.websecure.http.tls=true',
            '--providers.knative',
        ];
        $current = IngressTrust::currentArgs($deployed);
        $this->assertCount(4, $current);
        $this->assertNotSame(IngressTrust::renderedArgs([]), $current);
        $this->assertSame('0.0.0.0/0', IngressTrust::describe($current));
        $this->assertSame('nobody', IngressTrust::describe([]));
        $this->assertSame('everyone (insecure)', IngressTrust::describe(['--entryPoints.web.forwardedHeaders.insecure']));

        // an up-to-date Traefik compares equal whatever the arg order
        $this->assertSame(
            IngressTrust::renderedArgs(['192.0.2.7']),
            IngressTrust::currentArgs(array_reverse(array_merge(['--ping=true'], IngressTrust::renderedArgs(['192.0.2.7'])))),
        );
    }

    public function test_helm_set_json_resets_both_lists_and_insecure(): void
    {
        $set = IngressTrust::helmSetJson([]);

        $this->assertCount(8, $set);
        $this->assertSame('[]', $set['ports.web.proxyProtocol.trustedIPs']);
        $this->assertSame('[]', $set['ports.websecure.forwardedHeaders.trustedIPs']);
        $this->assertSame('false', $set['ports.websecure.proxyProtocol.insecure']);
        $this->assertSame('["10.0.0.0/24"]', IngressTrust::helmSetJson(['10.0.0.0/24'])['ports.web.forwardedHeaders.trustedIPs']);
    }

    public function test_setting_values_are_normalized_and_validated(): void
    {
        $this->assertSame(['10.0.0.0/24', '192.0.2.7'], IngressTrust::normalize([' 192.0.2.7', '10.0.0.0/24', '192.0.2.7', '']));
        $this->assertSame([], IngressTrust::normalize(null));

        foreach (['192.0.2.7', '10.0.0.0/24', '0.0.0.0/0', '2001:db8::/32', '::1', '100.64.0.0/10'] as $valid) {
            $this->assertTrue(IpOrCidr::valid($valid), $valid);
        }
        foreach (['10.0.0.0/33', '2001:db8::/129', '10.0.0/24', 'lb.example.test', '10.0.0.0/', '10.0.0.0/2a', '*'] as $invalid) {
            $this->assertFalse(IpOrCidr::valid($invalid), $invalid);
        }
    }

    public function test_only_runrs_helm_release_is_recognised(): void
    {
        $deployment = fn (string $release, string $ns) => ['metadata' => [
            'annotations' => ['meta.helm.sh/release-name' => $release, 'meta.helm.sh/release-namespace' => $ns],
            'labels' => ['helm.sh/chart' => 'traefik-40.3.0'],
        ]];

        $this->assertTrue(IngressTrust::isRunrRelease($deployment('traefik', 'knative-serving'), 'knative-serving'));
        $this->assertFalse(IngressTrust::isRunrRelease($deployment('traefik', 'kube-system'), 'knative-serving'));
        $this->assertFalse(IngressTrust::isRunrRelease($deployment('platform-ingress', 'knative-serving'), 'knative-serving'));
    }
}
