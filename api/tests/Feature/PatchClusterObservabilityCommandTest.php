<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * cluster:patch-observability re-applies Prometheus/Loki Helm values on existing regions.
 * A region whose Prometheus/Loki was supplied externally (prometheus_managed / loki_managed
 * = false — see RegionMonitoringReuseTest) has no RunR-owned Helm release to patch, so the
 * command must skip it rather than Helm-installing kube-prometheus-stack/loki-stack into a
 * cluster that never had RunR's own observability stack in the first place.
 */
class PatchClusterObservabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    /** Every K8s API call (agent deploy, etc.) succeeds; nothing here exercises the real helm binary. */
    private function fakeCluster(): void
    {
        Http::fake(fn () => Http::response(['kind' => 'Status', 'metadata' => ['name' => 'ok']], 200));
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
        ], $attributes));
    }

    public function test_skips_prometheus_and_loki_patch_for_an_externally_supplied_stack(): void
    {
        $this->fakeCluster();
        $region = $this->region([
            'prometheus_url' => 'http://platform-prometheus.monitoring:9090',
            'prometheus_managed' => false,
            'loki_url' => 'http://platform-loki.monitoring:3100',
            'loki_managed' => false,
        ]);

        Artisan::call('cluster:patch-observability', ['regionId' => $region->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Prometheus not RunR-managed', $output);
        $this->assertStringContainsString('Loki not RunR-managed', $output);
        $this->assertStringNotContainsString('Prometheus patched', $output);
        $this->assertStringNotContainsString('Loki patched', $output);

        // Untouched — the command never called installPrometheus()/installLoki() for them.
        $fresh = $region->fresh();
        $this->assertSame('http://platform-prometheus.monitoring:9090', $fresh->prometheus_url);
        $this->assertSame('http://platform-loki.monitoring:3100', $fresh->loki_url);
    }
}
