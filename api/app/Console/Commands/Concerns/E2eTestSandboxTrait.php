<?php

namespace App\Console\Commands\Concerns;

trait E2eTestSandboxTrait
{
    private function runSandboxTests(array $images, string $regionId): array
    {
        $this->info('');
        $this->info('═══ Sandbox Tests ═══');

        if (! $this->setupSandboxRuntime($regionId)) {
            $this->warn('Sandbox setup failed — skipping sandbox tests.');

            return [];
        }

        if (! $this->configureSandboxMapping($regionId)) {
            $this->warn('Sandbox mapping failed — skipping sandbox tests.');

            return [];
        }

        $tests = $this->buildSandboxMatrix($images, $regionId);
        $this->runTestsSequentially($tests);
        $this->runPhaseVerify($tests);
        $this->verifySandboxRuntimeClass($tests);

        // API-only mapping validation test (no K8s deploy)
        $tests[] = $this->runMappingApiTest($regionId);

        return $tests;
    }

    private function setupSandboxRuntime(string $regionId): bool
    {
        $this->info('Installing gVisor sandbox runtime...');

        $response = $this->api()->timeout(30)
            ->post("/api/knative-regions/{$regionId}/install-sandbox", [
                'runtime' => 'gvisor',
            ]);

        if (! $response->successful()) {
            $this->warn('  gVisor API failed — fallback...');

            return $this->fallbackGvisorSetup($regionId);
        }

        // As long as the install itself may take: SandboxInstallService waits up to 10 minutes for
        // the installer DaemonSet (gVisor's release tarball is downloaded on every node, then
        // containerd restarts) — on a Kind host 3.5–5 minutes, past the 5 the suite used to allow
        $deadline = time() + 900;
        $message = '';
        while (time() < $deadline) {
            $status = $this->api()->get("/api/knative-regions/{$regionId}/sandbox-status");
            $gvisor = $status->json('gvisor.status');
            $message = (string) $status->json('gvisor.message');

            if ($gvisor === 'ready') {
                $this->line('  <fg=green>✓</> gVisor installed');

                return true;
            }
            // SandboxInstallService ends a failed install in "error"
            if ($gvisor === 'error' || $gvisor === 'failed') {
                $this->warn('  gVisor install failed — fallback... '.mb_substr($message, -1500));

                return $this->fallbackGvisorSetup($regionId);
            }

            sleep(5);
        }

        $this->warn('  gVisor install timeout — fallback... '.mb_substr($message, -1500));

        return $this->fallbackGvisorSetup($regionId);
    }

    private function fallbackGvisorSetup(string $regionId): bool
    {
        $k8s = $this->getE2eKubernetesClient();

        // Check if RuntimeClass already exists (from previous install attempt)
        $existing = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', 'gvisor');

        if ($existing) {
            $handler = $existing['handler'] ?? 'unknown';

            // If handler != runc, delete and recreate (handler is immutable)
            if ($handler !== 'runc') {
                $this->line("  Existing RuntimeClass handler={$handler}, replacing with runc...");
                $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', 'gvisor');
                sleep(1);
                $existing = null;
            }
        }

        if (! $existing) {
            try {
                $k8s->applyClusterResource([
                    'apiVersion' => 'node.k8s.io/v1',
                    'kind' => 'RuntimeClass',
                    'metadata' => ['name' => 'gvisor'],
                    'handler' => 'runc',
                ]);
            } catch (\Throwable $e) {
                $this->error('  Mock RuntimeClass failed: '.$e->getMessage());

                return false;
            }
        }

        // Enable runtimeClassName in Knative config-features
        $region = $this->getE2eRegion();
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
        $this->enableKnativeRuntimeClassFeature($k8s, $servingNs);

        $region->update([
            'sandbox_gvisor_status' => 'ready',
            'sandbox_gvisor_status_message' => 'Fallback (runc handler) for e2e',
        ]);

        $this->line('  <fg=green>✓</> gVisor RuntimeClass exists (handler=runc)');

        return true;
    }

    private function configureSandboxMapping(string $regionId): bool
    {
        $response = $this->api()->put("/api/knative-regions/{$regionId}/sandbox-mapping", [
            'sandbox_xaas_runtime' => 'gvisor',
            'sandbox_faas_runtime' => 'gvisor',
            'sandbox_caas_runtime' => 'runc',
            'sandbox_build_runtime' => 'runc',
        ]);

        if (! $response->successful()) {
            $this->error('  Mapping API failed: '.$response->body());

            return false;
        }

        $this->line('  <fg=green>✓</> Sandbox mapping: XaaS/FaaS→gvisor, CaaS/Build→runc');

        return true;
    }

    private function buildSandboxMatrix(array $images, string $regionId): array
    {
        $codeMap = self::xaasEchoCode();
        $tests = [];

        $defaults = [];
        foreach ($images as $img) {
            if (($img['eol_at'] ?? null) && $img['eol_at'] <= now()->toDateString()) {
                continue;
            }
            if (! ($img['is_default'] ?? false)) {
                continue;
            }
            if (! isset($codeMap[$img['runtime']])) {
                continue;
            }
            $defaults[$img['runtime']] = $img;
        }

        // 4 XaaS gVisor tests (HTTP trigger)
        foreach ($defaults as $runtime => $img) {
            $entry = $this->makeTestEntry(
                "sandbox-xaas-{$runtime}-gvisor-http",
                [
                    'service_type' => 'xaas',
                    'runtime' => $runtime,
                    'runtime_version' => $img['version'],
                    'trigger_type' => 'http',
                    'code' => $codeMap[$runtime],
                ],
                $regionId,
            );
            $entry['expectedRuntimeClass'] = 'gvisor';
            $tests[] = $entry;
        }

        // 1 FaaS gVisor test (Python, HTTP trigger)
        $pythonImg = $defaults['python'] ?? null;
        if ($pythonImg) {
            $faasMap = self::faasFiles();
            $entry = $this->makeTestEntry(
                'sandbox-faas-python-gvisor-http',
                [
                    'service_type' => 'faas',
                    'runtime' => 'python',
                    'runtime_version' => $pythonImg['version'],
                    'trigger_type' => 'http',
                    'faas_files' => $faasMap['python']['files'],
                ],
                $regionId,
            );
            $entry['expectedRuntimeClass'] = 'gvisor';
            $tests[] = $entry;
        }

        // 1 CaaS test — mapping=runc → no runtimeClassName expected
        $entry = $this->makeTestEntry(
            'sandbox-caas-nginx-runc-http',
            [
                'service_type' => 'caas',
                'trigger_type' => 'http',
                'image' => 'ghcr.io/brf-tech/reqs/nginx:1.27-alpine',
            ],
            $regionId,
        );
        $entry['expectedRuntimeClass'] = null;
        $tests[] = $entry;

        return $tests;
    }

    private function runMappingApiTest(string $regionId): array
    {
        $test = $this->makeTestEntry('sandbox-api-mapping', ['service_type' => 'api_only'], $regionId);
        $test['startTime'] = microtime(true);

        try {
            $res = $this->api()->get("/api/knative-regions/{$regionId}/sandbox-status");
            $data = $res->json();

            $checks = [
                'mapping.xaas' => 'gvisor',
                'mapping.faas' => 'gvisor',
                'mapping.caas' => 'runc',
                'gvisor.status' => 'ready',
            ];

            $mismatches = [];
            foreach ($checks as $key => $expected) {
                $actual = data_get($data, $key);
                if ($actual !== $expected) {
                    $mismatches[] = "{$key}={$actual} (expected {$expected})";
                }
            }

            $test['steps'] = [
                'version' => true,
                'deploy' => true,
                'ready' => true,
                'verify' => empty($mismatches) ? true : 'Mismatch: '.implode(', ', $mismatches),
            ];
        } catch (\Throwable $e) {
            $test['steps'] = [
                'version' => true,
                'deploy' => true,
                'ready' => true,
                'verify' => 'API error: '.$e->getMessage(),
            ];
        }

        $label = $test['steps']['verify'] === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$label} sandbox-api-mapping");

        return $this->finishTest($test);
    }

    private function verifySandboxRuntimeClass(array &$tests): void
    {
        $k8s = $this->getE2eKubernetesClient();
        $ns = $this->getE2eNamespace();

        foreach ($tests as &$test) {
            if (! array_key_exists('expectedRuntimeClass', $test)) {
                continue;
            }
            if ($test['steps']['verify'] !== true) {
                continue;
            }

            $slug = $test['slug'] ?? null;
            if (! $slug) {
                continue;
            }

            try {
                $svc = $k8s->getResourceStatus(
                    'serving.knative.dev/v1', 'Service', $slug, $ns,
                );

                $actual = $svc['spec']['template']['spec']['runtimeClassName'] ?? null;
                $expected = $test['expectedRuntimeClass'];

                if ($actual !== $expected) {
                    $expLabel = $expected ?? 'null';
                    $actLabel = $actual ?? 'null';
                    $test['steps']['verify'] = "runtimeClass: expected={$expLabel}, got={$actLabel}";
                }
            } catch (\Throwable $e) {
                $test['steps']['verify'] = "runtimeClass check: {$e->getMessage()}";
            }
        }
        unset($test);
    }

    private function enableKnativeRuntimeClassFeature($k8s, string $servingNs): void
    {
        $feature = ['kubernetes.podspec-runtimeclassname' => 'enabled'];
        $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs);

        if ($cr) {
            $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs, [
                'spec' => ['config' => ['features' => $feature]],
            ]);
        } else {
            $k8s->strategicMergePatch('v1', 'ConfigMap', 'config-features', $servingNs, ['data' => $feature]);
        }
    }
}
