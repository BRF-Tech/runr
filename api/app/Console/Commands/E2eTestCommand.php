<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\E2eTestAiTrait;
use App\Console\Commands\Concerns\E2eTestBindingsTrait;
use App\Console\Commands\Concerns\E2eTestGitDeployTrait;
use App\Console\Commands\Concerns\E2eTestImagesTrait;
use App\Console\Commands\Concerns\E2eTestMigrationTrait;
use App\Console\Commands\Concerns\E2eTestObservabilityTrait;
use App\Console\Commands\Concerns\E2eTestResultTrait;
use App\Console\Commands\Concerns\E2eTestRunnerTrait;
use App\Console\Commands\Concerns\E2eTestRuntimesTrait;
use App\Console\Commands\Concerns\E2eTestSandboxApiTrait;
use App\Console\Commands\Concerns\E2eTestSandboxTrait;
use App\Console\Commands\Concerns\E2eTestSetupTrait;
use App\Console\Commands\Concerns\E2eTestShellTrait;
use App\Console\Commands\Concerns\E2eTestSupplyChainTrait;
use App\Console\Commands\Concerns\E2eTestVerifyTrait;
use App\Console\Commands\Concerns\E2eTestWasmTrait;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class E2eTestCommand extends Command
{
    use E2eTestAiTrait;
    use E2eTestBindingsTrait;
    use E2eTestGitDeployTrait;
    use E2eTestImagesTrait;
    use E2eTestMigrationTrait;
    use E2eTestObservabilityTrait;
    use E2eTestResultTrait;
    use E2eTestRunnerTrait;
    use E2eTestRuntimesTrait;
    use E2eTestSandboxApiTrait;
    use E2eTestSandboxTrait;
    use E2eTestSetupTrait;
    use E2eTestShellTrait;
    use E2eTestSupplyChainTrait;
    use E2eTestVerifyTrait;
    use E2eTestWasmTrait;

    protected $signature = 'test:e2e
        {--base-url=http://localhost:8000 : API base URL}
        {--kubeconfig= : Path to kubeconfig file for test region}
        {--timeout=300 : Deploy wait timeout in seconds}
        {--skip-sandbox : Skip sandbox runtime isolation tests}
        {--suite=all : all = every phase incl. runtime plugins; runtimes = runtime plugin functions only; supply-chain = SBOM / scan / signing / admission; ai = model services, offline snapshots, bindings, GPU; bindings = bindings only (data services, KV / object / Postgres / external); sandbox-api = Sandbox API only; wasm = WebAssembly support + Wasm runtimes; observability = tracing backend, traces, replay; git-deploy = git remote, Forgejo push and previews; images = uploaded images (tar + wasm), CaaS from an upload, wasm-exec in C and Q}
        {--sandbox-kata : sandbox-api suite: also install Kata and run a Kata sandbox (nodes with /dev/kvm)}
        {--examples=/tmp/runr-examples : examples/runtimes copied into the container (runtime plugin suite)}
        {--hf-serve-image= : AI suite: hf-serve image of the CPU model services (default ghcr.io/brf-tech/hf-serve:1.1.0, or RUNR_E2E_HF_SERVE_IMAGE)}
        {--ai-tei : AI suite: also serve sentence-transformers/all-MiniLM-L6-v2 on TEI (CPU), or RUNR_E2E_TEI=1}
        {--ai-gpu : AI suite: also install GPU support and run a GPU CaaS project (needs a GPU visible to the nodes), or RUNR_E2E_GPU=1}
        {--gpu-image= : AI suite: image of the GPU CaaS check, built from examples/ai/gpu-caas (or RUNR_E2E_GPU_IMAGE)}
        {--gpu-node=* : AI suite: the GPU nodes GPU support is restricted to (repeatable; default: detected)}
        {--forgejo=/tmp/runr-forgejo.json : git-deploy suite: address and token of the throwaway Forgejo}';

    protected $description = 'Run end-to-end tests against a running API + Knative cluster';

    private string $baseUrl;

    private string $token;

    private int $timeout;

    private ?KubernetesClient $e2eK8s = null;

    private ?string $e2eNamespace = null;

    private ?KnativeRegion $e2eRegion = null;

    public function handle(): int
    {
        $this->baseUrl = rtrim($this->option('base-url'), '/');
        $this->timeout = (int) $this->option('timeout');

        if (! $this->checkApiHealth()) {
            return 1;
        }

        $this->info('Running prerequisites...');
        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);
        $this->call('executor:sync');

        if (! $this->login()) {
            return 1;
        }

        $images = $this->fetchExecutorImages();
        if (empty($images)) {
            $this->error('No executor images found.');

            return 1;
        }

        $regionId = $this->ensureTestRegion();
        if (! $regionId) {
            $this->error('Could not create/find test region.');

            return 1;
        }

        $this->e2eRegion = KnativeRegion::find($regionId);
        $this->e2eK8s = new KubernetesClient($this->e2eRegion->kubeconfig);
        $this->e2eNamespace = $this->e2eRegion->project_namespace ?? $this->e2eRegion->name;

        // Patch CoreDNS so pods can resolve *.{knative_domain} → Traefik
        $this->patchCoreDnsForTesting();
        // RunR's own images are loaded into Kind, not pulled: no digest lookup on their registry
        $this->skipTagResolutionForLoadedImages();

        $startTime = microtime(true);

        // Software supply chain (E2eTestSupplyChainTrait); the other suites run with it off
        if ($this->option('suite') === 'supply-chain') {
            $tests = $this->runSupplyChainSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }
        $this->supplyChainOffForRegion($regionId);

        // Git deploy only: RunR remote push, Forgejo push-to-deploy and a preview (E2eTestGitDeployTrait)
        if ($this->option('suite') === 'git-deploy') {
            $tests = $this->runGitDeploySuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Runtime plugins only: built-in Python + C / Q / Bash example runtimes (E2eTestRuntimesTrait)
        // Bindings only: region data services + one binding of each type (E2eTestBindingsTrait)
        if ($this->option('suite') === 'bindings') {
            $tests = $this->runBindingsSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        if ($this->option('suite') === 'runtimes') {
            $tests = $this->runRuntimeSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Observability only: tracing backend, traced function, trace search / logs, replay (E2eTestObservabilityTrait)
        if ($this->option('suite') === 'observability') {
            $tests = $this->runObservabilitySuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // WebAssembly only: shim install, Rust + JavaScript Wasm functions, cold starts, uninstall (E2eTestWasmTrait)
        if ($this->option('suite') === 'wasm') {
            $tests = $this->runWasmSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Uploaded images only: tar + wasm uploads, CaaS from an upload, wasm-exec C and Q (E2eTestImagesTrait)
        if ($this->option('suite') === 'images') {
            $tests = $this->runImagesSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Sandbox API only: lifecycle, exec, files, processes, pools, quota (E2eTestSandboxApiTrait)
        if ($this->option('suite') === 'sandbox-api') {
            $tests = $this->runSandboxApiSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // AI only: model services (online / offline), snapshots, bindings, GPU (E2eTestAiTrait)
        if ($this->option('suite') === 'ai') {
            $tests = $this->runAiSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Build test matrix and run sequential flow
        $tests = $this->buildTestMatrix($images, $regionId);

        $this->runTestsSequentially($tests);
        $this->runPhaseVerify($tests);

        // Shell (exec) tests — uses an already-deployed project
        $this->runShellTests($tests, $tests);

        // Sandbox tests (unless --skip-sandbox)
        if (! $this->option('skip-sandbox')) {
            $sandboxTests = $this->runSandboxTests($images, $regionId);
            $tests = array_merge($tests, $sandboxTests);
        }

        // Runtime plugins (custom runtimes through the universal wrapper)
        $tests = array_merge($tests, $this->runRuntimeSuite($regionId));

        // Every project pod deployed above (functions, queue consumers) on the selected workers
        if ($placement = $this->runNodePlacementTest($regionId)) {
            $tests[] = $this->finishTest($placement);
        }

        // Last: the migration checks change the region itself — the domain check adds Knative
        // domains (functions deployed afterwards get hosts under them), the cluster check migrates it
        $this->runMigrationTests($regionId, $tests);

        $this->printResultsTable($tests, $startTime);
        $this->saveResultsMarkdown($tests, $startTime);

        $counts = $this->countResults($tests);

        return $counts['failed'] > 0 ? 1 : 0;
    }

    private function checkApiHealth(): bool
    {
        try {
            $response = Http::timeout(10)->get("{$this->baseUrl}/up");
            if (! $response->successful()) {
                $this->error("API not reachable at {$this->baseUrl}");

                return false;
            }
        } catch (\Throwable $e) {
            $this->error("API not reachable: {$e->getMessage()}");

            return false;
        }

        $this->info('API is reachable.');

        return true;
    }

    private function login(): bool
    {
        try {
            $response = Http::post("{$this->baseUrl}/api/auth/login", [
                'login' => config('app.admin.username'),
                'password' => (string) config('app.admin.password'),
            ]);

            $this->token = $response->json('token') ?? '';
            if (! $this->token) {
                $this->error('Login failed — no token received.');

                return false;
            }
        } catch (\Throwable $e) {
            $this->error("Login failed: {$e->getMessage()}");

            return false;
        }

        $this->info('Login successful.');

        return true;
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->token)->accept('application/json')->baseUrl($this->baseUrl);
    }

    private function fetchExecutorImages(): array
    {
        $response = $this->api()->get('/api/settings');

        return $response->json('executor_images') ?? [];
    }

    private function ensureTestRegion(): ?string
    {
        $kubeconfigPath = $this->option('kubeconfig');
        if (! $kubeconfigPath || ! file_exists($kubeconfigPath)) {
            $this->error('--kubeconfig is required for e2e tests.');

            return null;
        }

        return $this->createRegionAndInstallKnative($kubeconfigPath);
    }

    private function createRegionAndInstallKnative(string $kubeconfigPath): ?string
    {
        $kubeconfig = file_get_contents($kubeconfigPath);
        $regionName = 'e2e-'.substr(md5(uniqid()), 0, 8);

        // Create region with Zot registry (PVC, 1Gi) via registry-setup API
        $regionId = $this->createRegionWithRegistry($regionName, $kubeconfig);
        if (! $regionId) {
            return null;
        }

        foreach (['operator', 'serving'] as $component) {
            if (! $this->installComponent($regionId, $component)) {
                $this->error("{$component} install failed");

                return null;
            }
            $this->line("  <fg=green>✓</> {$component} installed");

            if ($component === 'operator' && ! $this->selectWorkerNodes($regionId)) {
                $this->error('Worker selection failed');

                return null;
            }
        }

        // Install Redis (needed for queue trigger tests)
        if (! $this->installRedis($regionId)) {
            $this->error('Redis install failed');

            return null;
        }
        $this->line('  <fg=green>✓</> Redis installed');

        return $regionId;
    }

    /**
     * Every running pod of the project namespace (functions, CronJob runs, queue consumers) is on
     * the region's selected workers. A result entry of the run (null without a selection).
     */
    private function runNodePlacementTest(string $regionId): ?array
    {
        $this->info('');
        $this->info('── Node Placement Test ──');

        $region = KnativeRegion::find($regionId);
        if (! $region || empty($region->selected_workers)) {
            $this->warn('No worker nodes selected — skipping.');

            return null;
        }

        $entry = $this->makeTestEntry('node-placement', ['service_type' => 'placement', 'trigger_type' => 'none'], $regionId);
        $entry['steps'] = ['version' => true, 'deploy' => true, 'ready' => true, 'verify' => null];
        $entry['startTime'] = microtime(true);

        $k8s = new KubernetesClient($region->kubeconfig);
        $selectedWorkers = $region->selected_workers;

        $running = [];
        $deadline = time() + 60;
        while (time() < $deadline) {
            $pods = $k8s->listResources('v1', 'Pod', $region->project_namespace ?? $region->name);
            $running = $this->filterRunningPods($pods['items'] ?? []);
            if (! empty($running)) {
                break;
            }
            sleep(5);
        }

        if (empty($running)) {
            $this->line('  <fg=red>✗</> No running pods after 60s');
            $entry['steps']['verify'] = 'no running pods after 60s';

            return $entry;
        }

        $misplaced = [];
        foreach ($running as $pod) {
            $nodeName = $pod['node'];
            if ($nodeName && ! in_array($nodeName, $selectedWorkers)) {
                $misplaced[] = "{$pod['name']} → {$nodeName}";
            }
        }

        if (! empty($misplaced)) {
            $this->line('  <fg=red>✗</> Wrong nodes: '.implode(', ', $misplaced));
            $entry['steps']['verify'] = 'wrong nodes: '.implode(', ', $misplaced);
        } else {
            $this->line('  <fg=green>✓</> '.count($running).' running pod(s) on selected workers.');
            $entry['steps']['verify'] = true;
        }

        return $entry;
    }

    private function filterRunningPods(array $items): array
    {
        $running = [];
        foreach ($items as $pod) {
            if (($pod['status']['phase'] ?? '') !== 'Running') {
                continue;
            }
            $podName = $pod['metadata']['name'] ?? 'unknown';
            if (str_starts_with($podName, 'runr-redis')) {
                continue;
            }
            $running[] = ['name' => $podName, 'node' => $pod['spec']['nodeName'] ?? null];
        }

        return $running;
    }

    private function getE2eKubernetesClient(): KubernetesClient
    {
        return $this->e2eK8s;
    }

    private function getE2eNamespace(): string
    {
        return $this->e2eNamespace;
    }

    private function getE2eRegion(): ?KnativeRegion
    {
        return $this->e2eRegion;
    }
}
