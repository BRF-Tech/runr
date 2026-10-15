<?php

namespace Tests\Concerns;

use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\Ai\Gpu\GpuInventory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * Fixtures of the AI tests: Kubernetes Node / Pod items of GPU clusters (device plugin + GPU
 * Feature Discovery labels, time-slicing, NFD-only hardware), regions with a stored GPU
 * inventory, GPU CaaS projects, Hub models and model services.
 */
trait BuildsAiFixtures
{
    /** A Ready node with `gpus` nvidia.com/gpu and GFD labels. */
    protected function gpuNode(string $name, int $gpus = 1, array $labels = [], array $extra = []): array
    {
        $node = [
            'metadata' => [
                'name' => $name,
                'labels' => array_merge([
                    'kubernetes.io/os' => 'linux',
                    GpuInventory::LABEL_PRODUCT => 'NVIDIA-L4',
                    GpuInventory::LABEL_MEMORY => '23034',
                    GpuInventory::LABEL_COUNT => (string) $gpus,
                    'nvidia.com/gpu.compute.major' => '8',
                    'nvidia.com/gpu.compute.minor' => '9',
                    'nvidia.com/cuda.driver.major' => '550',
                    'nvidia.com/cuda.driver.minor' => '54',
                    'nvidia.com/cuda.driver.rev' => '15',
                    'nvidia.com/cuda.runtime.major' => '12',
                    'nvidia.com/cuda.runtime.minor' => '4',
                ], $labels),
            ],
            'spec' => [],
            'status' => [
                'allocatable' => ['cpu' => '8', 'memory' => '32Gi', GpuInventory::RESOURCE => (string) $gpus],
                'capacity' => ['cpu' => '8', 'memory' => '32Gi', GpuInventory::RESOURCE => (string) $gpus],
                'conditions' => [['type' => 'Ready', 'status' => 'True']],
                'nodeInfo' => ['containerRuntimeVersion' => 'containerd://1.7.22', 'kubeletVersion' => 'v1.31.4'],
            ],
        ];

        return array_replace_recursive($node, $extra);
    }

    protected function cpuNode(string $name): array
    {
        return [
            'metadata' => ['name' => $name, 'labels' => ['kubernetes.io/os' => 'linux']],
            'spec' => [],
            'status' => [
                'allocatable' => ['cpu' => '4', 'memory' => '16Gi'],
                'capacity' => ['cpu' => '4', 'memory' => '16Gi'],
                'conditions' => [['type' => 'Ready', 'status' => 'True']],
            ],
        ];
    }

    /** A running pod requesting `gpus` on `node`, owned by a project / model service through its labels. */
    protected function gpuPod(string $name, string $node, int $gpus, array $labels = [], string $phase = 'Running'): array
    {
        return [
            'metadata' => ['name' => $name, 'namespace' => 'fn', 'labels' => $labels],
            'spec' => [
                'nodeName' => $node,
                'containers' => [[
                    'name' => 'model',
                    'resources' => [
                        'requests' => ['cpu' => '2', 'memory' => '8192Mi'],
                        'limits' => ['cpu' => '2', 'memory' => '8192Mi', GpuInventory::RESOURCE => (string) $gpus],
                    ],
                ]],
            ],
            'status' => ['phase' => $phase, 'startTime' => Carbon::now()->subMinutes(10)->toIso8601String()],
        ];
    }

    /** A region whose stored inventory has the given nodes (no cluster). */
    protected function gpuRegion(?array $nodes = null, array $attributes = []): KnativeRegion
    {
        static $n = 0;
        $n++;

        $region = KnativeRegion::create(array_merge([
            'name' => "gpu-{$n}",
            'project_namespace' => 'fn',
            'is_active' => true,
            'total_cpu_m' => 64000,
            'total_memory_mb' => 262144,
        ], $attributes));

        $inventory = app(GpuInventory::class)->fromNodes($nodes ?? [$this->gpuNode('gpu-a', 2)], []);
        $region->forceFill([
            'gpu_mode' => 'device-plugin',
            'gpu_status' => 'ready',
            'gpu_available' => ['nodes' => $inventory['nodes'], 'summary' => $inventory['summary'], 'detected_at' => now()->toIso8601String()],
        ])->save();

        return $region->fresh();
    }

    /** A CaaS project with config.gpu in a region, plus its first version. @return array{0: Project, 1: Version} */
    protected function gpuCaasProject(KnativeRegion $region, ?array $gpu = ['count' => 1], array $config = []): array
    {
        static $n = 0;
        $n++;

        $project = Project::create(['name' => "gpu-caas-{$n}", 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        ContainerConfig::create(array_merge([
            'project_id' => $project->id,
            'image' => 'ghcr.io/acme/infer:1',
            'cpu' => '1',
            'memory_mb' => 2048,
            'timeout_seconds' => 300,
            'trigger_type' => 'http',
            'ports' => [8080],
            'gpu' => $gpu,
        ], $config));

        $version = Version::create([
            'project_id' => $project->id,
            'number' => 1,
            'commit_id' => 'abc',
            'created_by' => 'dev',
            'build_status' => 'ready',
        ]);

        return [$project->fresh(['containerConfig', 'knativeRegion']), $version];
    }

    /** @var list<array{method: string, path: string, query: string, body: mixed, headers: array}> */
    protected array $k8sCalls = [];

    /** Kubeconfig of a fake region API server (https://region.test:6443). */
    protected function fakeKubeconfig(): string
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

    /**
     * Fake region API server: every request is recorded in $k8sCalls; $routes maps
     * "METHOD /path" (or "METHOD /path*" prefix) to a response array / [body, status] / closure;
     * unknown GETs answer 404, anything else 200 with the request body.
     */
    protected function fakeCluster(array $routes = []): void
    {
        Http::fake(function (Request $request) use ($routes) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = (string) parse_url($request->url(), PHP_URL_QUERY);
            $method = $request->method();
            $body = json_decode($request->body(), true) ?? $request->body();
            $this->k8sCalls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body, 'headers' => $request->headers()];

            foreach ($routes as $key => $response) {
                [$m, $p] = explode(' ', $key, 2);
                $match = str_ends_with($p, '*') ? str_starts_with($path, rtrim($p, '*')) : $path === $p;
                if ($m === $method && $match) {
                    if ($response instanceof \Closure) {
                        $response = $response($request);
                        if (! is_array($response)) {
                            return $response; // an Http::response() of its own
                        }
                    }
                    if (is_array($response) && array_is_list($response) && count($response) === 2 && is_int($response[1])) {
                        return Http::response($response[0], $response[1]);
                    }

                    return Http::response($response);
                }
            }

            return $method === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(is_array($body) ? $body : ['kind' => 'Status'], 200);
        });
    }

    /** Recorded calls of a method whose path contains $needle. */
    protected function k8sCallsTo(string $method, string $needle): array
    {
        return array_values(array_filter($this->k8sCalls, fn ($c) => $c['method'] === $method && str_contains($c['path'], $needle)));
    }

    protected function hubModel(array $attributes = []): AiModel
    {
        return AiModel::create(array_merge([
            'name' => 'all-minilm-l6-v2',
            'source' => 'hub',
            'hub_id' => 'sentence-transformers/all-MiniLM-L6-v2',
            'revision' => 'main',
            'revision_sha' => str_repeat('a', 40),
            'task' => 'feature-extraction',
            'library' => 'sentence-transformers',
            'size_bytes' => 90 * 1024 * 1024,
            'metadata' => ['tags' => ['sentence-transformers', 'text-embeddings-inference']],
        ], $attributes));
    }

    protected function modelService(AiModel $model, KnativeRegion $region, array $attributes = []): AiModelService
    {
        static $n = 0;
        $n++;

        return AiModelService::create(array_merge([
            'ai_model_id' => $model->id,
            'knative_region_id' => $region->id,
            'name' => "svc-{$n}",
            'service_name' => "m-svc-{$n}",
            'engine' => 'tei',
            'device' => 'cpu',
            'mode' => 'online',
            'cpu' => '1',
            'memory_mb' => 1024,
            'min_scale' => 0,
            'max_scale' => 1,
            'scale_to_zero' => true,
            'timeout_seconds' => 300,
            'served_name' => $model->name,
            'status' => AiModelService::STATUS_READY,
        ], $attributes));
    }
}
