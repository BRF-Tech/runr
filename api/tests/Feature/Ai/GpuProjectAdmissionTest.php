<?php

namespace Tests\Feature\Ai;

use App\Http\Requests\Project\DeployProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Gpu\GpuInventory;
use App\Services\Ai\Gpu\GpuQuota;
use App\Services\GitService;
use App\Services\ProjectService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\BuildsAiFixtures;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * GPU admission of projects on create, update and deploy (GpuValidation): config.gpu shape,
 * GPU nodes / product / GPU memory / per-node count of the region, the sandbox the region maps
 * the project type to, GPU runtime versions for XaaS / FaaS, and the GPU quota; the stored
 * config (count 0 removes the GPU) and the GPU default timeout.
 */
class GpuProjectAdmissionTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase, RegistersRuntimes;

    private function errors(string $requestClass, array $payload, ?Project $routeProject = null): array
    {
        /** @var FormRequest $request */
        $request = $requestClass::create('/api/test', 'POST', $payload);
        $request->setContainer($this->app);

        if ($routeProject) {
            $route = (new Route('POST', 'api/test/{project}', []))->bind($request);
            $route->setParameter('project', $routeProject);
            $request->setRouteResolver(fn () => $route);
        }

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        return $validator->errors()->toArray();
    }

    private function region(?array $nodes = null, array $attributes = []): KnativeRegion
    {
        return $this->gpuRegion($nodes, ['kubeconfig' => "apiVersion: v1\nkind: Config\n"] + $attributes);
    }

    private function caas(KnativeRegion $region, ?array $gpu, array $config = [], string $name = 'infer'): array
    {
        return [
            'name' => $name,
            'service_type' => 'caas',
            'knative_region_id' => $region->id,
            'config' => array_filter(['image' => 'ghcr.io/acme/infer:1', 'gpu' => $gpu], fn ($v) => $v !== null) + $config,
        ];
    }

    public function test_a_gpu_caas_project_fits_a_region_with_gpu_nodes(): void
    {
        $region = $this->region([$this->gpuNode('gpu-a', 2)]);

        $this->assertSame([], $this->errors(StoreProjectRequest::class, $this->caas($region, ['count' => 2, 'type' => 'NVIDIA-L4', 'memory_min' => 20000])));
    }

    public function test_regions_without_gpu_nodes_refuse_gpu_projects(): void
    {
        $none = $this->region([$this->cpuNode('cpu-1')]);
        $errors = $this->errors(StoreProjectRequest::class, $this->caas($none, ['count' => 1]));
        $this->assertStringContainsString($none->name, $errors['config.gpu'][0]);

        // NVIDIA hardware without a device plugin: the message points at the GPU tab
        $raw = $this->cpuNode('raw');
        $raw['metadata']['labels'][GpuInventory::LABEL_PCI] = 'true';
        $plugless = $this->region([$raw]);
        $errors = $this->errors(StoreProjectRequest::class, $this->caas($plugless, ['count' => 1], [], 'infer-2'));
        $this->assertSame(__('ai.gpu.no_device_plugin', ['region' => $plugless->name]), $errors['config.gpu'][0]);
    }

    public function test_type_memory_and_per_node_count_must_exist_in_the_region(): void
    {
        $region = $this->region([$this->gpuNode('gpu-a', 2)]);

        $type = $this->errors(StoreProjectRequest::class, $this->caas($region, ['count' => 1, 'type' => 'NVIDIA-H100']));
        $this->assertStringContainsString('NVIDIA-L4', $type['config.gpu'][0], 'lists the available products');

        $memory = $this->errors(StoreProjectRequest::class, $this->caas($region, ['count' => 1, 'memory_min' => 40000]));
        $this->assertSame(__('ai.gpu.memory_too_high', ['memory' => 40000]), $memory['config.gpu'][0]);

        $count = $this->errors(StoreProjectRequest::class, $this->caas($region, ['count' => 3]));
        $this->assertSame(__('ai.gpu.count_exceeds_node', ['count' => 3, 'max' => 2]), $count['config.gpu'][0]);

        $shape = $this->errors(StoreProjectRequest::class, $this->caas($region, ['count' => 9]));
        $this->assertArrayHasKey('config.gpu.count', $shape);
        $this->assertArrayHasKey('config.gpu.count', $this->errors(StoreProjectRequest::class, $this->caas($region, ['type' => 'NVIDIA-L4'])));
    }

    public function test_kata_and_gvisor_without_nvproxy_are_not_offered(): void
    {
        $kata = $this->region(null, ['sandbox_caas_runtime' => 'kata-clh']);
        $this->assertSame(
            [__('ai.gpu.kata_unsupported', ['type' => 'CAAS'])],
            $this->errors(StoreProjectRequest::class, $this->caas($kata, ['count' => 1]))['config.gpu'],
        );

        $gvisor = $this->region(null, ['sandbox_caas_runtime' => 'gvisor']);
        $this->assertArrayHasKey('config.gpu', $this->errors(StoreProjectRequest::class, $this->caas($gvisor, ['count' => 1], [], 'g1')));

        $gvisor->update(['gpu_settings' => ['gvisor_nvproxy' => true]]);
        $this->assertSame([], $this->errors(StoreProjectRequest::class, $this->caas($gvisor->fresh(), ['count' => 1], [], 'g2')));
    }

    public function test_code_projects_need_a_gpu_runtime_version(): void
    {
        $this->seedBuiltinVersions();
        $region = $this->region();

        $errors = $this->errors(StoreProjectRequest::class, [
            'name' => 'fn-gpu',
            'service_type' => 'xaas',
            'runtime' => 'python',
            'knative_region_id' => $region->id,
            'config' => ['trigger_type' => 'http', 'gpu' => ['count' => 1]],
        ]);

        $this->assertSame([__('ai.gpu.runtime_not_gpu', ['runtime' => 'python', 'version' => 'default'])], $errors['config.gpu']);
    }

    public function test_the_gpu_quota_is_checked_with_min_scale(): void
    {
        $region = $this->region([$this->gpuNode('gpu-a', 4)]);
        $this->app->instance(GpuQuota::class, new class extends GpuQuota
        {
            public function quota(KnativeRegion $region): ?int
            {
                return 2;
            }
        });

        $this->assertSame([], $this->errors(StoreProjectRequest::class, $this->caas($region, ['count' => 2])));

        $errors = $this->errors(StoreProjectRequest::class, $this->caas($region, ['count' => 1], ['min_scale' => 3, 'max_scale' => 3]));
        $this->assertSame(__('ai.gpu.quota_exceeded', ['requested' => 3, 'remaining' => 2, 'quota' => 2]), $errors['config.gpu'][0]);
    }

    public function test_create_stores_the_gpu_and_a_longer_default_timeout(): void
    {
        $region = $this->region();
        $this->mock(GitService::class, function ($mock) {
            $mock->shouldReceive('initRepo')->andReturnNull();
            $mock->shouldReceive('projectDir')->andReturn('/tmp/infer');
            $mock->shouldReceive('writeFiles')->andReturn('abc123');
        });

        $project = app(ProjectService::class)->create($this->caas($region, ['count' => 1, 'type' => 'NVIDIA-L4']));

        $this->assertSame(['count' => 1, 'type' => 'NVIDIA-L4'], $project->containerConfig->gpu);
        $this->assertSame((int) config('ai.gpu.default_timeout'), $project->containerConfig->timeout_seconds);
        $this->assertSame(['count' => 1, 'type' => 'NVIDIA-L4'], $project->latestVersion->config_snapshot['gpu'], 'the version snapshot carries the GPU');

        $plain = app(ProjectService::class)->create($this->caas($region, null, [], 'plain'));
        $this->assertNull($plain->containerConfig->gpu);
        $this->assertSame(30, $plain->containerConfig->timeout_seconds);
    }

    public function test_update_checks_changes_and_count_zero_removes_the_gpu(): void
    {
        $region = $this->region([$this->gpuNode('gpu-a', 1)]);
        [$project] = $this->gpuCaasProject($region, ['count' => 1]);

        $this->assertArrayHasKey('config.gpu', $this->errors(UpdateProjectRequest::class, ['config' => ['gpu' => ['count' => 2]]], $project));
        $this->assertSame([], $this->errors(UpdateProjectRequest::class, ['config' => ['gpu' => ['count' => 0]]], $project));
        $this->assertSame([], $this->errors(UpdateProjectRequest::class, ['description' => 'only text'], $project), 'no GPU check without a GPU change');

        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('writeFiles')->andReturn('def456'));
        app(ProjectService::class)->updateCaaSConfig($project, ['gpu' => ['count' => 0]], 'dev');
        $this->assertNull($project->containerConfig->fresh()->gpu);
    }

    public function test_deploy_rechecks_the_region(): void
    {
        $region = $this->region();
        [$project, $version] = $this->gpuCaasProject($region, ['count' => 1]);

        $this->assertSame([], $this->errors(DeployProjectRequest::class, ['version_id' => $version->id], $project));

        // the GPU nodes left the cluster since the project was created
        $region->update(['gpu_available' => ['nodes' => [], 'summary' => app(GpuInventory::class)->summary([]), 'detected_at' => now()->toIso8601String()]]);
        $errors = $this->errors(DeployProjectRequest::class, ['version_id' => $version->id], $project->fresh(['containerConfig', 'knativeRegion']));
        $this->assertSame([__('ai.gpu.no_gpu_nodes', ['region' => $region->name])], $errors['gpu']);
    }
}
