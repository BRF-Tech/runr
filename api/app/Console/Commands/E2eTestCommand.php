<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\E2eTestAdmissionTrait;
use App\Console\Commands\Concerns\E2eTestAiTrait;
use App\Console\Commands\Concerns\E2eTestApiProductTrait;
use App\Console\Commands\Concerns\E2eTestBindingsTrait;
use App\Console\Commands\Concerns\E2eTestCertManagerTrait;
use App\Console\Commands\Concerns\E2eTestDeployStrategiesTrait;
use App\Console\Commands\Concerns\E2eTestEnvironmentsTrait;
use App\Console\Commands\Concerns\E2eTestGitDeployTrait;
use App\Console\Commands\Concerns\E2eTestGlobalProjectsTrait;
use App\Console\Commands\Concerns\E2eTestImagesTrait;
use App\Console\Commands\Concerns\E2eTestInternalNetworkTrait;
use App\Console\Commands\Concerns\E2eTestMigrationTrait;
use App\Console\Commands\Concerns\E2eTestNetworkTrait;
use App\Console\Commands\Concerns\E2eTestObservabilityTrait;
use App\Console\Commands\Concerns\E2eTestOrgTransferTrait;
use App\Console\Commands\Concerns\E2eTestReconcileTrait;
use App\Console\Commands\Concerns\E2eTestRegionMoveSecretsTrait;
use App\Console\Commands\Concerns\E2eTestRegionMoveTrait;
use App\Console\Commands\Concerns\E2eTestResultTrait;
use App\Console\Commands\Concerns\E2eTestRunnerTrait;
use App\Console\Commands\Concerns\E2eTestRuntimePatchesTrait;
use App\Console\Commands\Concerns\E2eTestRuntimesTrait;
use App\Console\Commands\Concerns\E2eTestSandboxApiTrait;
use App\Console\Commands\Concerns\E2eTestSandboxTrait;
use App\Console\Commands\Concerns\E2eTestSecretsTrait;
use App\Console\Commands\Concerns\E2eTestSetupTrait;
use App\Console\Commands\Concerns\E2eTestShellTrait;
use App\Console\Commands\Concerns\E2eTestSupplyChainTrait;
use App\Console\Commands\Concerns\E2eTestTriggerSourcesTrait;
use App\Console\Commands\Concerns\E2eTestTriggersTrait;
use App\Console\Commands\Concerns\E2eTestVerifyTrait;
use App\Console\Commands\Concerns\E2eTestWarmerTrait;
use App\Console\Commands\Concerns\E2eTestWasmTrait;
use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class E2eTestCommand extends Command
{
    use E2eTestAdmissionTrait;
    use E2eTestAiTrait;
    use E2eTestApiProductTrait;
    use E2eTestBindingsTrait;
    use E2eTestCertManagerTrait;
    use E2eTestDeployStrategiesTrait;
    use E2eTestEnvironmentsTrait;
    use E2eTestGitDeployTrait;
    use E2eTestGlobalProjectsTrait;
    use E2eTestImagesTrait;
    use E2eTestInternalNetworkTrait;
    use E2eTestMigrationTrait;
    use E2eTestNetworkTrait;
    use E2eTestObservabilityTrait;
    use E2eTestOrgTransferTrait;
    use E2eTestReconcileTrait;
    use E2eTestRegionMoveSecretsTrait;
    use E2eTestRegionMoveTrait;
    use E2eTestResultTrait;
    use E2eTestRunnerTrait;
    use E2eTestRuntimePatchesTrait;
    use E2eTestRuntimesTrait;
    use E2eTestSandboxApiTrait;
    use E2eTestSandboxTrait;
    use E2eTestSecretsTrait;
    use E2eTestSetupTrait;
    use E2eTestShellTrait;
    use E2eTestSupplyChainTrait;
    use E2eTestTriggerSourcesTrait;
    use E2eTestTriggersTrait;
    use E2eTestVerifyTrait;
    use E2eTestWarmerTrait;
    use E2eTestWasmTrait;

    protected $signature = 'test:e2e
        {--base-url=http://localhost:8000 : API base URL}
        {--kubeconfig= : Path to kubeconfig file for test region}
        {--timeout=300 : Deploy wait timeout in seconds}
        {--skip-sandbox : Skip sandbox runtime isolation tests}
        {--suite=all : all = every phase incl. runtime plugins; runtimes = runtime plugin functions only; supply-chain = SBOM / scan / signing / admission; ai = model services, offline snapshots, bindings, GPU; bindings = bindings only (data services, KV / object / Postgres / external); sandbox-api = Sandbox API only; wasm = WebAssembly support + Wasm runtimes; observability = tracing backend, traces, replay; reconcile = region:reconcile tracing backend and registry pre-warm steps; network = private ingress (cluster-local routes off the LoadBalancer), NetworkPolicies, an earlier release\'s region moved under load; internal-network = the authz, allow lists, cross-organization grants, access modes, audit mode, an earlier release\'s region moved under load; git-deploy = git remote, Forgejo push and previews; images = uploaded images (tar + wasm), CaaS from an upload, wasm-exec in C and Q; admission = project stop / resume, region status blocks, fixed-region allocations (Billing module on); deploy-strategies = blue-green (test URL, promote, warm previous, rollback) and canary (traffic split, automatic promotion, abort) rollouts; region-move = the region moved to the cluster of --move-kubeconfig and rolled back (rebuilds, report, restored state, secret values in the region\'s Secret and OpenBao copied there and back, references); runtime-patches = signed patch feed, workloads by digest, deadline migration with rollback, apply and pin; warmer = the timed warmer: schedule preview, min-scale rendered into the template, PodAutoscaler patches without a restart, schedule turned off and redeployed; triggers = trigger lists: webhook inbox, dead letter + replay, platform events, schedule and topics through the event gateway; trigger-sources = S3 object events on the region object store, Kafka, NATS JetStream, RabbitMQ, MQTT, Postgres CDC with the slot cleanup, IMAP and a schedule time zone through the event gateway; secrets = pass-through secret values, secrets:move-to-region, External Secrets Operator with an OpenBao reference; environments = staging next to production, promotion, rollback restoring the config; cert-manager = cert-manager installed with Serving, the region CA issuer, HTTPS of a custom domain, removal and reinstall; global-projects = a global project in the e2e region and a second region on the cluster of --global-kubeconfig (PowerDNS, rollout, HA failover); api-product = gateway API keys, plans, usage, JWT and gateway-only through the gateway-auth of the region; org-transfer = organization export with binding data and images, download, chunked import, plan, apply with data restore and deploy}
        {--sandbox-kata : sandbox-api suite: also install Kata and run a Kata sandbox (nodes with /dev/kvm)}
        {--sandbox-cases= : sandbox-api suite: "kata" runs only the Kata cases, their setup and the quota case (or RUNR_E2E_SANDBOX_CASES)}
        {--examples=/tmp/runr-examples : examples/runtimes copied into the container (runtime plugin suite)}
        {--hf-serve-image= : AI suite: hf-serve image of the CPU model services (default ghcr.io/brf-tech/runr/hf-serve:1.1.1, or RUNR_E2E_HF_SERVE_IMAGE)}
        {--ai-tei : AI suite: also serve sentence-transformers/all-MiniLM-L6-v2 on TEI (CPU), or RUNR_E2E_TEI=1}
        {--ai-gpu : AI suite: also install GPU support and run a GPU CaaS project (needs a GPU visible to the nodes), or RUNR_E2E_GPU=1}
        {--gpu-image= : AI suite: image of the GPU CaaS check, built from examples/ai/gpu-caas (or RUNR_E2E_GPU_IMAGE)}
        {--gpu-node=* : AI suite: the GPU nodes GPU support is restricted to (repeatable; default: detected)}
        {--ai-cases= : AI suite: only these cases, comma-separated, plus what they need (e.g. ai-gpu-caas; or RUNR_E2E_AI_CASES)}
        {--gpu-abort-file=/tmp/runr-e2e-gpu-abort : AI suite: the GPU case stops with the text of this file once it exists (runtime/lib/test-ai.sh writes it when the host GPU needs a reset)}
        {--forgejo=/tmp/runr-forgejo.json : git-deploy suite: address and token of the throwaway Forgejo}
        {--move-kubeconfig= : region-move suite: kubeconfig of the cluster the region moves to (runtime/lib/test-region-move.sh)}
        {--mqtt-image= : trigger-sources suite: another image with mosquitto and mosquitto_pub for the MQTT source check (or RUNR_E2E_MQTT_IMAGE; default the reqs mirror\'s eclipse-mosquitto)}
        {--secrets-db : secrets suite: also dynamic Postgres credentials from OpenBao (installs the Postgres data service), or RUNR_E2E_SECRETS_DB=1}
        {--global-kubeconfig= : global-projects suite: kubeconfig of the second cluster, the second region (runtime/lib/test-global-projects.sh)}
        {--global-agent-image= : global-projects suite: region agent image built from this checkout and loaded into both clusters (empty: the published agent)}
        {--global-cases= : global-projects suite: only these cases, comma-separated, plus the setup and what they need (e.g. gp-postgres-replica; or RUNR_E2E_GLOBAL_CASES)}';

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

        // Organization export / import (E2eTestOrgTransferTrait, docs/org-export.md)
        if ($this->option('suite') === 'org-transfer') {
            $tests = $this->runOrgTransferSuite($regionId);
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

        // Region network only: private ingress, NetworkPolicies, the move of an earlier release's region (E2eTestNetworkTrait)
        if ($this->option('suite') === 'network') {
            $tests = $this->runNetworkSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Internal network only: authz, allow lists, access modes, the move of an earlier release's region (E2eTestInternalNetworkTrait)
        if ($this->option('suite') === 'internal-network') {
            $tests = $this->runInternalNetworkSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // region:reconcile only: tracing backend update, registry pre-warm Job (E2eTestReconcileTrait)
        if ($this->option('suite') === 'reconcile') {
            $tests = $this->runReconcileSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // cert-manager only: install with Serving, region CA issuer, custom-domain HTTPS, removal (E2eTestCertManagerTrait)
        if ($this->option('suite') === 'cert-manager') {
            $tests = $this->runCertManagerSuite($regionId);
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

        // Project admission only: stop / resume, region status blocks, fixed-region allocations (E2eTestAdmissionTrait)
        if ($this->option('suite') === 'admission') {
            $tests = $this->runAdmissionSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Timed warmer only: preview, template min-scale, PodAutoscaler patches (E2eTestWarmerTrait)
        if ($this->option('suite') === 'warmer') {
            $tests = $this->runWarmerSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Deploy strategies only: blue-green and canary rollouts of one function (E2eTestDeployStrategiesTrait)
        if ($this->option('suite') === 'deploy-strategies') {
            $tests = $this->runDeployStrategiesSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Environments only: staging next to production, promotion, rollback with the config (E2eTestEnvironmentsTrait)
        if ($this->option('suite') === 'environments') {
            $tests = $this->runEnvironmentsSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Region move only: to the cluster of --move-kubeconfig and back (E2eTestRegionMoveTrait)
        if ($this->option('suite') === 'region-move') {
            $tests = $this->runRegionMoveSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Runtime patches only: signed feed, digests, deadline migration + rollback, apply, pin (E2eTestRuntimePatchesTrait)
        if ($this->option('suite') === 'runtime-patches') {
            $tests = $this->runRuntimePatchesSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Broker / external trigger sources: S3, Kafka, NATS, RabbitMQ, MQTT, CDC, IMAP (E2eTestTriggerSourcesTrait)
        if ($this->option('suite') === 'trigger-sources') {
            $tests = $this->runTriggerSourcesSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Triggers only: webhook inbox, dead letter + replay, platform events, schedule, topics (E2eTestTriggersTrait)
        if ($this->option('suite') === 'triggers') {
            $tests = $this->runTriggersSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Project secrets only: pass-through values, the database move, store references (E2eTestSecretsTrait)
        if ($this->option('suite') === 'secrets') {
            $tests = $this->runSecretsSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // Global projects only: a second region, PowerDNS, rollout, failover (E2eTestGlobalProjectsTrait)
        if ($this->option('suite') === 'global-projects') {
            $tests = $this->runGlobalProjectsSuite($regionId);
            $this->printResultsTable($tests, $startTime);
            $this->saveResultsMarkdown($tests, $startTime);

            return $this->countResults($tests)['failed'] > 0 ? 1 : 0;
        }

        // API product only: keys, plans, usage, JWT, gateway-only on the region's gateway-auth (E2eTestApiProductTrait)
        if ($this->option('suite') === 'api-product') {
            $tests = $this->runApiProductSuite($regionId);
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
