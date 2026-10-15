<?php

namespace Tests\Feature;

use App\Jobs\InstallRedisJob;
use App\Models\KnativeRegion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Region option to reuse an already-running Prometheus/Loki instead of installing
 * RunR's own kube-prometheus-stack / loki-stack (e.g. a platform-managed cluster
 * that already runs a platform monitoring stack). Supplying prometheus_url/loki_url at
 * region creation stores them and marks that component unmanaged; installPrometheus()/
 * installLoki() only ever run later, from installServing() — a separate install step —
 * so region creation itself never calls them regardless of these fields.
 * See PatchClusterObservabilityCommandTest for the "leave an unmanaged stack alone" side.
 */
class RegionMonitoringReuseTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    /** Cluster API: version 1.31, the project namespace does not exist yet, every write succeeds. */
    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/version') {
                return Http::response(['major' => '1', 'minor' => '31']);
            }
            if ($request->method() === 'GET' && str_starts_with($path, '/api/v1/namespaces/')) {
                return Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            return Http::response(['kind' => 'Namespace', 'metadata' => ['name' => 'fn']]);
        });
    }

    public function test_store_with_external_urls_marks_them_unmanaged(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/knative-regions', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'redis_mode' => 'none',
            'prometheus_url' => 'http://platform-prometheus.monitoring.svc:9090',
            'loki_url' => 'http://platform-loki.monitoring.svc:3100',
        ])->assertCreated();

        $region = KnativeRegion::where('name', 'fn')->first();
        $this->assertSame('http://platform-prometheus.monitoring.svc:9090', $region->prometheus_url);
        $this->assertFalse($region->prometheus_managed);
        $this->assertSame('http://platform-loki.monitoring.svc:3100', $region->loki_url);
        $this->assertFalse($region->loki_managed);
        // No RunR-owned pods will ever appear for KnativeStatusService to promote — set eagerly.
        $this->assertSame('ready', $region->loki_status);
    }

    public function test_store_without_urls_defaults_both_to_managed(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/knative-regions', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'redis_mode' => 'none',
        ])->assertCreated();

        $region = KnativeRegion::where('name', 'fn')->first();
        $this->assertNull($region->prometheus_url);
        $this->assertTrue($region->prometheus_managed);
        $this->assertNull($region->loki_url);
        $this->assertTrue($region->loki_managed);
        $this->assertNull($region->loki_status);
    }

    public function test_store_rejects_a_malformed_prometheus_url(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/knative-regions', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'redis_mode' => 'none',
            'prometheus_url' => 'not-a-url',
        ])->assertUnprocessable()->assertJsonValidationErrors('prometheus_url');

        Bus::assertNotDispatched(InstallRedisJob::class);
    }

    public function test_prometheus_and_loki_reuse_are_independent(): void
    {
        Bus::fake([InstallRedisJob::class]);
        $this->fakeCluster();
        $user = User::factory()->create(['username' => 'admin']);

        $this->actingAs($user, 'sanctum')->postJson('/api/knative-regions', [
            'name' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'nodes' => [],
            'redis_mode' => 'none',
            'prometheus_url' => 'http://platform-prometheus.monitoring.svc:9090',
            // loki_url intentionally omitted — Loki should stay RunR-managed.
        ])->assertCreated();

        $region = KnativeRegion::where('name', 'fn')->first();
        $this->assertFalse($region->prometheus_managed);
        $this->assertTrue($region->loki_managed);
        $this->assertNull($region->loki_url);
    }
}
