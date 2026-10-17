<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Http\Client\Response;

/**
 * E2E of the AI features (test:e2e --suite=ai, started by `runtime/ci.sh test-ai`), driven through
 * the REST API like a user of the console:
 *  - ai-model           a Hub model added to the library (hf-internal-testing/tiny-random-bert,
 *                       task feature-extraction) — a few MB, runs on CPU
 *  - ai-online-cpu      model service, mode online (downloads into the region model cache),
 *                       engine transformers (hf-serve) → /v1/embeddings through the playground route
 *  - ai-mirror          offline snapshot of the model in the region (mirror Job) → ready, files counted
 *  - ai-offline-cpu     model service, mode offline (snapshot mounted read-only) → /v1/embeddings, and
 *                       the default-deny egress NetworkPolicy <service>-egress exists
 *  - ai-binding         XaaS Python function bound to the offline service as EMBED: the Knative Service
 *                       carries RUNR_MODEL_EMBED_URL and the function gets an embedding from it
 *  - ai-tei-cpu         (--ai-tei / RUNR_E2E_TEI=1) sentence-transformers/all-MiniLM-L6-v2 on TEI (CPU)
 *  - ai-gpu-caas        (--ai-gpu / RUNR_E2E_GPU=1) GPU support (device plugin) installed through the API,
 *                       a CaaS project with config.gpu {count: 1} (examples/ai/gpu-caas) sees the GPU
 *                       ("cuda": true), runs a CUDA kernel on it and returns the result the CPU computes
 * Optional cases that are not enabled are listed as skipped and not counted.
 *
 * --ai-cases / RUNR_E2E_AI_CASES runs only the named cases and what they need (aiSelectCases);
 * the others are listed as not selected. A selected case that cannot run here (ai-gpu-caas without
 * --ai-gpu) counts as failed. The GPU case stops at once with the text of --gpu-abort-file when that
 * file exists (runtime/lib/test-ai.sh writes it when the host GPU needs a reset) — before it
 * installs GPU support and while it waits for an allocatable nvidia.com/gpu.
 */
trait E2eTestAiTrait
{
    /** The suite's cases, in the order they run */
    private const AI_CASES = ['ai-model', 'ai-online-cpu', 'ai-mirror', 'ai-offline-cpu', 'ai-binding', 'ai-tei-cpu', 'ai-gpu-caas'];

    /** A case → the case that has to run before it */
    private const AI_CASE_NEEDS = [
        'ai-online-cpu' => 'ai-model',
        'ai-mirror' => 'ai-model',
        'ai-offline-cpu' => 'ai-mirror',
        'ai-binding' => 'ai-offline-cpu',
    ];

    /** The cases built on the tiny model of the library */
    private const AI_MODEL_CASES = ['ai-model', 'ai-online-cpu', 'ai-mirror', 'ai-offline-cpu', 'ai-binding'];

    private const AI_TINY_MODEL = 'hf-internal-testing/tiny-random-bert';

    private const AI_TEI_MODEL = 'sentence-transformers/all-MiniLM-L6-v2';

    private const AI_WAIT_READY = 900;

    private const AI_WAIT_MIRROR = 900;

    private const AI_WAIT_GPU = 1200;

    private const AI_BINDING_CODE = <<<'PY'
import json
import os
import urllib.request


def handler(event, context):
    url = os.environ.get("RUNR_MODEL_EMBED_URL")
    if not url:
        return {"statusCode": 500, "body": {"error": "RUNR_MODEL_EMBED_URL is not set"}}
    payload = {"input": ["hello from a bound function"], "model": os.environ.get("RUNR_MODEL_EMBED_MODEL", "")}
    request = urllib.request.Request(url.rstrip("/") + "/v1/embeddings", data=json.dumps(payload).encode(),
                                     headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(request, timeout=120) as response:
        data = json.loads(response.read())
    return {"statusCode": 200, "body": {"dims": len(data["data"][0]["embedding"]), "api": os.environ.get("RUNR_MODEL_EMBED_API")}}
PY;

    private function runAiSuite(string $regionId): array
    {
        $this->info('');
        $this->info('── AI: model services, offline snapshots, bindings, GPU ──');

        $hfServe = (string) ($this->option('hf-serve-image') ?: (getenv('RUNR_E2E_HF_SERVE_IMAGE') ?: 'ghcr.io/brf-tech/runr/hf-serve:1.1.1'));
        $this->line("  hf-serve image: {$hfServe}");

        $tests = [];
        $skipped = [];

        // ── --ai-cases / RUNR_E2E_AI_CASES ──
        $selection = self::aiSelectCases((string) ($this->option('ai-cases') ?: (getenv('RUNR_E2E_AI_CASES') ?: '')));
        if ($selection['unknown'] !== []) {
            $row = $this->makeTestEntry('ai-cases', ['service_type' => 'model', 'trigger_type' => 'http'], $regionId);
            $row['steps'] = [
                'version' => 'no such case: '.implode(', ', $selection['unknown']).' (the cases: '.implode(', ', self::AI_CASES).')',
                'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped',
            ];
            $this->aiLine($row);

            return [$row];
        }
        $selected = $selection['cases'];
        $want = fn (string $case): bool => $selected === null || in_array($case, $selected, true);
        if ($selected !== null) {
            $this->line('  cases: '.implode(', ', $selected).' (--ai-cases / RUNR_E2E_AI_CASES, with what they need)');
        }
        $notSelected = 'not selected (--ai-cases / RUNR_E2E_AI_CASES)';
        $teiOn = $selected !== null ? $want('ai-tei-cpu') : ($this->option('ai-tei') || getenv('RUNR_E2E_TEI') === '1');

        if ($want('ai-model') || $teiOn) {
            $this->aiModelStorageForCluster($regionId);
        }

        $model = null;
        if ($want('ai-model')) {
            // ── Model in the library ──
            $modelRow = $this->makeTestEntry('ai-model', ['service_type' => 'model', 'trigger_type' => 'http'], $regionId);
            $modelRow['startTime'] = microtime(true);
            $model = $this->aiEnsureModel(self::AI_TINY_MODEL, 'feature-extraction', $modelRow);
            $modelRow['steps']['deploy'] = $model ? true : 'skipped';
            $modelRow['steps']['ready'] = $model ? true : 'skipped';
            $modelRow['steps']['verify'] = $model ? (($model['revision_sha'] ?? null) ? true : 'revision not pinned to a commit') : 'skipped';
            $tests[] = $this->finishTest($modelRow);

            if (! $model) {
                return $tests;
            }
        }

        if ($model) {
            // ── Online model service (CPU, transformers) ──
            if ($want('ai-online-cpu')) {
                $online = $this->aiServiceCase('ai-online-cpu', $regionId, [
                    'ai_model_id' => $model['id'],
                    'name' => 'e2e-tiny-online',
                    'mode' => 'online',
                    'device' => 'cpu',
                    'engine' => 'transformers',
                    'image' => $hfServe,
                    'cpu' => '500m',
                    'memory_mb' => 1024,
                ], fn (array $service) => $this->aiVerifyEmbeddings($service));
                $tests[] = $online['row'];
            }

            // ── Offline snapshot (mirror Job) ──
            $mirror = null;
            if ($want('ai-mirror')) {
                $mirror = $this->aiMirrorCase('ai-mirror', $regionId, $model['id']);
                $tests[] = $mirror;
            }

            // ── Offline model service + NetworkPolicy ──
            $offline = ['row' => null, 'service' => null];
            if ($want('ai-offline-cpu')) {
                if ($mirror && $mirror['steps']['ready'] === true) {
                    $offline = $this->aiServiceCase('ai-offline-cpu', $regionId, [
                        'ai_model_id' => $model['id'],
                        'name' => 'e2e-tiny-offline',
                        'mode' => 'offline',
                        'device' => 'cpu',
                        'engine' => 'transformers',
                        'image' => $hfServe,
                        'cpu' => '500m',
                        'memory_mb' => 1024,
                    ], function (array $service) {
                        $embeddings = $this->aiVerifyEmbeddings($service);
                        if ($embeddings !== true) {
                            return $embeddings;
                        }

                        return $this->aiVerifyEgressPolicy($service);
                    });
                    $tests[] = $offline['row'];
                } else {
                    $tests[] = $this->aiSkippedRow('ai-offline-cpu', $regionId, 'no ready snapshot');
                }
            }

            // ── Binding to a function ──
            if ($want('ai-binding')) {
                $tests[] = $offline['service'] && $offline['row']['steps']['ready'] === true
                    ? $this->aiBindingCase('ai-binding', $regionId, $offline['service'])
                    : $this->aiSkippedRow('ai-binding', $regionId, 'offline model service not ready');
            }
        }
        foreach (self::AI_MODEL_CASES as $case) {
            if (! $want($case)) {
                $skipped[$case] = $notSelected;
            }
        }

        // ── Optional: TEI on CPU ──
        if ($teiOn) {
            $teiRow = $this->makeTestEntry('ai-tei-cpu', ['service_type' => 'model', 'trigger_type' => 'http'], $regionId);
            $tei = $this->aiEnsureModel(self::AI_TEI_MODEL, 'feature-extraction', $teiRow);
            $tests[] = $tei
                ? $this->aiServiceCase('ai-tei-cpu', $regionId, [
                    'ai_model_id' => $tei['id'],
                    'name' => 'e2e-minilm-tei',
                    'mode' => 'online',
                    'device' => 'cpu',
                    'engine' => 'tei',
                    'cpu' => '1',
                    'memory_mb' => 2048,
                ], fn (array $service) => $this->aiVerifyEmbeddings($service, 384))['row']
                : $teiRow;
        } else {
            $skipped['ai-tei-cpu'] = $selected !== null ? $notSelected : 'not enabled (--ai-tei or RUNR_E2E_TEI=1)';
        }

        // ── Optional: GPU CaaS ──
        $gpuOn = $this->option('ai-gpu') || getenv('RUNR_E2E_GPU') === '1';
        if (! $want('ai-gpu-caas')) {
            $skipped['ai-gpu-caas'] = $notSelected;
        } elseif ($gpuOn) {
            $tests[] = $this->aiGpuCase('ai-gpu-caas', $regionId);
        } elseif ($selected !== null) {
            // named, but it cannot run here: a failure, not a skip
            $tests[] = $this->aiSkippedRow('ai-gpu-caas', $regionId, 'selected, but no GPU visible to the Kind nodes (see runtime/lib/test-ai.sh)');
        } else {
            $skipped['ai-gpu-caas'] = 'no GPU visible to the Kind nodes (see runtime/lib/test-ai.sh)';
        }

        foreach ($skipped as $name => $reason) {
            $this->line("  ─ {$name} skipped: {$reason}");
        }

        return $tests;
    }

    /**
     * The cases --ai-cases / RUNR_E2E_AI_CASES names (comma- or space-separated) and the cases they
     * need (ai-binding → ai-offline-cpu → ai-mirror → ai-model, ai-online-cpu → ai-model), in the
     * suite's order; null when nothing is named (every case). Names the suite lacks in "unknown".
     *
     * @return array{cases: ?list<string>, unknown: list<string>}
     */
    private static function aiSelectCases(string $spec): array
    {
        $names = array_values(array_filter(preg_split('/[\s,]+/', trim($spec)) ?: [], fn (string $n) => $n !== ''));
        if ($names === []) {
            return ['cases' => null, 'unknown' => []];
        }

        $set = [];
        foreach (array_intersect($names, self::AI_CASES) as $name) {
            for ($case = $name; $case !== null; $case = self::AI_CASE_NEEDS[$case] ?? null) {
                $set[$case] = true;
            }
        }

        return [
            'cases' => array_values(array_filter(self::AI_CASES, fn (string $c) => isset($set[$c]))),
            'unknown' => array_values(array_unique(array_diff($names, self::AI_CASES))),
        ];
    }

    /**
     * Why the GPU case has to stop, from the harness (runtime/lib/test-ai.sh writes --gpu-abort-file
     * when the host GPU needs a reset); null while there is no such file or it is empty.
     *
     * @phpstan-impure the file can appear between two calls
     */
    private static function aiHostGpuProblem(string $path): ?string
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        $text = trim((string) @file_get_contents($path));

        return $text === '' ? null : mb_substr($text, 0, 400);
    }

    // ── Cases ───────────────────────────────────────────────────────────────

    /**
     * Create a model service, wait until it serves and run $verify on it.
     *
     * @return array{row: array, service: ?array}
     */
    private function aiServiceCase(string $name, string $regionId, array $payload, callable $verify): array
    {
        $row = $this->makeTestEntry($name, ['service_type' => 'model', 'trigger_type' => 'http'], $regionId);
        $row['startTime'] = microtime(true);

        $res = $this->api()->post('/api/model-services', $payload + ['knative_region_id' => $regionId, 'deploy' => true]);
        $service = $this->aiData($res);
        if (! $res->successful() || empty($service['id'])) {
            $row['steps']['version'] = "create failed: HTTP {$res->status()} ".mb_substr((string) json_encode($res->json('errors') ?? $res->json('message')), 0, 300);
            $this->skipRemaining($row, 'deploy');
            $this->aiLine($row);

            return ['row' => $row, 'service' => null];
        }
        $row['steps']['version'] = true;
        $row['steps']['deploy'] = ($service['status'] ?? '') === 'failed' ? 'failed: '.($service['status_message'] ?? '') : true;

        if ($row['steps']['deploy'] !== true) {
            $this->skipRemaining($row, 'ready');
            $this->aiLine($row);

            return ['row' => $row, 'service' => $service];
        }

        $service = $this->aiWaitService($service['id'], self::AI_WAIT_READY);
        $row['steps']['ready'] = ($service['status'] ?? null) === 'ready'
            ? true
            : 'status '.($service['status'] ?? '?').': '.mb_substr((string) ($service['status_message'] ?? ''), 0, 300).$this->aiServiceLogTail($service);

        $row['steps']['verify'] = $row['steps']['ready'] === true ? $verify($service) : 'skipped';
        $this->aiLine($row);

        return ['row' => $row, 'service' => $service];
    }

    private function aiMirrorCase(string $name, string $regionId, string $modelId): array
    {
        $row = $this->makeTestEntry($name, ['service_type' => 'model', 'trigger_type' => 'http'], $regionId);
        $row['startTime'] = microtime(true);

        $res = $this->api()->post("/api/models/{$modelId}/snapshots", ['knative_region_id' => $regionId]);
        if (! $res->successful()) {
            $row['steps']['version'] = "snapshot request failed: HTTP {$res->status()} ".mb_substr((string) json_encode($res->json('message')), 0, 300);
            $this->skipRemaining($row, 'deploy');
            $this->aiLine($row);

            return $row;
        }
        $row['steps']['version'] = true;
        $row['steps']['deploy'] = true;

        $deadline = time() + self::AI_WAIT_MIRROR;
        $snapshot = null;
        while (time() < $deadline) {
            $snapshot = collect($this->api()->get("/api/models/{$modelId}/snapshots")->json('data') ?? [])
                ->firstWhere('knative_region_id', $regionId);
            if (in_array($snapshot['status'] ?? null, ['ready', 'failed'], true)) {
                break;
            }
            sleep(5);
        }

        if (($snapshot['status'] ?? null) !== 'ready') {
            $logs = '';
            if (! empty($snapshot['id'])) {
                $logs = (string) $this->api()->get("/api/models/{$modelId}/snapshots/{$snapshot['id']}/logs")->json('logs');
            }
            $row['steps']['ready'] = 'snapshot '.($snapshot['status'] ?? 'not listed').': '.mb_substr((string) ($snapshot['message'] ?? ''), 0, 200)
                .($logs !== '' ? ' | logs: '.mb_substr($logs, -300) : '');
            $row['steps']['verify'] = 'skipped';
            $this->aiLine($row);

            return $row;
        }

        $row['steps']['ready'] = true;
        $row['steps']['verify'] = (int) ($snapshot['files_count'] ?? 0) > 0 && (int) ($snapshot['size_bytes'] ?? 0) > 0
            ? true
            : 'ready snapshot without files ('.json_encode($snapshot).')';
        $this->aiLine($row);

        return $row;
    }

    private function aiBindingCase(string $name, string $regionId, array $service): array
    {
        $test = $this->makeTestEntry($name, [
            'service_type' => 'xaas',
            'runtime' => 'python',
            'runtime_version' => null,
            'trigger_type' => 'http',
            'code' => self::AI_BINDING_CODE,
        ], $regionId);
        $test['startTime'] = microtime(true);

        $this->setupSingleTest($test);
        if ($test['steps']['version'] !== true) {
            $this->skipRemaining($test, 'deploy');
            $this->aiLine($test);

            return $test;
        }

        $bind = $this->api()->post("/api/projects/{$test['projectId']}/models", [
            'ai_model_service_id' => $service['id'],
            'alias' => 'EMBED',
        ]);
        if (! $bind->successful()) {
            $test['steps']['deploy'] = "binding failed: HTTP {$bind->status()} ".mb_substr((string) json_encode($bind->json('errors') ?? $bind->json('message')), 0, 300);
            $this->skipRemaining($test, 'ready');
            $this->aiLine($test);

            return $test;
        }

        if (! $this->deploySingleTest($test)) {
            $this->skipRemaining($test, 'ready');
            $this->aiLine($test);

            return $test;
        }

        $this->waitSingleReady($test);
        if ($test['steps']['ready'] !== true) {
            $test['steps']['verify'] = 'skipped';
            $this->aiLine($test);

            return $test;
        }

        // The deployed Knative Service carries the binding env…
        $ksvc = $this->getE2eKubernetesClient()->getResourceStatus('serving.knative.dev/v1', 'Service', $test['slug'], $this->getE2eNamespace());
        $env = array_column($ksvc['spec']['template']['spec']['containers'][0]['env'] ?? [], 'value', 'name');
        if (empty($env['RUNR_MODEL_EMBED_URL'])) {
            $test['steps']['verify'] = 'RUNR_MODEL_EMBED_URL not in the Knative Service env ('.implode(', ', array_keys($env)).')';
            $this->aiLine($test);

            return $test;
        }
        if (! empty($service['url']) && rtrim($env['RUNR_MODEL_EMBED_URL'], '/') !== rtrim((string) $service['url'], '/')) {
            $test['steps']['verify'] = "RUNR_MODEL_EMBED_URL {$env['RUNR_MODEL_EMBED_URL']} is not the service URL {$service['url']}";
            $this->aiLine($test);

            return $test;
        }

        // …and the function reaches the model through it
        $result = $this->curlFunction($test['slug'], '/');
        if (! is_array($result)) {
            $test['steps']['verify'] = $result;
        } else {
            [$status, , $body] = $result;
            $test['steps']['verify'] = $status === '200' && str_contains($body, '"dims"')
                ? true
                : "HTTP {$status}: ".mb_substr($body, 0, 300);
        }
        $this->aiLine($test);

        return $test;
    }

    private function aiGpuCase(string $name, string $regionId): array
    {
        $image = (string) ($this->option('gpu-image') ?: (getenv('RUNR_E2E_GPU_IMAGE') ?: 'ghcr.io/brf-tech/runr/ai-gpu-caas:e2e'));
        $test = $this->makeTestEntry($name, ['service_type' => 'caas', 'trigger_type' => 'http', 'image' => $image], $regionId);
        $test['startTime'] = microtime(true);

        // A host GPU that needs a reset (the harness says so through --gpu-abort-file) ends the case
        // here, and while it waits for an allocatable GPU below, instead of after AI_WAIT_GPU
        $abortFile = (string) ($this->option('gpu-abort-file') ?: '/tmp/runr-e2e-gpu-abort');
        if (($problem = self::aiHostGpuProblem($abortFile)) !== null) {
            $test['steps']['version'] = $problem;
            $this->skipRemaining($test, 'deploy');
            $this->aiLine($test);

            return $test;
        }

        // 1. GPU support through the API (device plugin; the Kind GPU node runs nvidia as containerd's default runtime)
        // Restricted to the GPU nodes (on by default): Kind workers share the host's PCI view, so
        // only the prepared GPU node(s) may run the plugin — the suite names them
        $gpuNodes = array_values(array_filter((array) $this->option('gpu-node')));
        $install = $this->api()->post("/api/knative-regions/{$regionId}/gpu/install", array_filter([
            'mode' => 'device-plugin',
            'runtime_class' => 'none',
            'restrict_to_gpu_nodes' => true,
            'gpu_nodes' => $gpuNodes ?: null,
        ], fn ($v) => $v !== null));
        if (! $install->successful()) {
            $test['steps']['version'] = "GPU install refused: HTTP {$install->status()} ".mb_substr((string) json_encode($install->json('message')), 0, 300);
            $this->skipRemaining($test, 'deploy');
            $this->aiLine($test);

            return $test;
        }

        $waitStart = time();
        $deadline = $waitStart + self::AI_WAIT_GPU;
        $gpu = [];
        while (time() < $deadline) {
            if (($problem = self::aiHostGpuProblem($abortFile)) !== null) {
                break;
            }
            $gpu = $this->aiData($this->api()->get("/api/knative-regions/{$regionId}/gpu", ['refresh' => 1]));
            if ((int) ($gpu['summary']['allocatable'] ?? 0) > 0) {
                break;
            }
            sleep(10);
        }
        if ($problem !== null) {
            $test['steps']['version'] = $problem.' — stopped after '.(time() - $waitStart).' s of waiting for an allocatable nvidia.com/gpu';
            $this->skipRemaining($test, 'deploy');
            $this->aiLine($test);

            return $test;
        }
        if ((int) ($gpu['summary']['allocatable'] ?? 0) < 1) {
            $test['steps']['version'] = 'no allocatable nvidia.com/gpu after '.self::AI_WAIT_GPU.'s (GPU support '.($gpu['status'] ?? '?').': '
                .mb_substr((string) ($gpu['status_message'] ?? ''), -300).')';
            $this->skipRemaining($test, 'deploy');
            $this->aiLine($test);

            return $test;
        }
        $this->line('  GPU nodes: '.implode(', ', array_map(fn ($n) => ($n['name'] ?? '?').' ('.($n['product'] ?? 'unknown').')', array_filter($gpu['nodes'] ?? [], fn ($n) => (int) ($n['allocatable'] ?? 0) > 0))));

        // 2. CaaS project asking for one GPU
        $project = $this->api()->post('/api/projects', [
            'name' => $name,
            'knative_region_id' => $regionId,
            'service_type' => 'caas',
            'config' => [
                'image' => $image,
                'ports' => [8080],
                'trigger_type' => 'http',
                'cpu' => '500m',
                'memory_mb' => 2048,
                'timeout_seconds' => 300,
                'gpu' => ['count' => 1],
            ],
        ]);
        $data = $this->aiData($project);
        if (! $project->successful() || empty($data['id'])) {
            $test['steps']['version'] = "project create failed: HTTP {$project->status()} ".mb_substr((string) json_encode($project->json('errors') ?? $project->json('message')), 0, 300);
            $this->skipRemaining($test, 'deploy');
            $this->aiLine($test);

            return $test;
        }
        $test['projectId'] = $data['id'];
        $test['slug'] = $data['slug'] ?? $name;
        $test['versionId'] = $data['latest_version']['id'] ?? null;
        $test['steps']['version'] = $test['versionId'] ? true : 'no initial version';
        if ($test['steps']['version'] !== true) {
            $this->skipRemaining($test, 'deploy');
            $this->aiLine($test);

            return $test;
        }

        // 3. Deploy, ready, call
        if (! $this->deploySingleTest($test)) {
            $this->skipRemaining($test, 'ready');
            $this->aiLine($test);

            return $test;
        }
        $this->waitSingleReady($test);
        if ($test['steps']['ready'] !== true) {
            $test['steps']['verify'] = 'skipped';
            $this->aiLine($test);

            return $test;
        }

        $ksvc = $this->getE2eKubernetesClient()->getResourceStatus('serving.knative.dev/v1', 'Service', $test['slug'], $this->getE2eNamespace());
        $limits = $ksvc['spec']['template']['spec']['containers'][0]['resources']['limits'] ?? [];
        if ((string) ($limits['nvidia.com/gpu'] ?? '') !== '1') {
            $test['steps']['verify'] = 'Knative Service without the nvidia.com/gpu limit: '.json_encode($limits);
            $this->aiLine($test);

            return $test;
        }

        $result = $this->curlFunction($test['slug'], '/');
        if (! is_array($result)) {
            $test['steps']['verify'] = $result;
        } else {
            [$status, , $body] = $result;
            $json = json_decode($body, true);
            // the container sees the GPU, ran the kernel on it, and its result equals the CPU's
            $ok = $status === '200' && is_array($json)
                && ($json['cuda'] ?? null) === true
                && ($json['computed_on'] ?? null) === 'gpu'
                && ($json['result_ok'] ?? null) === true
                && isset($json['checksum']) && $json['checksum'] === ($json['expected_checksum'] ?? null);
            $test['steps']['verify'] = $ok ? true : "HTTP {$status}: ".mb_substr($body, 0, 400);
            if ($test['steps']['verify'] === true) {
                $this->line('  GPU seen by the container: '.($json['device'] ?? '?').', kernel result checked ('.($json['elapsed_ms'] ?? '?').' ms)');
            }
        }
        $this->aiLine($test);

        return $test;
    }

    // ── Checks ──────────────────────────────────────────────────────────────

    private function aiVerifyEmbeddings(array $service, ?int $dims = null): bool|string
    {
        $res = $this->api()->timeout(180)->post("/api/model-services/{$service['id']}/invoke", [
            'path' => '/v1/embeddings',
            'body' => ['input' => ['hello world'], 'model' => $service['served_name'] ?? ''],
        ]);
        $answer = $res->json();
        if (isset($answer['data']) && is_array($answer['data']) && array_key_exists('body', $answer['data'])) {
            $answer = $answer['data'];
        }
        if (! $res->successful() || (int) ($answer['status'] ?? 0) !== 200) {
            return 'invoke: HTTP '.$res->status().' / model '.($answer['status'] ?? '?').' '.mb_substr((string) json_encode($answer['body'] ?? $answer), 0, 300);
        }

        $body = is_string($answer['body'] ?? null) ? json_decode($answer['body'], true) : ($answer['body'] ?? null);
        $vector = $body['data'][0]['embedding'] ?? null;
        if (! is_array($vector) || $vector === []) {
            return 'no embedding in '.mb_substr((string) json_encode($body), 0, 300);
        }
        if ($dims !== null && count($vector) !== $dims) {
            return 'embedding has '.count($vector)." dimensions, {$dims} expected";
        }

        return true;
    }

    private function aiVerifyEgressPolicy(array $service): bool|string
    {
        $name = ($service['service_name'] ?? '').'-egress';
        $policy = $this->getE2eKubernetesClient()->getResourceStatus('networking.k8s.io/v1', 'NetworkPolicy', $name, $this->getE2eNamespace());
        if (! $policy) {
            return "NetworkPolicy {$name} not found";
        }
        if (! in_array('Egress', $policy['spec']['policyTypes'] ?? [], true)) {
            return "NetworkPolicy {$name} does not restrict egress";
        }
        if (($policy['spec']['podSelector']['matchLabels']['runr.sh/model-service'] ?? null) !== $service['id']) {
            return "NetworkPolicy {$name} does not select the model service pods";
        }

        return true;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The model volume the way an administrator sets it on this cluster: the default access mode
     * is ReadWriteMany, which the local-path provisioner of Kind (and k3s) cannot provision — the
     * claim stays Pending and every model pod with it — so there it is ReadWriteOnce, as
     * docs/huggingface.md (Model storage) says for local-path clusters.
     */
    private function aiModelStorageForCluster(string $regionId): void
    {
        $classes = $this->getE2eKubernetesClient()->listClusterResources('storage.k8s.io/v1', 'StorageClass')['items'] ?? [];
        $default = collect($classes)->first(fn ($c) => ($c['metadata']['annotations']['storageclass.kubernetes.io/is-default-class'] ?? '') === 'true');
        if (! self::aiReadWriteOnceOnly($default['provisioner'] ?? null)) {
            return;
        }

        $res = $this->api()->put("/api/knative-regions/{$regionId}/model-storage", ['access_mode' => 'ReadWriteOnce', 'size_gi' => 5]);
        $this->line("  model volume: ReadWriteOnce, 5 GiB (default StorageClass {$default['metadata']['name']}: {$default['provisioner']}) → HTTP {$res->status()}");
    }

    /** Provisioners whose volumes are ReadWriteOnce only. */
    private static function aiReadWriteOnceOnly(?string $provisioner): bool
    {
        return in_array($provisioner, ['rancher.io/local-path', 'kubernetes.io/no-provisioner', 'k8s.io/minikube-hostpath'], true);
    }

    /** A Hub model in the library with the given task (added when absent). */
    private function aiEnsureModel(string $hubId, string $task, array &$row): ?array
    {
        $existing = collect($this->api()->get('/api/models')->json('data') ?? [])->firstWhere('hub_id', $hubId);
        $model = $existing;

        if (! $model) {
            $res = $this->api()->timeout(60)->post('/api/models', ['source' => 'hub', 'hub_id' => $hubId, 'revision' => 'main']);
            $model = $this->aiData($res);
            if (! $res->successful() || empty($model['id'])) {
                $row['steps']['version'] = "add {$hubId}: HTTP {$res->status()} ".mb_substr((string) json_encode($res->json('errors') ?? $res->json('message')), 0, 300);

                return null;
            }
        }

        if (($model['task'] ?? null) !== $task) {
            $res = $this->api()->patch("/api/models/{$model['id']}", ['task' => $task]);
            if (! $res->successful()) {
                $row['steps']['version'] = "set task {$task}: HTTP {$res->status()}";

                return null;
            }
            $model = $this->aiData($res) ?: $model;
        }

        $row['steps']['version'] = true;
        $this->line("  <fg=green>✓</> model {$hubId} @ ".substr((string) ($model['revision_sha'] ?? $model['revision'] ?? '?'), 0, 12));

        return $model;
    }

    private function aiWaitService(string $id, int $timeout): array
    {
        $deadline = time() + $timeout;
        $service = [];
        while (time() < $deadline) {
            $service = $this->aiData($this->api()->get("/api/model-services/{$id}"));
            if (in_array($service['status'] ?? null, ['ready', 'failed'], true)) {
                return $service;
            }
            sleep(5);
        }

        return $service + ['status_message' => "not ready after {$timeout}s"];
    }

    private function aiServiceLogTail(array $service): string
    {
        if (empty($service['id'])) {
            return '';
        }
        $pods = $this->api()->get("/api/model-services/{$service['id']}/logs", ['tail' => 20])->json('pods') ?? [];
        $logs = trim(implode("\n", array_map(fn ($p) => (string) ($p['logs'] ?? ''), $pods)));

        return $logs === '' ? '' : ' | logs: '.mb_substr($logs, -300);
    }

    /** The resource of an API answer, whether it is wrapped in "data" or not. */
    private function aiData(Response $res): array
    {
        $json = $res->json();
        if (! is_array($json)) {
            return [];
        }

        return isset($json['data']) && is_array($json['data']) && ! array_is_list($json['data']) ? $json['data'] : $json;
    }

    private function aiSkippedRow(string $name, string $regionId, string $reason): array
    {
        $row = $this->makeTestEntry($name, ['service_type' => 'model', 'trigger_type' => 'http'], $regionId);
        $row['steps'] = ['version' => $reason, 'deploy' => 'skipped', 'ready' => 'skipped', 'verify' => 'skipped'];
        $this->aiLine($row);

        return $row;
    }

    /** The case's result line; every case ends here, so its duration is taken here (finishTest) */
    private function aiLine(array &$row): void
    {
        $row = $this->finishTest($row);
        $failed = null;
        foreach ($row['steps'] as $step => $value) {
            if ($value !== true && $value !== 'skipped' && $value !== null) {
                $failed = "{$step}: {$value}";
                break;
            }
        }
        $elapsed = isset($row['duration']) ? sprintf(' (%.0f s)', $row['duration']) : '';
        $this->line($failed === null && $row['steps']['verify'] === true
            ? "  <fg=green>✓</> {$row['name']}{$elapsed}"
            : "  <fg=red>✗</> {$row['name']} — ".($failed ?? 'not verified').$elapsed);
    }
}
