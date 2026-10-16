<?php

namespace App\Console\Commands\Concerns;

use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\SupplyChain\ImageRef;
use App\Services\SupplyChain\SupplyChainManifests;
use Illuminate\Support\Facades\Process;

/**
 * E2E of the software supply chain (test:e2e --suite=supply-chain, runtime/lib/test-supply-chain.sh)
 * on Kind + Knative with the region's internal Zot:
 *
 *  sc-signing-key       region key generated in the cluster (cosign k8s:// Secret)
 *  sc-build             FaaS Python with requirements.txt: Kaniko build → deploy → ready
 *  sc-report            supply-chain run: SBOM, referrers, scan, signature, attestations all ok
 *  sc-sbom-download     SPDX + CycloneDX downloads parse and list packages
 *  sc-zot-referrers     Zot: both SBOMs are OCI referrers of the digest; .sig and .att tags exist
 *  sc-admission         policy-controller installed, project namespace labelled
 *  sc-signed-admitted   the signed function deploys again (pods admitted)
 *  sc-unsigned-denied   an unsigned image under runr-builds/ is refused at pod admission
 *  sc-wasm-gated        a Spin app (wasm-rust) under an enforced gate that requires a scan:
 *                       signed, SBOM / scan skipped with the reason, deployed without an
 *                       override, admitted and answering (WebAssembly support installed and the
 *                       app built before sc-admission, like sc-build; removed afterwards)
 *  sc-policy-gate       deploy blocked by CRITICAL/HIGH limits, then let through by an override
 *  sc-migration-pinned  region migration without the region's data location is refused
 *  sc-admission-removed admission policy uninstalled, namespace label gone
 *
 * Other suites run with the supply chain off for their region (supplyChainOffForRegion()).
 */
trait E2eTestSupplyChainTrait
{
    private function runSupplyChainSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── Supply chain ──');

        $results = [];
        $base = "/api/knative-regions/{$regionId}/supply-chain";

        $res = $this->api()->put($base, [
            'sbom_enabled' => true,
            'scan_enabled' => true,
            'signing_enabled' => true,
            'attest_enabled' => true,
            'verify_on_deploy' => true,
            'gate_mode' => 'warn',
            'data_location' => 'E2E-LOCAL',
        ]);
        if (! $res->successful()) {
            return [$this->scEntry('sc-settings', "PUT supply-chain: HTTP {$res->status()} ".$res->body())];
        }

        // 1. Region signing key
        $results[] = $this->scCheck('sc-signing-key', function () use ($base) {
            $res = $this->api()->post("{$base}/signing-key");
            if ($res->status() !== 202) {
                return "POST signing-key: HTTP {$res->status()}";
            }
            $settings = $this->scWaitFor(fn () => $this->api()->get($base)->json('data'), fn ($d) => in_array($d['signing_status'] ?? null, ['ready', 'failed'], true), 300);

            return ($settings['signing_status'] ?? null) === 'ready' && ! empty($settings['signing_key_fingerprint'])
                ? true
                : 'signing key: '.($settings['signing_status'] ?? 'timeout').' '.($settings['signing_message'] ?? '');
        });

        // 2. A built function
        $build = $this->makeTestEntry('sc-build', [
            'service_type' => 'faas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'faas_files' => self::faasFiles()['python']['files'],
        ], $regionId);
        $tests = [$build];
        $this->runTestsSequentially($tests);
        $build = $tests[0];
        $build['steps']['verify'] = $build['steps']['ready'] === true ? true : 'not ready';
        $results[] = $build;

        if ($build['steps']['ready'] !== true || ! $build['versionId']) {
            return array_merge($results, [$this->scEntry('sc-report', 'build did not deploy')]);
        }

        $versionUrl = "/api/projects/{$build['projectId']}/versions/{$build['versionId']}";

        // 3. Supply-chain report of the build
        $report = null;
        $results[] = $this->scCheck('sc-report', function () use ($versionUrl, &$report) {
            $report = $this->scWaitFor(fn () => $this->api()->get("{$versionUrl}/security")->json('data.report'),
                fn ($r) => in_array($r['status'] ?? null, ['completed', 'failed'], true), 1200);
            if (! $report) {
                return 'no report within 20 min';
            }
            foreach (['sbom_status', 'referrer_status', 'scan_status', 'signature_status', 'attestation_status'] as $step) {
                if (($report[$step] ?? null) !== 'ok') {
                    return "{$step} = ".($report[$step] ?? 'missing').' ('.json_encode($report['step_results'] ?? []).')';
                }
            }
            if (empty($report['image_digest']) || ($report['packages'] ?? 0) < 1) {
                return 'digest or package count missing';
            }
            $this->line("    {$report['image_ref']} {$report['image_digest']}: {$report['packages']} packages, "
                .json_encode($report['counts']).' — '.count($report['findings'] ?? []).' findings stored');

            return true;
        });

        // 4. SBOM downloads
        $results[] = $this->scCheck('sc-sbom-download', function () use ($versionUrl) {
            $spdx = $this->api()->get("{$versionUrl}/sbom", ['format' => 'spdx-json']);
            $cdx = $this->api()->get("{$versionUrl}/sbom", ['format' => 'cyclonedx-json']);
            if (! $spdx->successful() || ! str_starts_with((string) $spdx->json('spdxVersion'), 'SPDX-')) {
                return "SPDX download: HTTP {$spdx->status()}";
            }
            if (! $cdx->successful() || $cdx->json('bomFormat') !== 'CycloneDX' || count($cdx->json('components') ?? []) < 1) {
                return "CycloneDX download: HTTP {$cdx->status()}";
            }

            return true;
        });

        // 5. Zot: referrers + signature / attestation tags
        $results[] = $this->scCheck('sc-zot-referrers', function () use (&$report) {
            if (! $report) {
                return 'no report';
            }
            $ref = ImageRef::parse($report['image_ref']);
            $repo = $ref->repository;
            $digest = $report['image_digest'];
            $ns = $this->e2eRegion->registry_namespace ?: 'runr-registry';

            $referrers = $this->e2eK8s->serviceProxyGet($ns, 'runr-zot', 5000, "v2/{$repo}/referrers/{$digest}", [], ['Accept' => 'application/vnd.oci.image.index.v1+json']);
            $types = array_column($referrers?->json('manifests') ?? [], 'artifactType');
            foreach (['application/spdx+json', 'application/vnd.cyclonedx+json'] as $type) {
                if (! in_array($type, $types, true)) {
                    return "referrer {$type} missing (".implode(', ', $types).')';
                }
            }

            $tags = $this->e2eK8s->serviceProxyGet($ns, 'runr-zot', 5000, "v2/{$repo}/tags/list")?->json('tags') ?? [];
            $hex = substr($digest, strlen('sha256:'));
            foreach (["sha256-{$hex}.sig", "sha256-{$hex}.att"] as $tag) {
                if (! in_array($tag, $tags, true)) {
                    return "tag {$tag} missing";
                }
            }

            return true;
        });

        // The Spin app of sc-wasm-gated is built now, before the admission policy (scWasmBuild())
        $wasm = $this->scWasmBuild($regionId);

        // 6. Admission policy
        $results[] = $this->scCheck('sc-admission', function () use ($base) {
            $res = $this->api()->post("{$base}/admission", ['mode' => 'enforce']);
            if ($res->status() !== 202) {
                return "POST admission: HTTP {$res->status()} ".$res->json('message');
            }
            $settings = $this->scWaitFor(fn () => $this->api()->get($base)->json('data'), fn ($d) => in_array($d['admission_status'] ?? null, ['ready', 'failed'], true), 600);
            if (($settings['admission_status'] ?? null) !== 'ready') {
                return 'admission: '.($settings['admission_status'] ?? 'timeout').' '.($settings['admission_message'] ?? '');
            }
            $ns = $this->e2eK8s->getClusterResource('v1', 'Namespace', $this->e2eNamespace);

            return ($ns['metadata']['labels'][config('supply_chain.admission.namespace_label')] ?? null) === 'true'
                ? true
                : 'project namespace not labelled';
        });

        // 7. The signed function deploys again
        $results[] = $this->scCheck('sc-signed-admitted', function () use ($build) {
            $redeploy = $build;
            $redeploy['steps']['deploy'] = null;
            $redeploy['steps']['ready'] = null;
            if (! $this->deploySingleTest($redeploy)) {
                return 'redeploy: '.$redeploy['steps']['deploy'];
            }
            $this->waitSingleReady($redeploy);

            return $redeploy['steps']['ready'] === true ? true : 'redeploy not ready: '.$redeploy['steps']['ready'];
        });

        // 8. An unsigned image under runr-builds/ is refused
        $results[] = $this->scCheck('sc-unsigned-denied', fn () => $this->scUnsignedImageDenied());

        // 8b. A Spin app deploys under an enforced gate that requires a scan: signed, admitted, answering
        $results[] = $this->scCheck('sc-wasm-gated', fn () => $this->scWasmUnderEnforcedGate($base, $regionId, $wasm));

        // 9. Policy gate: limits below the findings block, an override lets the deploy through
        $results[] = $this->scCheck('sc-policy-gate', function () use ($base, $build, &$report) {
            $critical = (int) ($report['counts']['critical'] ?? 0);
            $high = (int) ($report['counts']['high'] ?? 0);
            if ($critical + $high === 0) {
                $this->line('    (image has no CRITICAL/HIGH findings — gate blocking not exercised)');

                return true;
            }

            $this->api()->put($base, [
                'gate_mode' => 'enforce',
                'gate_max_critical' => max(0, $critical - 1),
                'gate_max_high' => max(0, $high - 1),
            ]);

            $blocked = $this->api()->post("/api/projects/{$build['projectId']}/deploy", ['version_id' => $build['versionId']]);
            if ($blocked->status() !== 422 || $blocked->json('code') !== 'supply_chain_policy') {
                return "expected 422 supply_chain_policy, got HTTP {$blocked->status()} ".$blocked->json('code');
            }

            $override = $this->api()->post("/api/projects/{$build['projectId']}/deploy", [
                'version_id' => $build['versionId'],
                'override_policy' => true,
                'override_reason' => 'e2e: accepted risk',
            ]);
            $this->api()->put($base, ['gate_mode' => 'warn']);

            return $override->status() === 202 ? true : "override: HTTP {$override->status()} ".$override->json('message');
        });

        // 10. Region migration of a pinned region needs its data location
        $results[] = $this->scCheck('sc-migration-pinned', function () use ($regionId) {
            $res = $this->api()->post("/api/knative-regions/{$regionId}/migrate", ['kubeconfig' => 'apiVersion: v1']);

            return $res->status() === 422 && $res->json('code') === 'region_location_mismatch'
                ? true
                : "expected 422 region_location_mismatch, got HTTP {$res->status()}";
        });

        // 11. Admission removed
        $results[] = $this->scCheck('sc-admission-removed', function () use ($base) {
            $this->api()->delete("{$base}/admission");
            $settings = $this->scWaitFor(fn () => $this->api()->get($base)->json('data'), fn ($d) => in_array($d['admission_status'] ?? null, ['not_installed', 'failed'], true), 300);
            $ns = $this->e2eK8s->getClusterResource('v1', 'Namespace', $this->e2eNamespace);

            return ($settings['admission_status'] ?? null) === 'not_installed'
                && ! isset($ns['metadata']['labels'][config('supply_chain.admission.namespace_label')])
                ? true
                : 'admission: '.($settings['admission_status'] ?? 'timeout');
        });

        return $results;
    }

    /**
     * Copies an image into <zot>/runr-builds/sc-unsigned-e2e:v1 (a Job of the tools image), then
     * creates a pod with it in the project namespace: the webhook must refuse it.
     */
    private function scUnsignedImageDenied(): bool|string
    {
        $registry = rtrim((string) $this->e2eRegion->fresh()->registry_address, '/');
        $target = "{$registry}/runr-builds/sc-unsigned-e2e:v1";
        $ns = SupplyChainManifests::namespace();

        $job = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => ['name' => 'sc-e2e-unsigned-copy', 'namespace' => $ns],
            'spec' => [
                'backoffLimit' => 0,
                'template' => ['spec' => [
                    'restartPolicy' => 'Never',
                    'containers' => [[
                        'name' => 'copy',
                        'image' => (string) config('supply_chain.tools_image'),
                        'command' => ['oras', 'cp', '--to-plain-http', 'ghcr.io/brf-tech/runr/reqs/busybox:latest', $target],
                    ]],
                ]],
            ],
        ];
        $this->e2eK8s->deleteResource('batch/v1', 'Job', 'sc-e2e-unsigned-copy', $ns, 'Background');
        $this->e2eK8s->createResource($job, $ns);

        $done = $this->scWaitFor(fn () => $this->e2eK8s->getResourceStatus('batch/v1', 'Job', 'sc-e2e-unsigned-copy', $ns)['status'] ?? [],
            fn ($s) => ($s['succeeded'] ?? 0) >= 1 || ($s['failed'] ?? 0) >= 1, 300);
        if (($done['succeeded'] ?? 0) < 1) {
            return 'could not copy the unsigned test image into Zot';
        }

        $kubeconfig = (string) $this->option('kubeconfig');
        $run = Process::timeout(120)->run([
            'kubectl', '--kubeconfig', $kubeconfig, '-n', $this->e2eNamespace,
            'run', 'sc-unsigned-e2e', "--image={$target}", '--restart=Never', '--command', '--', 'sleep', '5',
        ]);
        Process::run(['kubectl', '--kubeconfig', $kubeconfig, '-n', $this->e2eNamespace, 'delete', 'pod', 'sc-unsigned-e2e', '--ignore-not-found']);

        $output = $run->output().$run->errorOutput();
        if ($run->successful()) {
            return 'the unsigned image was admitted';
        }

        return str_contains($output, 'denied') || str_contains($output, 'admission webhook')
            ? true
            : 'kubectl run failed for another reason: '.mb_substr($output, 0, 300);
    }

    /**
     * The Spin app of sc-wasm-gated: WebAssembly support (spin) installed and the Rust example built
     * as a Spin app, before sc-admission installs the admission policy — as sc-build's Kaniko build
     * is. The build Job runs the runtime's toolchain image (ghcr.io/brf-tech/runr/exec-wasm-rust:<version>)
     * in the project namespace, and policy-controller admits a pod there only with every image
     * resolved to a digest on its registry: the harness builds the toolchain image from the checkout
     * and loads it into Kind, so before a release it is on no registry and policy-controller would
     * refuse the Job ("must be an image digest"). A published toolchain image resolves and passes
     * runr-system-images (ghcr.io/brf-tech/runr/**, AdmissionManifestsTest).
     *
     * @return array{installed: bool, test: ?array, error: ?string}
     */
    private function scWasmBuild(string $regionId): array
    {
        $dir = rtrim((string) ($this->option('examples') ?: '/tmp/runr-examples'), '/').'/wasm';
        if (! is_file("{$dir}/rust/lib.rs")) {
            return ['installed' => false, 'test' => null, 'error' => "WebAssembly examples not found at {$dir}"];
        }

        $this->line('  sc-wasm-gated: WebAssembly support and the Spin app build (before the admission policy)');
        $install = $this->wasmInstall($regionId);
        if ($install['steps']['verify'] !== true) {
            return ['installed' => false, 'test' => null,
                'error' => 'WebAssembly support: '.($install['steps']['ready'] !== true ? $install['steps']['ready'] : $install['steps']['verify'])];
        }

        $test = $this->runtimeEntry('sc-wasm', 'wasm-rust', 'http', (string) file_get_contents("{$dir}/rust/lib.rs"), $regionId, ['body' => 'Hello, RunR! (from Rust, Wasm)']);
        $this->setupSingleTest($test);
        if ($test['steps']['version'] !== true) {
            return ['installed' => true, 'test' => $test, 'error' => 'project: '.$test['steps']['version']];
        }
        if (! $this->waitSingleBuild($test)) {
            return ['installed' => true, 'test' => $test, 'error' => 'build: '.$test['steps']['version']];
        }

        return ['installed' => true, 'test' => $test, 'error' => null];
    }

    /**
     * WebAssembly under the region's strictest gate (enforce, a required scan, no CRITICAL / HIGH,
     * signature verified on deploy, admission enforced): the Spin app scWasmBuild() built has its
     * report signed with the scan steps skipped (image kind wasm), the deploy is let through without
     * an override, the pod admitted and answering; then the project and WebAssembly support are
     * removed and the gate goes back to warn.
     *
     * @param  array{installed: bool, test: ?array, error: ?string}  $wasm
     */
    private function scWasmUnderEnforcedGate(string $base, string $regionId, array $wasm): bool|string
    {
        $test = $wasm['test'];

        try {
            if ($wasm['error'] !== null) {
                return $wasm['error'];
            }

            $report = $this->scWaitFor(fn () => $this->api()->get("/api/projects/{$test['projectId']}/versions/{$test['versionId']}/security")->json('data.report'),
                fn ($r) => in_array($r['status'] ?? null, ['completed', 'failed'], true), 600);
            $problem = match (true) {
                ! $report => 'no supply-chain report within 10 min',
                ($report['image_kind'] ?? null) !== 'wasm' => 'image kind '.($report['image_kind'] ?? 'missing').', not wasm',
                ($report['signature_status'] ?? null) !== 'ok' => 'signature '.($report['signature_status'] ?? 'missing').' ('.json_encode($report['step_results'] ?? []).')',
                ($report['sbom_status'] ?? null) !== 'skipped' || ($report['scan_status'] ?? null) !== 'skipped' => "sbom {$report['sbom_status']} / scan {$report['scan_status']}, both should be skipped",
                ! str_contains((string) ($report['step_results']['scan']['message'] ?? ''), 'no container filesystem') => 'the skipped scan carries no reason',
                default => null,
            };
            if ($problem) {
                return $problem;
            }
            $this->line("    {$report['image_ref']} {$report['image_digest']}: signed, SBOM / scan skipped (Spin app)");

            $gate = $this->api()->put($base, ['gate_mode' => 'enforce', 'gate_require_scan' => true, 'gate_max_critical' => 0, 'gate_max_high' => 0]);
            if (! $gate->successful()) {
                return "PUT supply-chain gate: HTTP {$gate->status()}";
            }

            $deploy = $this->api()->post("/api/projects/{$test['projectId']}/deploy", ['version_id' => $test['versionId']]);
            if ($deploy->status() !== 202) {
                return "deploy under the enforced gate: HTTP {$deploy->status()} ".$deploy->json('code').' '.mb_substr((string) $deploy->json('message'), 0, 300);
            }

            $this->waitSingleReady($test);
            if ($test['steps']['ready'] !== true) {
                return 'not ready (admission or the shim): '.$test['steps']['ready'];
            }

            return $this->verifyWasmHttp($test);
        } finally {
            $this->api()->put($base, ['gate_mode' => 'warn', 'gate_require_scan' => false, 'gate_max_critical' => null, 'gate_max_high' => null]);
            if ($wasm['installed']) {
                $uninstall = $this->wasmUninstall($regionId, $test ? [$test] : []);
                if ($uninstall['steps']['verify'] !== true) {
                    $this->warn('    WebAssembly uninstall after sc-wasm-gated: '.json_encode($uninstall['steps']));
                }
            }
        }
    }

    /** The supply chain stays out of the other suites (their Kind run has no supply-chain image). */
    private function supplyChainOffForRegion(string $regionId): void
    {
        $region = KnativeRegion::find($regionId);
        if (! $region) {
            return;
        }

        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $settings->forceFill(['sbom_enabled' => false, 'scan_enabled' => false, 'signing_enabled' => false])->save();
    }

    private function scCheck(string $name, callable $check): array
    {
        $entry = $this->scEntry($name, null);
        $entry['startTime'] = microtime(true);

        try {
            $result = $check();
        } catch (\Throwable $e) {
            $result = $e->getMessage();
        }

        $entry['steps']['verify'] = $result === true ? true : (string) $result;
        $mark = $result === true ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line("  {$mark} {$name}".($result === true ? '' : ": {$result}"));

        return $this->finishTest($entry);
    }

    private function scEntry(string $name, ?string $failure): array
    {
        $entry = $this->makeTestEntry($name, ['service_type' => 'faas', 'trigger_type' => 'http'], '');
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => $failure ?? null];

        return $entry;
    }

    /**
     * Polls $fetch every 5 s until $done($value) or the timeout; returns the last value.
     */
    private function scWaitFor(callable $fetch, callable $done, int $timeout): mixed
    {
        $deadline = time() + $timeout;
        $value = null;
        do {
            try {
                $value = $fetch();
                if ($value !== null && $done($value)) {
                    return $value;
                }
            } catch (\Throwable) {
                // API / cluster hiccup: try again
            }
            sleep(5);
        } while (time() < $deadline);

        return $value;
    }
}
