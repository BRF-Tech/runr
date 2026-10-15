<?php

namespace App\Console\Commands\Concerns;

use App\Models\KnativeRegion;
use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\Steps\WasmSandboxStep;
use App\Services\Wasm\WasmInstallService;
use App\Services\Wasm\WasmManifests;
use App\Services\Wasm\WasmShim;

/**
 * E2E of WebAssembly support (test:e2e --suite=wasm; runtime/ci.sh test-wasm):
 *  1. installs WebAssembly support (spin shim) on the region through the API and waits for it;
 *  2. checks the RuntimeClass wasmtime-spin-v2 and the node labels it selects;
 *  2b. upgrade of a region an earlier build prepared (wasmUpgrade): the nodes get the upstream
 *     Spin shim back through the installer such a build applied, region:reconcile's WasmSandboxStep
 *     queues the install of this release and the installer replaces the binary with RunR's build;
 *  3. deploys the Rust and JavaScript examples (examples/wasm, copied into the API container at
 *     --examples/wasm) as XaaS projects of wasm-rust / wasm-js: build Job → Spin OCI artifact in
 *     the region registry → Knative Service under the spin shim (queue-proxy as a Linux container);
 *  4. calls them (?name=RunR) and checks the body and the pod spec (runtimeClassName, nodeSelector);
 *  5. waits until each scaled to zero and times the next call — WASM_COLD_STARTS times (default 3)
 *     — and prints every cold start with the median;
 *  6. deletes the projects and uninstalls WebAssembly support (node cleanup).
 */
trait E2eTestWasmTrait
{
    private function runWasmSuite(string $regionId): array
    {
        $dir = rtrim((string) ($this->option('examples') ?: '/tmp/runr-examples'), '/').'/wasm';

        $this->info('');
        $this->info('── WebAssembly (runwasi / Spin) ──');

        if (! is_file("{$dir}/rust/lib.rs") || ! is_file("{$dir}/js/index.js")) {
            $this->error("WebAssembly examples not found at {$dir} (docker compose cp examples/wasm api:{$dir})");

            return [$this->failedEntry('wasm-examples', "examples missing at {$dir}", $regionId)];
        }

        $install = $this->finishTest($this->wasmInstall($regionId));
        if ($install['steps']['verify'] !== true) {
            return [$install];
        }

        // the functions below run on the shim the upgrade installed
        $upgrade = $this->finishTest($this->wasmUpgrade($regionId));
        if ($upgrade['steps']['verify'] !== true) {
            return [$install, $upgrade];
        }

        $tests = [
            $this->runtimeEntry('wasm-rust-http', 'wasm-rust', 'http', (string) file_get_contents("{$dir}/rust/lib.rs"), $regionId, ['body' => 'Hello, RunR! (from Rust, Wasm)']),
            $this->runtimeEntry('wasm-js-http', 'wasm-js', 'http', (string) file_get_contents("{$dir}/js/index.js"), $regionId, ['body' => 'Hello, RunR! (from JavaScript, Wasm)']),
        ];

        $this->runTestsSequentially($tests);

        foreach ($tests as &$test) {
            if ($test['steps']['ready'] !== true) {
                $test['steps']['verify'] ??= 'skipped';

                continue;
            }
            $test['steps']['verify'] = $this->verifyWasmHttp($test);
            $state = $test['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$state} {$test['name']}: ".($test['steps']['verify'] === true ? 'verified' : $test['steps']['verify']));
        }
        unset($test);

        $this->printWasmColdStarts($tests);

        return array_merge([$install, $upgrade], $tests, [$this->finishTest($this->wasmUninstall($regionId, $tests))]);
    }

    /** WASM_SHIMS of an installer before RunR's Spin shim build: the upstream containerd-shim-spin v0.26.0 release. */
    private const UPSTREAM_SPIN_LINE = 'spin containerd-shim-spin-v2 spin-runr io.containerd.spin.v2 '
        .'https://github.com/spinframework/containerd-shim-spin/releases/download/v0.26.0/containerd-shim-spin-v2-linux-x86_64.tar.gz '
        .'392945ca82c11abc7006a32c4e5dc12821954b5e2c35cb28e924aeea6bad5286 '
        .'https://github.com/spinframework/containerd-shim-spin/releases/download/v0.26.0/containerd-shim-spin-v2-linux-aarch64.tar.gz '
        .'dfe430d65f9b67b4b82470a1f5e390b4ff396bb3c917e189e1ed375614e6deb5';

    /**
     * An existing region gets this release's Spin shim on upgrade:
     *  1. deploy: the wasm-installer becomes what an earlier build applied (WASM_SHIMS of the upstream
     *     release tarballs, no shim image) and rolls out — every node runs the upstream binary again;
     *  2. ready: region:reconcile's WasmSandboxStep reports the outdated installer and queues the
     *     region's WebAssembly install; the region is ready again;
     *  3. verify: the installer runs this release's shim image and replaced the binary on every node
     *     without restarting the container runtime (its log).
     */
    private function wasmUpgrade(string $regionId): array
    {
        $entry = $this->makeTestEntry('wasm-upgrade', ['service_type' => 'xaas', 'trigger_type' => 'http'], $regionId);
        $entry['startTime'] = microtime(true);
        $entry['steps'] = ['version' => true, 'deploy' => null, 'ready' => null, 'verify' => null];
        $k8s = $this->getE2eKubernetesClient();
        $spin = WasmShim::get('spin');
        $skip = function (string $step, string $why) use (&$entry): array {
            $entry['steps'][$step] = $why;
            foreach (['deploy', 'ready', 'verify'] as $s) {
                $entry['steps'][$s] ??= 'skipped';
            }
            $this->line("  <fg=red>✗</> wasm-upgrade [{$step}]: {$why}");

            return $entry;
        };

        // 1. the installer of an earlier release
        $nodes = $k8s->listClusterResources('v1', 'Node', [WasmShim::TARGET_LABEL => 'true'])['items'] ?? [];
        $old = WasmManifests::installerDaemonSet([$spin], ContainerRuntimeHelper::resolveCluster($nodes));
        $spec = &$old['spec']['template']['spec'];
        unset($spec['initContainers']);
        $spec['volumes'] = array_values(array_filter($spec['volumes'], fn ($v) => $v['name'] !== 'shim-files'));
        $spec['containers'][0]['volumeMounts'] = array_values(array_filter($spec['containers'][0]['volumeMounts'], fn ($m) => $m['name'] !== 'shim-files'));
        foreach ($spec['containers'][0]['env'] as &$env) {
            if ($env['name'] === 'WASM_SHIMS') {
                $env['value'] = self::UPSTREAM_SPIN_LINE;
            }
        }
        unset($env, $spec);
        if (! $k8s->applyResource($old, WasmManifests::NAMESPACE)) {
            return $skip('deploy', 'cannot apply the installer of an earlier build');
        }
        $logs = $this->wasmInstallerRollout($k8s, 300);
        if (is_string($logs)) {
            return $skip('deploy', "installer of an earlier build: {$logs}");
        }
        foreach ($logs as $node => $log) {
            if (! preg_match('/containerd-shim-spin-v2 installed \(spin shim, sha256 (392945ca|dfe430d6)/', $log)) {
                return $skip('deploy', "{$node} did not get the upstream shim back: ".mb_substr(trim($log), -300));
            }
        }
        $entry['steps']['deploy'] = true;
        $this->line('  <fg=green>✓</> wasm-upgrade: the nodes run the upstream Spin shim v0.26.0 of an earlier build ('.implode(', ', array_keys($logs)).')');

        // 2. region:reconcile's step
        $region = KnativeRegion::find($regionId);
        $drift = app(WasmInstallService::class)->installerDrift($k8s, [$spin]);
        if ($drift['state'] !== 'outdated') {
            return $skip('ready', "installer drift {$drift['state']}, expected outdated");
        }
        $lines = (new WasmSandboxStep)->run(new ClusterGroup('e2e', [new ReconcileTarget($region)], $k8s), false);
        $this->line('  region:reconcile [wasm-sandbox] '.implode(' | ', $lines));
        if (! str_contains(implode("\n", $lines), 'queued as the WebAssembly install')) {
            return $skip('ready', 'the reconcile step did not queue the install: '.implode(' | ', $lines));
        }
        $status = null;
        $deadline = time() + 900;
        while (time() < $deadline) {
            $status = $this->api()->get("/api/knative-regions/{$regionId}/wasm", ['cluster' => 0])->json();
            if (in_array($status['status'] ?? null, ['ready', 'error'], true)) {
                break;
            }
            sleep(5);
        }
        if (($status['status'] ?? null) !== 'ready') {
            $log = implode("\n", array_slice(explode("\n", trim((string) ($status['message'] ?? ''))), -6));

            return $skip('ready', 'status '.($status['status'] ?? 'unknown').': '.mb_substr($log, 0, 400));
        }
        $entry['steps']['ready'] = true;

        // 3. this release's shim on every node, no runtime restart
        $live = $k8s->getResourceStatus('apps/v1', 'DaemonSet', WasmManifests::INSTALLER_DS, WasmManifests::NAMESPACE);
        $initImage = $live['spec']['template']['spec']['initContainers'][0]['image'] ?? null;
        $logs = $this->wasmInstallerRollout($k8s, 60);
        $entry['steps']['verify'] = match (true) {
            $initImage !== $spin->image => 'installer without the shim image init container (image '.($initImage ?? 'none').')',
            is_string($logs) => "installer: {$logs}",
            default => true,
        };
        if ($entry['steps']['verify'] === true) {
            foreach ($logs as $node => $log) {
                $replaced = str_contains($log, 'Replacing the spin shim') && str_contains($log, "with image {$spin->image}");
                $entry['steps']['verify'] = match (true) {
                    ! $replaced => "{$node}: the upstream shim was not replaced: ".mb_substr(trim($log), -300),
                    ! str_contains($log, 'no restart needed') => "{$node}: the container runtime restarted for a binary change",
                    default => true,
                };
                if ($entry['steps']['verify'] !== true) {
                    break;
                }
            }
        }
        $state = $entry['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} wasm-upgrade: ".($entry['steps']['verify'] === true
            ? "Spin shim {$spin->version} replaced the upstream binary on ".implode(', ', array_keys($logs)).' (no container runtime restart)'
            : $entry['steps']['verify']));

        return $entry;
    }

    /**
     * Wait until the wasm-installer DaemonSet is rolled out and ready on every node.
     *
     * @return array<string, string>|string node → log of its installer pod, or what went wrong
     */
    private function wasmInstallerRollout(KubernetesClient $k8s, int $timeout): array|string
    {
        $deadline = time() + $timeout;
        $pending = 'no status yet';
        while (time() < $deadline) {
            $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', WasmManifests::INSTALLER_DS, WasmManifests::NAMESPACE);
            $pods = $k8s->listResourcesOrFail('v1', 'Pod', WasmManifests::NAMESPACE, ['runr.dev/component' => WasmManifests::INSTALLER_DS]);
            if (! $ds) {
                return 'DaemonSet missing';
            }
            if (($failure = WasmInstallService::podFailure($pods)) !== null) {
                return $failure;
            }
            $state = WasmInstallService::rolloutState($ds, $pods);
            if ($state['pending'] === []) {
                $logs = [];
                foreach ($pods as $pod) {
                    $generation = $pod['metadata']['labels']['pod-template-generation'] ?? null;
                    if (! empty($pod['metadata']['deletionTimestamp']) || (string) $generation !== (string) ($ds['metadata']['generation'] ?? '')) {
                        continue;
                    }
                    $logs[$pod['spec']['nodeName'] ?? $pod['metadata']['name']] = (string) $k8s->getPodLogs($pod['metadata']['name'], WasmManifests::NAMESPACE, 'installer');
                }

                return $logs ?: 'no installer pod';
            }
            $pending = implode('; ', $state['pending']);
            sleep(3);
        }

        return "not rolled out within {$timeout} s: {$pending}";
    }

    private function wasmInstall(string $regionId): array
    {
        $entry = $this->makeTestEntry('wasm-install', ['service_type' => 'xaas', 'trigger_type' => 'http'], $regionId);
        $entry['startTime'] = microtime(true);
        $entry['steps'] = ['version' => true, 'deploy' => null, 'ready' => null, 'verify' => null];

        $res = $this->api()->post("/api/knative-regions/{$regionId}/wasm", ['shims' => ['spin']]);
        if ($res->status() !== 202) {
            $entry['steps']['deploy'] = "HTTP {$res->status()}: ".mb_substr((string) $res->json('message'), 0, 200);
            $entry['steps']['ready'] = $entry['steps']['verify'] = 'skipped';

            return $entry;
        }
        $entry['steps']['deploy'] = true;
        $this->line('  WebAssembly support: install queued — '.(string) $res->json('warning'));

        $status = null;
        $deadline = time() + 1500;
        while (time() < $deadline) {
            $status = $this->api()->get("/api/knative-regions/{$regionId}/wasm", ['cluster' => 0])->json();
            if (in_array($status['status'] ?? null, ['ready', 'error'], true)) {
                break;
            }
            sleep(5);
        }

        if (($status['status'] ?? null) !== 'ready') {
            $log = implode("\n", array_slice(explode("\n", trim((string) ($status['message'] ?? ''))), -6));
            $entry['steps']['ready'] = 'status '.($status['status'] ?? 'unknown').': '.mb_substr($log, 0, 400);
            $entry['steps']['verify'] = 'skipped';
            $this->error("  WebAssembly install did not become ready:\n{$log}");

            return $entry;
        }
        $entry['steps']['ready'] = true;
        $this->line(sprintf('  <fg=green>✓</> WebAssembly support ready (%.0f s)', microtime(true) - $entry['startTime']));

        // RuntimeClass + labelled nodes (what Wasm pods are scheduled by)
        $k8s = $this->getE2eKubernetesClient();
        $rc = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', 'wasmtime-spin-v2');
        $nodes = $k8s->listClusterResources('v1', 'Node', ['runr.dev/wasm-spin' => 'true'])['items'] ?? [];
        $entry['steps']['verify'] = match (true) {
            ($rc['handler'] ?? null) !== 'spin-runr' => 'RuntimeClass wasmtime-spin-v2 missing or with handler '.($rc['handler'] ?? 'none'),
            ($rc['scheduling']['nodeSelector']['runr.dev/wasm-spin'] ?? null) !== 'true' => 'RuntimeClass wasmtime-spin-v2 without the runr.dev/wasm-spin nodeSelector',
            $nodes === [] => 'no node labelled runr.dev/wasm-spin=true',
            default => true,
        };
        if ($entry['steps']['verify'] === true) {
            $this->line('  <fg=green>✓</> RuntimeClass wasmtime-spin-v2 (handler spin-runr) on '.implode(', ', array_map(fn ($n) => $n['metadata']['name'], $nodes)));
        }

        return $entry;
    }

    private function verifyWasmHttp(array $test): bool|string
    {
        $body = $this->verifyRuntimeHttp($test);
        if ($body !== true) {
            return $body;
        }

        $svc = $this->getE2eKubernetesClient()->getResourceStatus('serving.knative.dev/v1', 'Service', $test['slug'], $this->getE2eNamespace());
        $spec = $svc['spec']['template']['spec'] ?? [];

        return match (true) {
            ($spec['runtimeClassName'] ?? null) !== 'wasmtime-spin-v2' => 'Knative Service without runtimeClassName wasmtime-spin-v2',
            ($spec['nodeSelector']['runr.dev/wasm-spin'] ?? null) !== 'true' => 'Knative Service without the runr.dev/wasm-spin nodeSelector',
            ($spec['containers'][0]['command'] ?? null) !== ['/'] => 'function container command is not ["/"]',
            default => true,
        };
    }

    private function printWasmColdStarts(array $tests): void
    {
        $runs = max(1, (int) (getenv('WASM_COLD_STARTS') ?: 3));

        $this->info('');
        $this->info("── WebAssembly cold starts (scaled to zero → first response, curl time_total, {$runs} run(s)) ──");
        foreach ($tests as $test) {
            if ($test['steps']['verify'] !== true) {
                continue;
            }
            $times = [];
            for ($i = 1; $i <= $runs; $i++) {
                $t = $this->measureColdStart($test);
                if (! is_float($t)) {
                    $this->line(sprintf('  %-18s run %d: %s', $test['name'], $i, $t));

                    continue;
                }
                $times[] = $t;
                $this->line(sprintf('  %-18s run %d: %.3f s', $test['name'], $i, $t));
            }
            if ($times) {
                sort($times);
                $median = $times[intdiv(count($times), 2)];
                $this->coldStarts[$test['name']] = $median;
                $this->line(sprintf('  %-18s median %.3f s (min %.3f s, max %.3f s)', $test['name'], $median, $times[0], end($times)));
            }
        }
    }

    private function wasmUninstall(string $regionId, array $tests): array
    {
        $entry = $this->makeTestEntry('wasm-uninstall', ['service_type' => 'xaas', 'trigger_type' => 'http'], $regionId);
        $entry['startTime'] = microtime(true);
        $entry['steps'] = ['version' => true, 'deploy' => null, 'ready' => null, 'verify' => null];

        foreach ($tests as $test) {
            if ($test['projectId']) {
                $this->api()->delete("/api/projects/{$test['projectId']}");
            }
        }

        // the Wasm pods must be gone first (uninstall refuses while they run)
        $k8s = $this->getE2eKubernetesClient();
        $deadline = time() + 300;
        while (time() < $deadline) {
            $running = array_filter($k8s->listClusterResources('v1', 'Pod')['items'] ?? [],
                fn ($p) => ($p['spec']['runtimeClassName'] ?? null) === 'wasmtime-spin-v2');
            if ($running === []) {
                break;
            }
            sleep(5);
        }

        $res = $this->api()->delete("/api/knative-regions/{$regionId}/wasm");
        if ($res->status() !== 202) {
            $entry['steps']['deploy'] = "HTTP {$res->status()}: ".mb_substr((string) $res->json('message'), 0, 200);
            $entry['steps']['ready'] = $entry['steps']['verify'] = 'skipped';

            return $entry;
        }
        $entry['steps']['deploy'] = true;

        $status = null;
        $deadline = time() + 900;
        while (time() < $deadline) {
            $status = $this->api()->get("/api/knative-regions/{$regionId}/wasm", ['cluster' => 0])->json();
            if (in_array($status['status'] ?? null, ['not_installed', 'error', 'ready'], true)) {
                break;
            }
            sleep(5);
        }
        $entry['steps']['ready'] = ($status['status'] ?? null) === 'not_installed'
            ? true
            : 'status '.($status['status'] ?? 'unknown').': '.mb_substr((string) ($status['message'] ?? ''), -300);

        $leftover = $k8s->listClusterResources('v1', 'Node', ['runr.dev/wasm' => 'true'])['items'] ?? [];
        $entry['steps']['verify'] = $entry['steps']['ready'] !== true ? 'skipped' : match (true) {
            $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', 'wasmtime-spin-v2') !== null => 'RuntimeClass wasmtime-spin-v2 still exists',
            $leftover !== [] => 'nodes still labelled runr.dev/wasm=true',
            default => true,
        };
        $state = $entry['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$state} wasm-uninstall: ".($entry['steps']['verify'] === true ? 'shims removed from the nodes' : $entry['steps']['verify']));

        return $entry;
    }
}
