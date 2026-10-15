<?php

namespace Tests\Unit\Wasm;

use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Wasm\WasmManifests;
use App\Services\Wasm\WasmNodeScripts;
use App\Services\Wasm\WasmShim;
use PHPUnit\Framework\TestCase;

/**
 * The installer / cleanup DaemonSets per node type (plain containerd 1.7 and 2.x, k3s, RKE2) and
 * the RuntimeClasses.
 */
class WasmManifestsTest extends TestCase
{
    private function runtime(string $runtimeVersion, string $kubelet = 'v1.34.1'): array
    {
        return ContainerRuntimeHelper::resolveCluster([[
            'metadata' => ['name' => 'n1'],
            'status' => ['nodeInfo' => ['containerRuntimeVersion' => $runtimeVersion, 'kubeletVersion' => $kubelet]],
        ]]);
    }

    private function env(array $ds): array
    {
        $env = [];
        foreach ($ds['spec']['template']['spec']['containers'][0]['env'] as $e) {
            $env[$e['name']] = $e['value'] ?? $e['valueFrom'];
        }

        return $env;
    }

    public function test_the_installer_runs_privileged_on_the_target_nodes_only(): void
    {
        $ds = WasmManifests::installerDaemonSet([WasmShim::get('spin')], $this->runtime('containerd://2.1.4'));
        $spec = $ds['spec']['template']['spec'];
        $c = $spec['containers'][0];

        $this->assertSame(['wasm-installer', 'runr-sandbox'], [$ds['metadata']['name'], $ds['metadata']['namespace']]);
        $this->assertSame(['kubernetes.io/os' => 'linux', 'runr.dev/wasm-target' => 'true'], $spec['nodeSelector']);
        $this->assertTrue($spec['hostPID']);
        $this->assertTrue($c['securityContext']['privileged']);
        $this->assertSame([
            ['name' => 'host-root', 'mountPath' => '/host'],
            ['name' => 'shim-files', 'mountPath' => WasmNodeScripts::SHIM_FILES, 'readOnly' => true],
        ], $c['volumeMounts']);
        $this->assertSame([
            ['name' => 'host-root', 'hostPath' => ['path' => '/']],
            ['name' => 'shim-files', 'emptyDir' => ['sizeLimit' => '512Mi']],
        ], $spec['volumes']);
        $this->assertSame(['sh', '-c', WasmNodeScripts::install()], $c['command']);
        $this->assertSame(['cat', WasmNodeScripts::READY_MARKER], $c['readinessProbe']['exec']['command']);
        $this->assertSame('FallbackToLogsOnError', $c['terminationMessagePolicy']);
        $this->assertSame(['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 1]], $ds['spec']['updateStrategy']);
        $this->assertContains(['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule'], $spec['tolerations']);
        $this->assertSame('spin', $ds['spec']['template']['metadata']['annotations'][WasmManifests::SHIMS_ANNOTATION]);
    }

    public function test_installer_environment_per_node_type(): void
    {
        $cases = [
            'containerd 2.x' => ['containerd://2.1.4', 'v1.34.1', 'containerd', '2', '/etc/containerd', '/run/containerd/containerd.sock', 'containerd'],
            'containerd 1.7' => ['containerd://1.7.27', 'v1.31.4', 'containerd', '1', '/etc/containerd', '/run/containerd/containerd.sock', 'containerd'],
            'k3s' => ['containerd://2.0.5-k3s1', 'v1.34.1+k3s1', 'k3s', '2', '/var/lib/rancher/k3s/agent/etc/containerd', '/run/k3s/containerd/containerd.sock', 'k3s k3s-agent'],
            'rke2' => ['containerd://2.0.5-k3s1', 'v1.33.4+rke2r1', 'rke2', '2', '/var/lib/rancher/rke2/agent/etc/containerd', '/run/k3s/containerd/containerd.sock', 'rke2-server rke2-agent'],
        ];

        foreach ($cases as $name => [$version, $kubelet, $flavor, $major, $dir, $socket, $units]) {
            $env = $this->env(WasmManifests::installerDaemonSet([WasmShim::get('spin'), WasmShim::get('wasmtime')], $this->runtime($version, $kubelet)));

            $this->assertSame($flavor, $env['RUNTIME_FLAVOR'], $name);
            $this->assertSame($major, $env['CONTAINERD_MAJOR'], $name);
            $this->assertSame($dir, $env['CONTAINERD_CONFIG_DIR'], $name);
            $this->assertSame($socket, $env['CONTAINERD_SOCKET'], $name);
            $this->assertSame($units, $env['RESTART_UNITS'], $name);
            $this->assertSame(WasmShim::get('spin')->scriptLine()."\n".WasmShim::get('wasmtime')->scriptLine(), $env['WASM_SHIMS'], $name);
            $this->assertSame($env['WASM_SHIMS'], $env['WASM_ALL_SHIMS'], $name);
            $this->assertSame(['fieldRef' => ['fieldPath' => 'spec.nodeName']], $env['NODE_NAME'], $name);
        }
    }

    public function test_an_image_shim_is_copied_into_the_installer_pod_by_an_init_container(): void
    {
        $spin = WasmShim::get('spin');
        $spec = WasmManifests::installerDaemonSet([$spin, WasmShim::get('wasmtime')], $this->runtime('containerd://2.1.4'))['spec']['template']['spec'];

        // one init container per image shim (wasmtime comes from its release tarballs)
        $this->assertCount(1, $spec['initContainers']);
        $init = $spec['initContainers'][0];
        $this->assertSame('shim-spin', $init['name']);
        $this->assertSame('ghcr.io/brf-tech/containerd-shim-spin:v0.26.0-runr.1', $init['image']);
        $this->assertSame(['sh', '-c', 'mkdir -p '.WasmNodeScripts::SHIM_FILES.'/spin && cp -a /shim/. '.WasmNodeScripts::SHIM_FILES.'/spin/'], $init['command']);
        $this->assertArrayNotHasKey('securityContext', $init, 'copying the files needs no privileges');
        $this->assertSame([['name' => 'shim-files', 'mountPath' => WasmNodeScripts::SHIM_FILES]], $init['volumeMounts']);

        // the installer reads them from the same pod-local volume
        $this->assertContains(['name' => 'shim-files', 'mountPath' => WasmNodeScripts::SHIM_FILES, 'readOnly' => true], $spec['containers'][0]['volumeMounts']);
        $this->assertContains(['name' => 'shim-files', 'emptyDir' => ['sizeLimit' => '512Mi']], $spec['volumes']);

        // a selection without an image shim needs neither
        $wasmtimeOnly = WasmManifests::installerDaemonSet([WasmShim::get('wasmtime')], $this->runtime('containerd://2.1.4'))['spec']['template']['spec'];
        $this->assertArrayNotHasKey('initContainers', $wasmtimeOnly);
        $this->assertSame([['name' => 'host-root', 'hostPath' => ['path' => '/']]], $wasmtimeOnly['volumes']);
    }

    public function test_the_installer_of_a_spin_only_selection(): void
    {
        $env = $this->env(WasmManifests::installerDaemonSet([WasmShim::get('spin')], $this->runtime('containerd://2.1.4')));

        $this->assertSame(WasmShim::get('spin')->scriptLine(), $env['WASM_SHIMS']);
        // every known shim, so the installer can drop the ones not selected
        $this->assertStringContainsString('containerd-shim-wasmtime-v1', $env['WASM_ALL_SHIMS']);
    }

    public function test_the_cleanup_runs_on_the_cleanup_nodes(): void
    {
        $ds = WasmManifests::cleanupDaemonSet($this->runtime('containerd://2.0.5-k3s1', 'v1.34.1+k3s1'));
        $spec = $ds['spec']['template']['spec'];
        $env = $this->env($ds);

        $this->assertSame('wasm-cleanup', $ds['metadata']['name']);
        $this->assertSame(['kubernetes.io/os' => 'linux', 'runr.dev/wasm-cleanup' => 'true'], $spec['nodeSelector']);
        $this->assertSame(['sh', '-c', WasmNodeScripts::cleanup()], $spec['containers'][0]['command']);
        $this->assertSame(['cat', WasmNodeScripts::CLEAN_MARKER], $spec['containers'][0]['readinessProbe']['exec']['command']);
        $this->assertSame('k3s', $env['RUNTIME_FLAVOR']);
        $this->assertArrayNotHasKey('WASM_SHIMS', $env);
        $this->assertStringContainsString('containerd-shim-spin-v2', $env['WASM_ALL_SHIMS']);
    }

    public function test_runtime_classes_select_the_nodes_with_their_shim(): void
    {
        $spin = WasmManifests::runtimeClass(WasmShim::get('spin'));
        $this->assertSame('node.k8s.io/v1', $spin['apiVersion']);
        $this->assertSame('wasmtime-spin-v2', $spin['metadata']['name']);
        $this->assertSame('runr', $spin['metadata']['labels']['app.kubernetes.io/managed-by']);
        $this->assertSame('spin-runr', $spin['handler']);
        $this->assertSame(['nodeSelector' => ['runr.dev/wasm-spin' => 'true']], $spin['scheduling']);
        $this->assertArrayNotHasKey('overhead', $spin, 'no VM: no pod overhead');

        $wasmtime = WasmManifests::runtimeClass(WasmShim::get('wasmtime'));
        $this->assertSame(['wasmtime-v1', 'wasmtime-runr'], [$wasmtime['metadata']['name'], $wasmtime['handler']]);
        $this->assertSame(['runr.dev/wasm-wasmtime' => 'true'], $wasmtime['scheduling']['nodeSelector']);
    }
}
