<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\RegionNamespaceQuotaService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\NamespaceQuotaStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * region:reconcile keeps the organization ceiling (quota.cpu / quota.memoryGiB) in the project
 * namespace of existing regions: applied when configured, left alone when already matching,
 * RunR's own objects removed when the ceiling is cleared.
 */
class NamespaceQuotaReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array> objects the fake cluster holds, keyed by "Kind/name" */
    private array $objects = [];

    /** @var list<string> */
    private array $writes = [];

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://q.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();

            $kind = str_contains($path, '/resourcequotas/') ? 'ResourceQuota' : (str_contains($path, '/limitranges/') ? 'LimitRange' : null);
            $name = basename($path);

            if ($kind && $method === 'GET') {
                return isset($this->objects["{$kind}/{$name}"])
                    ? Http::response($this->objects["{$kind}/{$name}"])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }

            if ($kind && in_array($method, ['PATCH', 'PUT', 'POST'], true)) {
                $this->writes[] = "{$method} {$kind}/{$name}";
                $this->objects["{$kind}/{$name}"] = json_decode($request->body(), true);

                return Http::response($this->objects["{$kind}/{$name}"]);
            }

            if ($kind && $method === 'DELETE') {
                $this->writes[] = "DELETE {$kind}/{$name}";
                unset($this->objects["{$kind}/{$name}"]);

                return Http::response(['kind' => 'Status'], 200);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });
    }

    private function cluster(): ClusterGroup
    {
        $region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
        ]);

        return new ClusterGroup('uid-q', [new ReconcileTarget($region)], new KubernetesClient($region->kubeconfig));
    }

    public function test_applies_the_ceiling_then_reports_nothing_on_the_next_run(): void
    {
        config(['quota.cpu' => '8', 'quota.memory_gib' => '16']);
        $this->fakeCluster();
        $cluster = $this->cluster();
        $step = new NamespaceQuotaStep;

        $lines = $step->run($cluster, false);
        $this->assertSame(['applied the organization ceiling in namespace fn'], $lines);
        $this->assertArrayHasKey('ResourceQuota/'.RegionNamespaceQuotaService::RESOURCE_QUOTA_NAME, $this->objects);
        $this->assertArrayHasKey('LimitRange/'.RegionNamespaceQuotaService::LIMIT_RANGE_NAME, $this->objects);

        // The API server may normalise quantities ("8000m" → "8"); still up to date.
        $this->objects['ResourceQuota/'.RegionNamespaceQuotaService::RESOURCE_QUOTA_NAME]['spec']['hard']['requests.cpu'] = '8';
        $this->writes = [];
        $this->assertSame([], $step->run($cluster, false));
        $this->assertSame([], $this->writes);
    }

    public function test_a_0_4_1_ceiling_on_cpu_limits_is_dropped(): void
    {
        config(['quota.cpu' => '8', 'quota.memory_gib' => '16']);
        $this->fakeCluster();
        $cluster = $this->cluster();
        $step = new NamespaceQuotaStep;
        $step->run($cluster, false);

        // RunR ≤ 0.4.1 also capped limits.cpu — Kata's queue-proxy burst would count there as reserved
        $this->objects['ResourceQuota/'.RegionNamespaceQuotaService::RESOURCE_QUOTA_NAME]['spec']['hard']['limits.cpu'] = '8';
        $this->writes = [];

        $this->assertSame(['applied the organization ceiling in namespace fn'], $step->run($cluster, false));
        $this->assertSame(
            ['requests.cpu' => '8000m', 'requests.memory' => '16384Mi', 'limits.memory' => '16384Mi'],
            $this->objects['ResourceQuota/'.RegionNamespaceQuotaService::RESOURCE_QUOTA_NAME]['spec']['hard'],
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        config(['quota.cpu' => '4', 'quota.memory_gib' => null]);
        $this->fakeCluster();

        $lines = (new NamespaceQuotaStep)->run($this->cluster(), true);

        $this->assertSame(['would apply the organization ceiling in namespace fn'], $lines);
        $this->assertSame([], $this->writes);
    }

    public function test_cleared_ceiling_removes_only_runr_objects(): void
    {
        config(['quota.cpu' => null, 'quota.memory_gib' => null]);
        $this->fakeCluster();
        $managed = ['metadata' => ['labels' => ['app.kubernetes.io/managed-by' => 'runr']]];
        $this->objects['ResourceQuota/'.RegionNamespaceQuotaService::RESOURCE_QUOTA_NAME] = $managed;
        $this->objects['LimitRange/'.RegionNamespaceQuotaService::LIMIT_RANGE_NAME] = ['metadata' => ['labels' => []]];

        $lines = (new NamespaceQuotaStep)->run($this->cluster(), false);

        $this->assertSame(['removed the organization ceiling in namespace fn'], $lines);
        $this->assertSame(['DELETE ResourceQuota/'.RegionNamespaceQuotaService::RESOURCE_QUOTA_NAME], $this->writes);
    }

    public function test_no_ceiling_and_nothing_managed_is_up_to_date(): void
    {
        config(['quota.cpu' => null, 'quota.memory_gib' => null]);
        $this->fakeCluster();

        $this->assertSame([], (new NamespaceQuotaStep)->run($this->cluster(), false));
        $this->assertSame([], $this->writes);
    }

    public function test_quantities_compare_by_value(): void
    {
        $this->assertTrue(NamespaceQuotaStep::sameQuantities(['cpu' => '8'], ['cpu' => '8000m']));
        $this->assertTrue(NamespaceQuotaStep::sameQuantities(['memory' => '1Gi'], ['memory' => '1024Mi']));
        $this->assertFalse(NamespaceQuotaStep::sameQuantities(['cpu' => '4'], ['cpu' => '8000m']));
        $this->assertFalse(NamespaceQuotaStep::sameQuantities(['cpu' => '8'], ['cpu' => '8', 'memory' => '1Gi']));
    }
}
