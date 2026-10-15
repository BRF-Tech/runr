<?php

namespace Tests\Feature\Wasm;

use App\Jobs\InstallWasmJob;
use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\KnativeNodeService;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\WasmSandboxStep;
use App\Services\Wasm\WasmInstallService;
use App\Services\Wasm\WasmKnativeFeatures;
use App\Services\Wasm\WasmManifests;
use App\Services\Wasm\WasmShim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesWasmCluster;
use Tests\TestCase;

/**
 * The Knative features of Wasm functions (podspec-runtimeclassname, podspec-nodeselector): the
 * merge patch (CR or ConfigMap), kept on worker changes, and region:reconcile's WasmSandboxStep
 * that keeps the features, the shims on the nodes (an installer of an earlier release is re-run
 * with this release's shims) and the RuntimeClasses of a ready install current.
 */
class WasmKnativeFeaturesTest extends TestCase
{
    use FakesWasmCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeWasmCluster();
    }

    private function k8s(): KubernetesClient
    {
        return new KubernetesClient($this->wasmKubeconfig());
    }

    /** The wasm-installer DaemonSet as RunR applied it ($shimLines: WASM_SHIMS of another release). */
    private function liveInstaller(array $shimIds, ?string $shimLines = null): void
    {
        $runtime = ContainerRuntimeHelper::resolveCluster([[
            'metadata' => ['name' => 'worker-1'],
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => 'containerd://2.1.4', 'kubeletVersion' => 'v1.34.1']],
        ]]);
        $ds = WasmManifests::installerDaemonSet(array_map([WasmShim::class, 'get'], $shimIds), $runtime);
        if ($shimLines !== null) {
            unset($ds['spec']['template']['spec']['initContainers']);
            foreach ($ds['spec']['template']['spec']['containers'][0]['env'] as &$env) {
                if ($env['name'] === 'WASM_SHIMS') {
                    $env['value'] = $shimLines;
                }
            }
            unset($env);
        }
        $this->daemonSets[WasmManifests::INSTALLER_DS] = $ds;
    }

    /** WASM_SHIMS of an install before RunR's Spin shim build: the upstream containerd-shim-spin v0.26.0 release tarballs. */
    private const UPSTREAM_SPIN_LINE = 'spin containerd-shim-spin-v2 spin-runr io.containerd.spin.v2 '
        .'https://github.com/spinframework/containerd-shim-spin/releases/download/v0.26.0/containerd-shim-spin-v2-linux-x86_64.tar.gz '
        .'392945ca82c11abc7006a32c4e5dc12821954b5e2c35cb28e924aeea6bad5286 '
        .'https://github.com/spinframework/containerd-shim-spin/releases/download/v0.26.0/containerd-shim-spin-v2-linux-aarch64.tar.gz '
        .'dfe430d65f9b67b4b82470a1f5e390b4ff396bb3c917e189e1ed375614e6deb5';

    public function test_only_the_two_keys_are_merge_patched_into_the_cr(): void
    {
        $this->servingFeatures = ['kubernetes.podspec-init-containers' => 'enabled', 'kubernetes.podspec-runtimeclassname' => 'enabled'];

        $this->assertSame(['kubernetes.podspec-nodeselector' => 'enabled'], WasmKnativeFeatures::missing($this->k8s(), 'knative-serving'));
        $changed = WasmKnativeFeatures::enable($this->k8s(), 'knative-serving');

        $this->assertSame(['kubernetes.podspec-nodeselector' => 'enabled'], $changed);
        $patch = $this->lastBody('PATCH', '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving');
        $this->assertSame(['spec' => ['config' => ['features' => WasmKnativeFeatures::FEATURES]]], $patch);
        $this->assertSame('enabled', $this->servingFeatures['kubernetes.podspec-init-containers'], 'other features stay');
        $this->assertSame([], WasmKnativeFeatures::enable($this->k8s(), 'knative-serving'), 'idempotent');
    }

    public function test_without_the_operator_the_configmap_gets_them(): void
    {
        $this->servingFeatures = null;
        $this->configFeatures = ['kubernetes.podspec-nodeselector' => 'disabled'];

        WasmKnativeFeatures::enable($this->k8s(), 'knative-serving');

        $this->assertSame('enabled', $this->configFeatures['kubernetes.podspec-nodeselector']);
        $this->assertSame('enabled', $this->configFeatures['kubernetes.podspec-runtimeclassname']);
    }

    public function test_deselecting_the_dedicated_workers_keeps_nodeselector_for_wasm_regions(): void
    {
        $nodes = app(KnativeNodeService::class);

        $nodes->disablePodSpecFeatures($this->k8s(), 'knative-serving', true);
        $kept = $this->lastBody('PATCH', '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving');
        $this->assertSame('enabled', $kept['spec']['config']['features']['kubernetes.podspec-nodeselector']);
        $this->assertSame('disabled', $kept['spec']['config']['features']['kubernetes.podspec-tolerations']);

        $nodes->disablePodSpecFeatures($this->k8s(), 'knative-serving');
        $default = $this->lastBody('PATCH', '/apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving');
        $this->assertSame('disabled', $default['spec']['config']['features']['kubernetes.podspec-nodeselector']);

        $this->assertTrue(WasmKnativeFeatures::regionNeedsNodeSelector($this->wasmRegion(['name' => 'a', 'sandbox_wasm_status' => 'ready'])));
        $this->assertTrue(WasmKnativeFeatures::regionNeedsNodeSelector($this->wasmRegion(['name' => 'b', 'sandbox_wasm_status' => 'installing'])));
        $this->assertFalse(WasmKnativeFeatures::regionNeedsNodeSelector($this->wasmRegion(['name' => 'c'])->refresh()));
        $this->assertFalse(WasmKnativeFeatures::regionNeedsNodeSelector(null));
    }

    public function test_the_reconcile_step_restores_features_and_runtime_classes_of_a_ready_install(): void
    {
        $region = $this->wasmRegion(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);
        $cluster = new ClusterGroup('uid:wasm', [new ReconcileTarget($region)], $this->k8s());
        $this->servingFeatures = ['kubernetes.podspec-runtimeclassname' => 'enabled', 'kubernetes.podspec-nodeselector' => 'disabled'];
        // an install of an earlier release: RunR's RuntimeClass without the nodeSelector
        $this->runtimeClasses['wasmtime-spin-v2'] = ['metadata' => ['name' => 'wasmtime-spin-v2', 'labels' => ['app.kubernetes.io/managed-by' => 'runr']], 'handler' => 'spin-runr'];
        $this->liveInstaller(['spin']);

        $dry = (new WasmSandboxStep)->run($cluster, true);
        $this->assertSame([
            'would enable Knative features in knative-serving: kubernetes.podspec-nodeselector',
            'would sync RuntimeClass wasmtime-spin-v2 (handler spin-runr, nodeSelector runr.dev/wasm-spin=true)',
        ], $dry);
        $this->assertSame('disabled', $this->servingFeatures['kubernetes.podspec-nodeselector'], 'dry run writes nothing');

        $lines = (new WasmSandboxStep)->run($cluster, false);
        $this->assertCount(2, $lines);
        $this->assertSame('enabled', $this->servingFeatures['kubernetes.podspec-nodeselector']);
        $this->assertSame(WasmManifests::runtimeClass(WasmShim::get('spin'))['scheduling'], $this->runtimeClasses['wasmtime-spin-v2']['scheduling']);

        $this->assertSame([], (new WasmSandboxStep)->run($cluster, false), 'nothing left to do');
    }

    public function test_an_installer_of_an_earlier_release_is_re_run_with_this_releases_shims(): void
    {
        Queue::fake();
        $this->addNode('worker-1', ['runr.dev/wasm' => 'true', 'runr.dev/wasm-spin' => 'true', 'runr.dev/wasm-target' => 'true']);
        $region = $this->wasmRegion(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin'], 'sandbox_wasm_nodes' => ['worker-1']]);
        $cluster = new ClusterGroup('uid:wasm', [new ReconcileTarget($region)], $this->k8s());
        $this->servingFeatures = WasmKnativeFeatures::FEATURES;
        $this->runtimeClasses['wasmtime-spin-v2'] = WasmManifests::runtimeClass(WasmShim::get('spin'));
        // an earlier build installed the upstream release of the Spin shim on the nodes
        $this->liveInstaller(['spin'], self::UPSTREAM_SPIN_LINE);

        $this->assertSame('outdated', app(WasmInstallService::class)->installerDrift($this->k8s(), [WasmShim::get('spin')])['state']);

        $dry = (new WasmSandboxStep)->run($cluster, true);
        $this->assertCount(1, $dry);
        $this->assertStringStartsWith('would re-run the WebAssembly installer on the region\'s nodes with the shims of this release (Spin v0.26.0-runr.1; installed now: spin (v0.26.0))', $dry[0]);
        $this->assertStringContainsString('the shim binaries are replaced in place one node at a time', $dry[0]);
        Queue::assertNothingPushed();
        $this->assertSame('ready', $region->refresh()->sandbox_wasm_status, 'dry run writes nothing');

        $lines = (new WasmSandboxStep)->run($cluster, false);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('queued as the WebAssembly install of region fn', $lines[0]);
        $region->refresh();
        $this->assertSame('installing', $region->sandbox_wasm_status);
        $this->assertStringContainsString('Queued by region:reconcile', $region->sandbox_wasm_status_message);
        Queue::assertPushed(InstallWasmJob::class, 1);
        Queue::assertPushed(InstallWasmJob::class, fn (InstallWasmJob $job) => $job->shims === ['spin']
            && $job->nodes === ['worker-1'] && $job->previousStatus === 'ready' && $job->region->is($region));

        // the queued install: the installer of this release (RunR's Spin shim image) on the same node
        $job = null;
        Queue::assertPushed(InstallWasmJob::class, function (InstallWasmJob $pushed) use (&$job) {
            $job = $pushed;

            return true;
        });
        $service = $this->wasmService();
        $job->handle($service);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_wasm_status);
        $spec = $this->daemonSets[WasmManifests::INSTALLER_DS]['spec']['template']['spec'];
        $this->assertSame('ghcr.io/runrsh/containerd-shim-spin:v0.26.0-runr.1', $spec['initContainers'][0]['image']);
        $env = array_column($spec['containers'][0]['env'], 'value', 'name');
        $this->assertSame(WasmShim::get('spin')->scriptLine(), $env['WASM_SHIMS']);
        $this->assertSame('true', $this->labelsOf('worker-1')['runr.dev/wasm-spin'] ?? null);

        // the next reconcile has nothing left to do
        $this->assertSame([], (new WasmSandboxStep)->run(new ClusterGroup('uid:wasm', [new ReconcileTarget($region)], $this->k8s()), false));
    }

    public function test_a_ready_install_without_its_installer_is_installed_again(): void
    {
        Queue::fake();
        // a failed change of a working install deletes the installer and keeps the region ready
        $region = $this->wasmRegion(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);
        $cluster = new ClusterGroup('uid:wasm', [new ReconcileTarget($region)], $this->k8s());
        $this->servingFeatures = WasmKnativeFeatures::FEATURES;
        $this->runtimeClasses['wasmtime-spin-v2'] = WasmManifests::runtimeClass(WasmShim::get('spin'));

        $dry = (new WasmSandboxStep)->run($cluster, true);
        $this->assertCount(1, $dry);
        $this->assertStringStartsWith('would re-run the WebAssembly installer on the region\'s nodes with the shims of this release '
            .'(Spin v0.26.0-runr.1; the wasm-installer DaemonSet is missing, as after a failed change)', $dry[0]);
        Queue::assertNothingPushed();

        $lines = (new WasmSandboxStep)->run($cluster, false);
        $this->assertStringContainsString('queued as the WebAssembly install of region fn', $lines[0]);
        Queue::assertPushed(InstallWasmJob::class, fn (InstallWasmJob $job) => $job->shims === ['spin'] && $job->nodes === null && $job->previousStatus === 'ready');
        $this->assertSame('installing', $region->refresh()->sandbox_wasm_status);
    }

    public function test_the_reconcile_step_leaves_regions_without_a_ready_install_alone(): void
    {
        foreach (['not_installed', 'installing', 'error'] as $status) {
            $region = $this->wasmRegion(['name' => "r-{$status}", 'sandbox_wasm_status' => $status]);
            $cluster = new ClusterGroup('uid', [new ReconcileTarget($region)], $this->k8s());
            $this->assertSame([], (new WasmSandboxStep)->run($cluster, false), $status);
        }
        $this->assertSame([], $this->runtimeClasses);
        $this->assertContains(WasmSandboxStep::class, array_map(fn ($s) => $s::class, app(RegionReconciler::class)->steps()));
    }
}
