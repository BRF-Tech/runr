<?php

namespace Tests\Concerns;

use App\Models\ContainerConfig;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\RegionSupplyChainSetting;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\Residency\RegionPolicy;
use App\Services\Runtimes\RuntimeRegistry;
use App\Services\SupplyChain\SigningKeyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * A fake region cluster for the supply-chain tests: records every Kubernetes / service-proxy
 * call, answers the supply-chain Job, its pod and the pod log (protocol of
 * runtime/supply-chain/runr-supply-chain.sh), and creates regions / versions / settings.
 */
trait FakesSupplyChainCluster
{
    protected const SC_ZOT = '10.43.0.5:5000';

    protected const SC_DIGEST = 'sha256:4f2a0c3ad1b6f6c1d9a1f6b1f3f6ab0a1e0f5c3f2a1d9e8c7b6a5f4e3d2c1b0a';

    /** @var list<array{method: string, path: string, query: string, body: array|string}> */
    protected array $k8sCalls = [];

    /** GET path → response body (array: JSON; string: text; null: 404) */
    protected array $k8sGet = [];

    /** status of every Job in the supply-chain namespace */
    protected array $jobStatus = ['succeeded' => 1];

    protected ?string $podLog = null;

    /** path fragment → HTTP status for write calls that should fail */
    protected array $k8sFail = [];

    private int $scProjects = 0;

    protected function fakeSupplyChainCluster(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);
            $method = $request->method();
            $this->k8sCalls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => json_decode($request->body(), true) ?? $request->body()];

            foreach ($this->k8sFail as $fragment => $status) {
                if ($method !== 'GET' && str_contains($path, $fragment)) {
                    return Http::response(['kind' => 'Status', 'message' => 'denied'], $status);
                }
            }

            if ($method === 'GET' && array_key_exists($path, $this->k8sGet)) {
                $body = $this->k8sGet[$path];
                if (is_callable($body)) {
                    $body = $body();
                }

                return $body === null
                    ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                    : Http::response($body, 200);
            }

            if ($method === 'GET' && preg_match('#^/apis/batch/v1/namespaces/runr-supply-chain/jobs/[^/]+$#', $path)) {
                return Http::response(['status' => $this->jobStatus]);
            }
            if ($method === 'GET' && $path === '/api/v1/namespaces/runr-supply-chain/pods') {
                return Http::response(['items' => [['metadata' => ['name' => 'sc-pod']]]]);
            }
            if ($method === 'GET' && $path === '/api/v1/namespaces/runr-supply-chain/pods/sc-pod/log') {
                return $this->podLog === null ? Http::response('', 404) : Http::response($this->podLog, 200);
            }

            return $method === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Status'], 200);
        });
    }

    protected function scKubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    protected function scRegion(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => 'eu-'.(++$this->scProjects),
            'project_namespace' => 'fn',
            'kubeconfig' => $this->scKubeconfig(),
            'is_active' => true,
            'total_cpu_m' => 16000,
            'total_memory_mb' => 32768,
            'registry_mode' => 'internal',
            'registry_status' => 'ready',
            'registry_address' => self::SC_ZOT,
            'registry_namespace' => 'runr-registry',
        ]);
    }

    protected function scSettings(KnativeRegion $region, array $attributes = []): RegionSupplyChainSetting
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $settings->forceFill($attributes)->save();

        return $settings->fresh();
    }

    /** A FaaS project on $region whose version v1 was built into the region registry. */
    protected function scBuiltVersion(KnativeRegion $region, array $version = []): Version
    {
        $n = ++$this->scProjects;
        $project = Project::create(['name' => "orders-{$n}", 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);

        return Version::create($version + [
            'project_id' => $project->id,
            'number' => 1,
            'commit_id' => 'abc',
            'created_by' => 'dev',
            'build_status' => 'ready',
            'build_image' => self::SC_ZOT."/runr-builds/{$project->slug}:v1",
        ]);
    }

    /**
     * An XaaS project of the wasm-rust runtime on $region (WebAssembly support ready there) whose
     * version v1 was built into the region registry as a Spin OCI artifact.
     */
    protected function scWasmVersion(KnativeRegion $region): Version
    {
        $region->forceFill(['sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']])->save();
        ExecutorImage::firstOrCreate(['runtime' => 'wasm-rust', 'version' => '4.1'], [
            'docker_image' => 'ghcr.io/brf-tech/exec-wasm-rust:4.1', 'is_default' => true, 'is_active' => true,
        ]);

        $n = ++$this->scProjects;
        $project = Project::create(['name' => "spin-{$n}", 'service_type' => 'xaas', 'runtime' => 'wasm-rust']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $manifest = app(RuntimeRegistry::class)->require('wasm-rust');
        XaasConfig::create([
            'project_id' => $project->id, 'filename' => $manifest->defaultFilename(), 'handler' => $manifest->handlerFunction(),
            'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30,
        ]);

        return Version::create([
            'project_id' => $project->id,
            'number' => 1,
            'commit_id' => 'abc',
            'created_by' => 'dev',
            'build_status' => 'ready',
            'build_image' => self::SC_ZOT."/runr-builds/{$project->slug}:v1",
        ]);
    }

    /** A CaaS project on $region with version v1 deploying $image. */
    protected function scCaasVersion(KnativeRegion $region, string $image = 'nginx:1.27-alpine'): Version
    {
        $n = ++$this->scProjects;
        $project = Project::create(['name' => "web-{$n}", 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => $image, 'cpu' => '250m', 'memory_mb' => 128, 'trigger_type' => 'http']);

        return Version::create([
            'project_id' => $project->id,
            'number' => 1,
            'commit_id' => 'abc',
            'created_by' => 'dev',
            'build_status' => 'ready',
            'config_snapshot' => ['image' => $image],
        ]);
    }

    /**
     * Log of a finished supply-chain run.
     *
     * @param  array<string, string>  $steps  step → ok | failed | skipped
     */
    protected function scPipelineLog(array $steps, bool $files = true, ?string $digest = self::SC_DIGEST): string
    {
        $lines = ['syft: cataloging packages...'];
        if ($digest) {
            $lines[] = "RUNR_DIGEST {$digest}";
        }
        foreach ($steps as $step => $status) {
            $lines[] = "RUNR_STEP {$step} {$status}".($status === 'failed' ? ' '.base64_encode("{$step} went wrong") : '');
        }
        if ($files) {
            foreach (['sbom-spdx' => 'sbom.spdx.json', 'sbom-cdx' => 'sbom.cdx.json', 'trivy' => 'trivy-report.json', 'trivy-version' => 'trivy-version.json'] as $name => $fixture) {
                $lines[] = "RUNR_FILE {$name} ".base64_encode(gzencode((string) file_get_contents(base_path("tests/Fixtures/supply-chain/{$fixture}"))));
            }
        }
        $lines[] = 'RUNR_DONE';

        return implode("\n", $lines)."\n";
    }

    /** Body of the first POST to a path ending with $suffix. */
    protected function scCreated(string $suffix): ?array
    {
        $call = collect($this->k8sCalls)->first(fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], $suffix));

        return $call['body'] ?? null;
    }

    /** @return list<array> calls matching method + path fragment */
    protected function scCalls(string $method, string $fragment): array
    {
        return array_values(array_filter($this->k8sCalls, fn ($c) => $c['method'] === $method && str_contains($c['path'], $fragment)));
    }

    /** A real P-256 public key (PEM) — fingerprints and policy authorities need a parseable key. */
    protected static function scPublicKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        return openssl_pkey_get_details($key)['key'];
    }

    /** Settings with a ready region key. */
    protected function scSigningReady(KnativeRegion $region, array $attributes = []): RegionSupplyChainSetting
    {
        $pub = self::scPublicKey();

        return $this->scSettings($region, $attributes + [
            'signing_enabled' => true,
            'signing_status' => 'ready',
            'signing_public_key' => $pub,
            'signing_key_fingerprint' => SigningKeyService::fingerprint($pub),
            'signing_private_key' => "-----BEGIN ENCRYPTED SIGSTORE PRIVATE KEY-----\nAAAA\n-----END ENCRYPTED SIGSTORE PRIVATE KEY-----\n",
            'signing_key_password' => 'pw',
            'signing_key_created_at' => now(),
        ]);
    }

    protected function tearDownSupplyChain(): void
    {
        RegionPolicy::resolveTenantUsing(null);
    }
}
