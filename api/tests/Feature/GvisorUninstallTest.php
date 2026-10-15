<?php

namespace Tests\Feature;

use App\Exceptions\SandboxInUseException;
use App\Models\KnativeRegion;
use App\Services\SandboxInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * gVisor uninstall through RunR also cleans the nodes (cleanup DaemonSet), refuses while
 * sandboxes run, and reports a node that could not be cleaned.
 */
class GvisorUninstallTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    /** @var list<string> "METHOD path" of every API call */
    private array $calls = [];

    private function region(): KnativeRegion
    {
        return KnativeRegion::create([
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
            'sandbox_gvisor_status' => 'ready',
            'sandbox_xaas_runtime' => 'gvisor',
            'sandbox_caas_runtime' => 'runc',
        ]);
    }

    /**
     * @param  array  $clusterPods  pods returned for the cluster-wide pod list
     * @param  array|null  $cleanupPod  pod of the cleanup DaemonSet (after it was applied)
     */
    private function fakeCluster(array $clusterPods = [], ?array $cleanupPod = null, bool $cleanupReady = true): void
    {
        $applied = false;

        Http::fake(function (Request $request) use ($clusterPods, $cleanupPod, $cleanupReady, &$applied) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $this->calls[] = "{$method} {$path}";

            if ($method === 'PATCH' && $path === '/apis/apps/v1/namespaces/runr-sandbox/daemonsets/gvisor-cleanup') {
                $applied = true;
            }

            return match (true) {
                $method === 'GET' && $path === '/api/v1/pods' => Http::response(['items' => $clusterPods]),
                $method === 'GET' && $path === '/api/v1/nodes' => Http::response(['items' => [[
                    'metadata' => ['name' => 'node-0', 'labels' => ['kubernetes.io/os' => 'linux']],
                    'status' => ['nodeInfo' => ['containerRuntimeVersion' => 'containerd://2.1.6', 'kubeletVersion' => 'v1.35.3']],
                ]]]),
                $method === 'GET' && $path === '/api/v1/namespaces/runr-sandbox/pods' => Http::response(['items' => ($applied && $cleanupPod) ? [$cleanupPod] : []]),
                $method === 'GET' && $path === '/apis/apps/v1/namespaces/runr-sandbox/daemonsets/gvisor-cleanup' => Http::response(['status' => ['desiredNumberScheduled' => 3, 'numberReady' => $cleanupReady ? 3 : 1]]),
                in_array($method, ['PATCH', 'DELETE', 'POST', 'PUT'], true) => Http::response(['kind' => 'Status', 'status' => 'Success']),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });
    }

    public function test_uninstall_cleans_the_nodes_and_removes_the_cleanup_daemonset(): void
    {
        $region = $this->region();
        $this->fakeCluster();

        app(SandboxInstallService::class)->uninstallGvisor($region);

        $region->refresh();
        $this->assertSame('not_installed', $region->sandbox_gvisor_status);
        $this->assertSame('runc', $region->sandbox_xaas_runtime);

        $this->assertContains('DELETE /apis/node.k8s.io/v1/runtimeclasses/gvisor', $this->calls);
        $this->assertContains('DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/gvisor-installer', $this->calls);
        $applyAt = array_search('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/gvisor-cleanup', $this->calls, true);
        $this->assertNotFalse($applyAt, 'cleanup DaemonSet applied');
        // the RuntimeClass goes before the nodes are touched, the cleanup DaemonSet after they are clean
        $this->assertLessThan($applyAt, array_search('DELETE /apis/node.k8s.io/v1/runtimeclasses/gvisor', $this->calls, true));
        $this->assertSame(
            'DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/gvisor-cleanup',
            collect($this->calls)->last(fn ($c) => str_contains($c, 'gvisor-cleanup')),
        );
    }

    public function test_uninstall_is_refused_while_pods_run_under_gvisor(): void
    {
        $region = $this->region();
        $this->fakeCluster([[
            'metadata' => ['namespace' => 'fn', 'name' => 'hello-python-sandbox-acme-00001-deployment-abc'],
            'spec' => ['runtimeClassName' => 'gvisor'],
            'status' => ['phase' => 'Running'],
        ], [
            // a sandboxed pod that never started does not block
            'metadata' => ['namespace' => 'fn', 'name' => 'stuck'],
            'spec' => ['runtimeClassName' => 'gvisor'],
            'status' => ['phase' => 'Pending'],
        ]]);

        try {
            app(SandboxInstallService::class)->uninstallGvisor($region);
            $this->fail('uninstall must be refused');
        } catch (SandboxInUseException $e) {
            $this->assertStringContainsString('1 running pod(s) still use gVisor (fn/hello-python-sandbox-acme-00001-deployment-abc)', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_gvisor_status);
        $this->assertStringStartsWith('Uninstall refused:', $region->sandbox_gvisor_status_message);
        $this->assertSame('gvisor', $region->sandbox_xaas_runtime, 'mappings untouched');
        $this->assertEmpty(array_filter($this->calls, fn ($c) => ! str_starts_with($c, 'GET ')), 'nothing changed on the cluster');
    }

    public function test_a_node_that_cannot_be_cleaned_is_reported(): void
    {
        $region = $this->region();
        $this->fakeCluster([], [
            'metadata' => ['name' => 'gvisor-cleanup-x1'],
            'status' => ['containerStatuses' => [[
                'lastState' => ['terminated' => [
                    'exitCode' => 1,
                    'message' => 'runr: ERROR: containerd rejects /etc/containerd/config.toml.runr-new: bad — config.toml left unchanged, nothing restarted (node node-1)',
                ]],
            ]]],
        ], cleanupReady: false);

        try {
            app(SandboxInstallService::class)->uninstallGvisor($region);
            $this->fail('uninstall must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('gVisor node cleanup failed: containerd rejects', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('error', $region->sandbox_gvisor_status);
        $this->assertStringContainsString('left unchanged', $region->sandbox_gvisor_status_message);
        $this->assertSame(
            'DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/gvisor-cleanup',
            collect($this->calls)->last(fn ($c) => str_contains($c, 'gvisor-cleanup')),
            'the failing cleanup DaemonSet is removed (no crash loop)',
        );
    }
}
