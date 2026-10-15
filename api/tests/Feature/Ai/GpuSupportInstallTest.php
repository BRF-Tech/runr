<?php

namespace Tests\Feature\Ai;

use App\Console\Commands\GpuRefreshCommand;
use App\Jobs\Ai\InstallGpuSupportJob;
use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\HelmResult;
use App\Services\HelmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Installing GPU support on a region: the device plugin chart (GFD / NFD, time-slicing,
 * RuntimeClass on k3s) or the GPU Operator through Helm, the nvidia RuntimeClass, the Knative
 * pod spec features, the inventory refresh and the install log; uninstall; the GPU endpoints
 * (queued install, validation, settings, model volume) and the gpu:refresh schedule.
 */
class GpuSupportInstallTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private function k3sNode(string $name, int $gpus): array
    {
        return $this->gpuNode($name, $gpus, [], ['status' => ['nodeInfo' => ['containerRuntimeVersion' => 'containerd://2.0.4-k3s2', 'kubeletVersion' => 'v1.32.3+k3s1']]]);
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'gpu', 'project_namespace' => 'fn', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
        ], $attributes));
    }

    private function helm(array &$installs): void
    {
        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('repoAdd')->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('repoUpdate')->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('install')->andReturnUsing(function (...$args) use (&$installs) {
            $installs[] = $args;

            return new HelmResult(true, 'deployed', '', 0);
        });
        $helm->shouldReceive('uninstall')->andReturn(new HelmResult(true, 'release uninstalled', '', 0));
        $this->app->instance(HelmService::class, $helm);
    }

    public function test_the_device_plugin_is_installed_with_time_slicing_on_k3s(): void
    {
        $installs = [];
        $this->helm($installs);
        $this->fakeCluster([
            'GET /api/v1/nodes' => ['items' => [$this->k3sNode('gpu-a', 4)]],
            'GET /api/v1/pods' => ['items' => []],
            'GET /apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving' => ['metadata' => ['name' => 'knative-serving']],
        ]);
        $region = $this->region();

        app(GpuInstallService::class)->install($region, 'device-plugin', ['time_slicing_replicas' => 4, 'runtime_class' => 'auto']);

        $this->assertCount(1, $installs);
        [$release, $chart, $namespace, , , $version, $values, , $wait] = $installs[0];
        $this->assertSame('nvidia-device-plugin', $release);
        $this->assertSame('nvdp/nvidia-device-plugin', $chart);
        $this->assertSame('runr-gpu', $namespace);
        $this->assertSame('0.17.0', $version);
        $this->assertFalse($wait);
        $this->assertSame('nvidia', $values['runtimeClassName'], 'k3s: the plugin needs the NVIDIA runtime');
        $this->assertStringContainsString('replicas: 4', $values['config']['map']['default']);
        $this->assertSame(['nvidia.com/gpu.present' => 'true'], $values['nodeSelector'], 'GPU nodes only, by default');

        // the detected GPU node gets the label the plugin selects
        $label = $this->k8sCallsTo('PATCH', '/api/v1/nodes/gpu-a');
        $this->assertSame('true', $label[0]['body']['metadata']['labels']['nvidia.com/gpu.present']);

        $rc = $this->k8sCallsTo('PATCH', '/apis/node.k8s.io/v1/runtimeclasses/nvidia');
        $this->assertSame('nvidia', $rc[0]['body']['handler']);

        $features = $this->k8sCallsTo('PATCH', '/knativeservings/knative-serving')[0]['body']['spec']['config']['features'];
        $this->assertSame('enabled', $features['kubernetes.podspec-affinity']);
        $this->assertSame('enabled', $features['kubernetes.podspec-persistent-volume-claim']);

        $region->refresh();
        $this->assertSame('device-plugin', $region->gpu_mode);
        $this->assertSame('ready', $region->gpu_status);
        $this->assertSame('nvidia', $region->gpu_settings['runtime_class']);
        $this->assertSame(4, $region->gpu_settings['time_slicing_replicas']);
        $this->assertSame(4, $region->gpu_available['summary']['allocatable']);
        $this->assertStringContainsString('GPU support ready: 4 GPU(s) on 1 node(s).', $region->gpu_status_message);
    }

    public function test_kind_workers_without_the_gpu_do_not_get_the_plugin(): void
    {
        $installs = [];
        $this->helm($installs);
        $pci = fn (string $name) => array_replace_recursive($this->cpuNode($name), ['metadata' => ['labels' => ['feature.node.kubernetes.io/pci-10de.present' => 'true']]]);
        $this->fakeCluster([
            // every Kind worker shares the host's PCI view
            'GET /api/v1/nodes' => ['items' => [$pci('kind-worker'), $pci('kind-worker2'), $pci('kind-worker3')]],
            'GET /api/v1/pods' => ['items' => []],
        ]);
        $region = $this->region();

        app(GpuInstallService::class)->install($region, 'device-plugin', ['gpu_nodes' => ['kind-worker3'], 'runtime_class' => 'none']);

        $patched = array_map(fn ($c) => basename($c['path']), $this->k8sCallsTo('PATCH', '/api/v1/nodes/'));
        $this->assertSame(['kind-worker3'], $patched, 'only the chosen GPU node is labelled');
        $this->assertSame(['nvidia.com/gpu.present' => 'true'], $installs[0][6]['nodeSelector']);
        $this->assertSame(['kind-worker3'], $region->fresh()->gpu_settings['gpu_nodes']);
        $this->assertStringContainsString('GPU nodes: kind-worker3', $region->fresh()->gpu_status_message);
    }

    public function test_the_restriction_can_be_switched_off(): void
    {
        $installs = [];
        $this->helm($installs);
        $this->fakeCluster(['GET /api/v1/nodes' => ['items' => [$this->gpuNode('gpu-a', 1)]], 'GET /api/v1/pods' => ['items' => []]]);
        $region = $this->region();

        app(GpuInstallService::class)->install($region, 'device-plugin', ['restrict_to_gpu_nodes' => false]);

        $this->assertArrayNotHasKey('nodeSelector', $installs[0][6]);
        $this->assertEmpty($this->k8sCallsTo('PATCH', '/api/v1/nodes/'));
        $this->assertFalse($region->fresh()->gpu_settings['restrict_to_gpu_nodes']);
    }

    public function test_gpu_refresh_labels_gpu_nodes_found_after_the_install(): void
    {
        $nfdNode = array_replace_recursive($this->cpuNode('late-gpu'), ['metadata' => ['labels' => ['feature.node.kubernetes.io/pci-10de.present' => 'true']]]);
        $this->fakeCluster(['GET /api/v1/nodes' => ['items' => [$nfdNode, $this->cpuNode('cpu')]], 'GET /api/v1/pods' => ['items' => []]]);
        $region = $this->region(['gpu_mode' => 'device-plugin', 'gpu_status' => 'ready', 'gpu_settings' => ['restrict_to_gpu_nodes' => true]]);

        app(GpuInstallService::class)->syncNodeLabels($region);

        $this->assertSame(['late-gpu'], array_map(fn ($c) => basename($c['path']), $this->k8sCallsTo('PATCH', '/api/v1/nodes/')));

        // chosen nodes: nothing is added automatically
        $this->k8sCalls = [];
        $region->update(['gpu_settings' => ['restrict_to_gpu_nodes' => true, 'gpu_nodes' => ['cpu']]]);
        app(GpuInstallService::class)->syncNodeLabels($region->fresh());
        $this->assertSame(['cpu'], array_map(fn ($c) => basename($c['path']), $this->k8sCallsTo('PATCH', '/api/v1/nodes/')));
    }

    public function test_the_gpu_operator_installs_drivers_and_the_toolkit(): void
    {
        $installs = [];
        $this->helm($installs);
        $this->fakeCluster([
            'GET /api/v1/nodes' => ['items' => [$this->gpuNode('gpu-a', 1)]],
            'GET /api/v1/pods' => ['items' => []],
        ]);
        $region = $this->region();

        app(GpuInstallService::class)->install($region, 'operator', ['operator_driver' => false, 'time_slicing_replicas' => 2]);

        [$release, $chart, , , , $version, $values] = $installs[0];
        $this->assertSame('gpu-operator', $release);
        $this->assertSame('nvidia/gpu-operator', $chart);
        $this->assertSame('v24.9.2', $version);
        $this->assertFalse($values['driver']['enabled']);
        $this->assertTrue($values['toolkit']['enabled']);
        $this->assertSame('runr-time-slicing', $values['devicePlugin']['config']['name']);
        $this->assertNotEmpty($this->k8sCallsTo('PATCH', '/namespaces/runr-gpu/configmaps/runr-time-slicing'));
        $this->assertEmpty($this->k8sCallsTo('PATCH', '/runtimeclasses/nvidia'), 'the operator creates its RuntimeClass');
        $this->assertSame('nvidia', $region->fresh()->gpu_settings['runtime_class']);
    }

    public function test_a_failed_helm_install_marks_the_region(): void
    {
        $helm = Mockery::mock(HelmService::class);
        $helm->shouldReceive('repoAdd', 'repoUpdate')->andReturn(new HelmResult(true, '', '', 0));
        $helm->shouldReceive('install')->andReturn(new HelmResult(false, '', 'Error: chart not found', 1));
        $this->app->instance(HelmService::class, $helm);
        $this->fakeCluster(['GET /api/v1/nodes' => ['items' => [$this->gpuNode('gpu-a', 1)]]]);
        $region = $this->region();

        try {
            app(GpuInstallService::class)->install($region, 'device-plugin');
            $this->fail('install did not fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('chart not found', $e->getMessage());
        }

        $this->assertSame('error', $region->fresh()->gpu_status);
        $this->assertStringContainsString('ERROR: helm install nvidia-device-plugin failed', $region->fresh()->gpu_status_message);
    }

    public function test_uninstall_removes_the_release_and_the_runtime_class(): void
    {
        $installs = [];
        $this->helm($installs);
        $region = $this->region(['gpu_mode' => 'device-plugin', 'gpu_status' => 'ready', 'gpu_settings' => ['runtime_class' => 'nvidia']]);
        $labelled = array_replace_recursive($this->cpuNode('was-gpu'), ['metadata' => [
            'labels' => ['nvidia.com/gpu.present' => 'true'],
            'annotations' => ['runr.dev/gpu-node-label' => 'true'],
        ]]);
        $this->fakeCluster(['GET /api/v1/nodes' => ['items' => [$labelled, $this->cpuNode('cpu')]], 'GET /api/v1/pods' => ['items' => []]]);

        app(GpuInstallService::class)->uninstall($region);

        $cleanup = $this->k8sCallsTo('PATCH', '/api/v1/nodes/was-gpu');
        $this->assertSame(['nvidia.com/gpu.present' => null], $cleanup[0]['body']['metadata']['labels'], 'the label RunR set is removed');

        $region->refresh();
        $this->assertSame('none', $region->gpu_mode);
        $this->assertSame('not_installed', $region->gpu_status);
        $this->assertNull($region->gpu_settings['runtime_class']);
        $this->assertNotEmpty($this->k8sCallsTo('DELETE', '/runtimeclasses/nvidia'));
    }

    public function test_the_gpu_endpoints(): void
    {
        Queue::fake();
        $this->fakeCluster();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $region = $this->region();
        $region->forceFill(['gpu_available' => [
            'nodes' => app(GpuInventory::class)->fromNodes([$this->gpuNode('gpu-a', 2)])['nodes'],
            'summary' => app(GpuInventory::class)->fromNodes([$this->gpuNode('gpu-a', 2)])['summary'],
            'detected_at' => now()->toIso8601String(),
        ]])->save();

        $this->getJson("/api/knative-regions/{$region->id}/gpu")
            ->assertOk()
            ->assertJsonPath('mode', 'none')
            ->assertJsonPath('summary.allocatable', 2)
            ->assertJsonPath('nodes.0.product', 'NVIDIA-L4')
            ->assertJsonPath('sandbox.caas.gpu', 'ok');

        $this->postJson("/api/knative-regions/{$region->id}/gpu/install", ['mode' => 'mig'])->assertStatus(422);
        $this->postJson("/api/knative-regions/{$region->id}/gpu/install", ['mode' => 'device-plugin', 'time_slicing_replicas' => 99])->assertStatus(422);
        $this->postJson("/api/knative-regions/{$region->id}/gpu/install", ['mode' => 'device-plugin', 'gpu_nodes' => ['Not A Node']])->assertStatus(422);

        $this->postJson("/api/knative-regions/{$region->id}/gpu/install", ['mode' => 'device-plugin', 'time_slicing_replicas' => 3])
            ->assertStatus(202)
            ->assertJsonPath('gpu.status', 'installing');
        Queue::assertPushed(InstallGpuSupportJob::class, fn ($job) => $job->action === 'install' && $job->mode === 'device-plugin' && $job->options['time_slicing_replicas'] === 3);
        $this->getJson("/api/knative-regions/{$region->id}/gpu")->assertOk()
            ->assertJsonPath('settings.restrict_to_gpu_nodes', true)
            ->assertJsonPath('settings.gpu_nodes', null);

        $this->postJson("/api/knative-regions/{$region->id}/gpu/install", ['mode' => 'device-plugin'])->assertStatus(422);

        // gVisor nvproxy is a RunR-side setting
        $region->update(['gpu_status' => 'ready', 'sandbox_caas_runtime' => 'gvisor']);
        $this->putJson("/api/knative-regions/{$region->id}/gpu/settings", ['gvisor_nvproxy' => true])
            ->assertOk()
            ->assertJsonPath('gpu.settings.gvisor_nvproxy', true)
            ->assertJsonPath('gpu.sandbox.caas.gpu', 'nvproxy');

        $this->putJson("/api/knative-regions/{$region->id}/model-storage", ['size_gi' => 200, 'access_mode' => 'ReadWriteOnce'])
            ->assertOk()
            ->assertJsonPath('size_gi', 200)
            ->assertJsonPath('access_mode', 'ReadWriteOnce');
    }

    public function test_demo_regions_show_a_gpu_node_when_the_gpu_demo_is_on(): void
    {
        $demo = KnativeRegion::create(['name' => 'demo', 'project_namespace' => 'demo', 'is_active' => true, 'is_demo' => true]);

        $this->assertNull(app(GpuInventory::class)->refresh($demo));

        Setting::set('gpu_demo_enabled', 'true');
        $inventory = app(GpuInventory::class)->refresh($demo);
        $this->assertSame(1, $inventory['summary']['allocatable']);
        $this->assertSame('external', $demo->fresh()->gpu_mode);
    }

    public function test_refresh_is_due_for_gpu_regions_and_idle_ones_now_and_then(): void
    {
        $gpu = $this->region(['gpu_mode' => 'device-plugin']);
        $idle = $this->region(['name' => 'idle', 'gpu_available' => ['summary' => ['total' => 0], 'detected_at' => now()->subMinutes(5)->toIso8601String()]]);
        $old = $this->region(['name' => 'old', 'gpu_available' => ['summary' => ['total' => 0], 'detected_at' => now()->subHours(2)->toIso8601String()]]);

        $this->assertTrue(GpuRefreshCommand::due($gpu, 30));
        $this->assertFalse(GpuRefreshCommand::due($idle, 30));
        $this->assertTrue(GpuRefreshCommand::due($old, 30));
    }
}
