<?php

namespace Tests\Feature\Wasm;

use App\Exceptions\SandboxInUseException;
use App\Services\Wasm\WasmManifests;
use App\Services\Wasm\WasmShim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWasmCluster;
use Tests\TestCase;

/**
 * WasmInstallService against a fake cluster: first install, node selection, the Knative feature
 * patch, failures (rollback of a first install, a foreign RuntimeClass, ineligible or mixed
 * nodes), changes of a working install (shims, nodes) and uninstall.
 */
class WasmInstallTest extends TestCase
{
    use FakesWasmCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addNode('worker-1');
        $this->addNode('worker-2');
        $this->addNode('control-plane', [], 'containerd://2.1.4', 'v1.34.1', [['key' => 'node-role.kubernetes.io/control-plane', 'effect' => 'NoSchedule']]);
        $this->fakeWasmCluster();
    }

    public function test_a_first_install_prepares_every_eligible_node(): void
    {
        $region = $this->wasmRegion();

        $this->wasmService()->install($region, ['spin']);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_wasm_status);
        $this->assertSame(['spin'], $region->sandbox_wasm_shims);
        $this->assertNull($region->sandbox_wasm_nodes);
        $this->assertStringContainsString('WebAssembly support installed.', $region->sandbox_wasm_status_message);

        // the installer ran on the selected (eligible) nodes only
        $ds = $this->lastBody('PATCH', '/apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-installer');
        $this->assertSame(['kubernetes.io/os' => 'linux', 'runr.dev/wasm-target' => 'true'], $ds['spec']['template']['spec']['nodeSelector']);
        $env = array_column($ds['spec']['template']['spec']['containers'][0]['env'], 'value', 'name');
        $this->assertSame(WasmShim::get('spin')->scriptLine(), $env['WASM_SHIMS']);
        $this->assertSame('containerd', $env['RUNTIME_FLAVOR']);

        foreach (['worker-1', 'worker-2'] as $node) {
            $labels = $this->labelsOf($node);
            $this->assertSame('true', $labels['runr.dev/wasm-target'] ?? null, $node);
            $this->assertSame('true', $labels['runr.dev/wasm'] ?? null, $node);
            $this->assertSame('true', $labels['runr.dev/wasm-spin'] ?? null, $node);
            $this->assertArrayNotHasKey('runr.dev/wasm-wasmtime', $labels, $node);
        }
        $this->assertArrayNotHasKey('runr.dev/wasm', $this->labelsOf('control-plane'));

        // RuntimeClass of the shim, selecting the labelled nodes
        $rc = $this->runtimeClasses['wasmtime-spin-v2'];
        $this->assertSame('spin-runr', $rc['handler']);
        $this->assertSame(['runr.dev/wasm-spin' => 'true'], $rc['scheduling']['nodeSelector']);
        $this->assertArrayNotHasKey('wasmtime-v1', $this->runtimeClasses);

        // Knative accepts runtimeClassName and nodeSelector in the Wasm functions' pod spec
        $this->assertSame('enabled', $this->servingFeatures['kubernetes.podspec-runtimeclassname']);
        $this->assertSame('enabled', $this->servingFeatures['kubernetes.podspec-nodeselector']);

        // the node labels come after the installer finished, the RuntimeClass after the labels
        $installer = array_search('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-installer', $this->calls, true);
        $labelled = array_search('PATCH /api/v1/nodes/worker-1', array_slice($this->calls, $installer, null, true), true);
        $created = array_search('PATCH /apis/node.k8s.io/v1/runtimeclasses/wasmtime-spin-v2', $this->calls, true);
        $this->assertNotFalse($labelled);
        $this->assertGreaterThan($labelled, $created);
    }

    public function test_install_on_selected_nodes_with_both_shims(): void
    {
        $region = $this->wasmRegion();

        $this->wasmService()->install($region, ['spin', 'wasmtime'], ['worker-2']);

        $region->refresh();
        $this->assertSame(['spin', 'wasmtime'], $region->sandbox_wasm_shims);
        $this->assertSame(['worker-2'], $region->sandbox_wasm_nodes);
        $this->assertSame('true', $this->labelsOf('worker-2')['runr.dev/wasm-wasmtime']);
        $this->assertArrayNotHasKey('runr.dev/wasm-target', $this->labelsOf('worker-1'));
        $this->assertArrayNotHasKey('runr.dev/wasm-spin', $this->labelsOf('worker-1'));
        $this->assertSame('wasmtime-runr', $this->runtimeClasses['wasmtime-v1']['handler']);
    }

    public function test_without_the_operator_the_config_features_configmap_is_patched(): void
    {
        $this->servingFeatures = null;
        $region = $this->wasmRegion();

        $this->wasmService()->install($region, ['spin']);

        $this->assertSame('enabled', $this->configFeatures['kubernetes.podspec-runtimeclassname']);
        $this->assertSame('enabled', $this->configFeatures['kubernetes.podspec-nodeselector']);
        $this->assertSame('ready', $region->refresh()->sandbox_wasm_status);
    }

    public function test_features_that_are_already_enabled_are_not_patched_again(): void
    {
        $this->servingFeatures = ['kubernetes.podspec-runtimeclassname' => 'enabled', 'kubernetes.podspec-nodeselector' => 'enabled'];
        $region = $this->wasmRegion();

        $this->wasmService()->install($region, ['spin']);

        $this->assertFalse($this->called('PATCH /apis/operator.knative.dev/v1beta1/namespaces/knative-serving/knativeservings/knative-serving'));
        $this->assertStringContainsString('Knative features already enabled', $region->refresh()->sandbox_wasm_status_message);
    }

    public function test_a_failed_first_install_rolls_the_nodes_back(): void
    {
        $this->failingDaemonSets['wasm-installer'] = 'download of https://example.com/shim.tar.gz failed';
        $region = $this->wasmRegion();

        try {
            $this->wasmService()->install($region, ['spin']);
            $this->fail('the install must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wasm-installer failed: download of https://example.com/shim.tar.gz failed (node worker-', $e->getMessage());
            $this->assertStringContainsString('node changes rolled back', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('error', $region->sandbox_wasm_status);
        $this->assertStringContainsString('Rolling back the node changes', $region->sandbox_wasm_status_message);
        // installer stopped, cleanup ran on the nodes and is gone again, no RuntimeClass, no labels
        $this->assertArrayNotHasKey('wasm-installer', $this->daemonSets);
        $this->assertTrue($this->called('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-cleanup'));
        $this->assertArrayNotHasKey('wasm-cleanup', $this->daemonSets);
        $this->assertSame([], $this->runtimeClasses);
        foreach (['worker-1', 'worker-2'] as $node) {
            $this->assertArrayNotHasKey('runr.dev/wasm-target', $this->labelsOf($node));
            $this->assertArrayNotHasKey('runr.dev/wasm-cleanup', $this->labelsOf($node));
            $this->assertArrayNotHasKey('runr.dev/wasm-spin', $this->labelsOf($node));
        }
    }

    public function test_a_failed_change_of_a_working_install_marked_installing_keeps_it(): void
    {
        $region = $this->wasmRegion();
        $this->wasmService()->install($region, ['spin']);
        // the API and region:reconcile mark the region "installing" before the job runs
        $region->update(['sandbox_wasm_status' => 'installing']);
        $this->failingDaemonSets['wasm-installer'] = 'cannot install containerd-shim-spin-v2 into /usr/local/bin';
        $this->calls = [];

        try {
            $this->wasmService()->install($region, ['spin'], null, 'ready');
            $this->fail('the change must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot install containerd-shim-spin-v2', $e->getMessage());
            $this->assertStringNotContainsString('rolled back', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_wasm_status);
        $this->assertSame(['spin'], $region->sandbox_wasm_shims);
        $this->assertStringContainsString('WebAssembly support stays installed with spin', $region->sandbox_wasm_status_message);
        $this->assertFalse($this->called('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-cleanup'), 'the nodes are not rolled back');
        $this->assertArrayHasKey('wasmtime-spin-v2', $this->runtimeClasses);
        foreach (['worker-1', 'worker-2'] as $node) {
            $this->assertSame('true', $this->labelsOf($node)['runr.dev/wasm-spin'] ?? null, $node);
        }
    }

    public function test_a_runtime_class_another_tool_created_is_left_alone(): void
    {
        $this->runtimeClasses['wasmtime-spin-v2'] = [
            'metadata' => ['name' => 'wasmtime-spin-v2', 'labels' => ['app.kubernetes.io/part-of' => 'runtime-class-manager']],
            'handler' => 'spin-v2',
        ];
        $region = $this->wasmRegion();

        try {
            $this->wasmService()->install($region, ['spin']);
            $this->fail('the install must stop');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('RuntimeClass wasmtime-spin-v2 exists and was not created by RunR (handler spin-v2)', $e->getMessage());
        }

        $this->assertSame('spin-v2', $this->runtimeClasses['wasmtime-spin-v2']['handler'], 'not replaced');
        $this->assertSame('error', $region->refresh()->sandbox_wasm_status);
    }

    public function test_ineligible_and_mixed_nodes_are_refused_before_anything_changes(): void
    {
        $region = $this->wasmRegion();
        try {
            $this->wasmService()->install($region, ['spin'], ['control-plane']);
            $this->fail('a tainted node accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('control-plane (tainted)', $e->getMessage());
        }

        $this->addNode('k3s-1', [], 'containerd://2.0.5-k3s1', 'v1.34.1+k3s1');
        try {
            $this->wasmService()->install($region, ['spin'], ['worker-1', 'k3s-1']);
            $this->fail('mixed runtimes accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('different container runtimes', $e->getMessage());
        }

        $this->assertSame([], $this->daemonSets);
        $this->assertSame('error', $region->refresh()->sandbox_wasm_status);
    }

    public function test_k3s_nodes_get_the_k3s_installer(): void
    {
        $this->nodes = [];
        $this->addNode('k3s-1', [], 'containerd://2.0.5-k3s1', 'v1.34.1+k3s1');
        $region = $this->wasmRegion();

        $this->wasmService()->install($region, ['spin']);

        $env = array_column($this->lastBody('PATCH', '/apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-installer')['spec']['template']['spec']['containers'][0]['env'], 'value', 'name');
        $this->assertSame('k3s', $env['RUNTIME_FLAVOR']);
        $this->assertSame('/var/lib/rancher/k3s/agent/etc/containerd', $env['CONTAINERD_CONFIG_DIR']);
        $this->assertSame('k3s k3s-agent', $env['RESTART_UNITS']);
        $this->assertStringContainsString('the k3s service', $region->refresh()->sandbox_wasm_status_message);
    }

    public function test_deselecting_a_shim_in_use_is_refused_and_changes_nothing(): void
    {
        $region = $this->wasmRegion();
        $this->wasmService()->install($region, ['spin', 'wasmtime']);
        $this->clusterPods = [[
            'metadata' => ['namespace' => 'fn', 'name' => 'cli-tool-00001-deployment-abc'],
            'spec' => ['runtimeClassName' => 'wasmtime-v1', 'nodeName' => 'worker-1'],
            'status' => ['phase' => 'Running'],
        ]];
        $this->calls = [];

        try {
            $this->wasmService()->install($region->refresh(), ['spin']);
            $this->fail('change accepted while a Wasmtime pod runs');
        } catch (SandboxInUseException $e) {
            $this->assertStringContainsString('fn/cli-tool-00001-deployment-abc', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_wasm_status);
        $this->assertSame(['spin', 'wasmtime'], $region->sandbox_wasm_shims);
        $this->assertArrayHasKey('wasmtime-v1', $this->runtimeClasses);
        $this->assertEmpty(array_filter($this->calls, fn ($c) => ! str_starts_with($c, 'GET ')), 'nothing written to the cluster');
    }

    public function test_a_change_drops_a_shim_and_cleans_the_nodes_taken_out(): void
    {
        $region = $this->wasmRegion();
        $this->wasmService()->install($region, ['spin', 'wasmtime']);

        $this->wasmService()->install($region->refresh(), ['spin'], ['worker-1']);

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_wasm_status);
        $this->assertSame(['spin'], $region->sandbox_wasm_shims);
        $this->assertArrayNotHasKey('wasmtime-v1', $this->runtimeClasses);
        $this->assertArrayHasKey('wasmtime-spin-v2', $this->runtimeClasses);
        $this->assertArrayNotHasKey('runr.dev/wasm-wasmtime', $this->labelsOf('worker-1'));
        $this->assertSame('true', $this->labelsOf('worker-1')['runr.dev/wasm-spin']);

        // worker-2 left the selection: no WebAssembly labels, cleaned by the cleanup DaemonSet
        $this->assertSame([], array_filter($this->labelsOf('worker-2'), fn ($k) => str_starts_with($k, 'runr.dev/wasm'), ARRAY_FILTER_USE_KEY));
        $cleanup = $this->lastBody('PATCH', '/apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-cleanup');
        $this->assertSame('true', $cleanup['spec']['template']['spec']['nodeSelector']['runr.dev/wasm-cleanup']);
        $this->assertArrayNotHasKey('wasm-cleanup', $this->daemonSets);
        $this->assertStringContainsString('Taken out of the selection', $region->sandbox_wasm_status_message);
    }

    public function test_uninstall_is_refused_while_wasm_pods_run(): void
    {
        $region = $this->wasmRegion();
        $this->wasmService()->install($region, ['spin']);
        $this->clusterPods = [[
            'metadata' => ['namespace' => 'fn', 'name' => 'hello-00001-deployment-x'],
            'spec' => ['runtimeClassName' => 'wasmtime-spin-v2'],
            'status' => ['phase' => 'Running'],
        ], [
            // a Wasm pod that never started does not block
            'metadata' => ['namespace' => 'fn', 'name' => 'stuck'],
            'spec' => ['runtimeClassName' => 'wasmtime-spin-v2'],
            'status' => ['phase' => 'Pending'],
        ]];

        try {
            $this->wasmService()->uninstall($region->refresh());
            $this->fail('uninstall must be refused');
        } catch (SandboxInUseException $e) {
            $this->assertStringContainsString('1 running pod(s) still use WebAssembly (fn/hello-00001-deployment-x)', $e->getMessage());
        }

        $region->refresh();
        $this->assertSame('ready', $region->sandbox_wasm_status);
        $this->assertStringStartsWith('Uninstall refused:', $region->sandbox_wasm_status_message);
        $this->assertArrayHasKey('wasmtime-spin-v2', $this->runtimeClasses);
    }

    public function test_uninstall_removes_the_runtime_classes_labels_and_shims(): void
    {
        $region = $this->wasmRegion();
        $this->wasmService()->install($region, ['spin', 'wasmtime']);
        $this->calls = [];

        $this->wasmService()->uninstall($region->refresh());

        $region->refresh();
        $this->assertSame('not_installed', $region->sandbox_wasm_status);
        $this->assertNull($region->sandbox_wasm_shims);
        $this->assertNull($region->sandbox_wasm_nodes);
        $this->assertSame([], $this->runtimeClasses);
        $this->assertArrayNotHasKey(WasmManifests::INSTALLER_DS, $this->daemonSets);
        foreach (['worker-1', 'worker-2'] as $node) {
            $this->assertSame([], array_filter($this->labelsOf($node), fn ($k) => str_starts_with($k, 'runr.dev/wasm'), ARRAY_FILTER_USE_KEY), $node);
        }

        // RuntimeClasses first (no new Wasm pods), then the installer goes, then the nodes are cleaned
        $rcDeleted = array_search('DELETE /apis/node.k8s.io/v1/runtimeclasses/wasmtime-spin-v2', $this->calls, true);
        $installer = array_search('DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-installer', $this->calls, true);
        $cleanup = array_search('PATCH /apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-cleanup', $this->calls, true);
        $this->assertNotFalse($rcDeleted);
        $this->assertLessThan($installer, $rcDeleted);
        $this->assertLessThan($cleanup, $installer);
        $this->assertSame('DELETE /apis/apps/v1/namespaces/runr-sandbox/daemonsets/wasm-cleanup',
            collect($this->calls)->last(fn ($c) => str_contains($c, 'wasm-cleanup')));
    }

    public function test_a_failed_cleanup_is_reported(): void
    {
        $region = $this->wasmRegion();
        $this->wasmService()->install($region, ['spin']);
        $this->failingDaemonSets['wasm-cleanup'] = 'processes of the spin shim (containerd-shim-spin-v2) still run on this node';

        try {
            $this->wasmService()->uninstall($region->refresh());
            $this->fail('cleanup failure not reported');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wasm-cleanup failed: processes of the spin shim', $e->getMessage());
        }

        $this->assertSame('error', $region->refresh()->sandbox_wasm_status);
        $this->assertArrayNotHasKey('wasm-cleanup', $this->daemonSets, 'no crash-looping cleanup left behind');
    }
}
