<?php

namespace Tests\Feature\Ai;

use App\Models\ExecutorImage;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAiFixtures;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * GPU scheduling of project workloads (WorkloadAiSpec / GpuScheduling): the Knative Service
 * revision (and the CronJob pod) of a GPU project gets the nvidia.com/gpu limit, a node
 * selector on the GPU product, a node affinity on the GPU memory, GPU taint tolerations, the
 * RuntimeClass of its sandbox (runc + nvidia RuntimeClass where needed, gVisor with nvproxy;
 * Kata refused), NVIDIA_DRIVER_CAPABILITIES and the longer start-up / scale-to-zero annotations.
 */
class GpuWorkloadManifestTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase, RegistersRuntimes;

    private function service(array $manifests): array
    {
        return collect($manifests)->firstWhere('kind', 'Service');
    }

    private function build($project, $version): array
    {
        return app(KnativeDeployService::class)->buildManifests($project, $version);
    }

    public function test_a_gpu_caas_project_requests_its_gpus(): void
    {
        $region = $this->gpuRegion();
        [$project, $version] = $this->gpuCaasProject($region, ['count' => 2, 'type' => 'NVIDIA-L4', 'memory_min' => 16000]);

        $ksvc = $this->service($this->build($project, $version));
        $spec = $ksvc['spec']['template']['spec'];
        $container = $spec['containers'][0];

        $this->assertSame('2', $container['resources']['limits']['nvidia.com/gpu']);
        $this->assertArrayNotHasKey('nvidia.com/gpu', $container['resources']['requests'], 'extended resources are requested by their limit');
        $this->assertSame('NVIDIA-L4', $spec['nodeSelector']['nvidia.com/gpu.product']);
        $this->assertSame([[
            'matchExpressions' => [['key' => 'nvidia.com/gpu.memory', 'operator' => 'Gt', 'values' => ['15999']]],
        ]], $spec['affinity']['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms']);
        $this->assertContains(['key' => 'nvidia.com/gpu', 'operator' => 'Exists', 'effect' => 'NoSchedule'], $spec['tolerations']);
        $this->assertContains(['key' => 'sku', 'operator' => 'Equal', 'value' => 'gpu', 'effect' => 'NoSchedule'], $spec['tolerations']);
        $this->assertArrayNotHasKey('runtimeClassName', $spec, 'runc without a GPU RuntimeClass');
        $this->assertContains(['name' => 'NVIDIA_DRIVER_CAPABILITIES', 'value' => 'compute,utility'], $container['env']);

        $annotations = $ksvc['spec']['template']['metadata']['annotations'];
        $this->assertSame('1800s', $annotations['serving.knative.dev/progress-deadline']);
        $this->assertSame('5m', $annotations['autoscaling.knative.dev/scale-to-zero-pod-retention-period']);
        $this->assertArrayNotHasKey('autoscaling.knative.dev/min-scale', $annotations, 'scale to zero stays available');
    }

    public function test_the_nvidia_runtime_class_is_used_under_runc_where_the_region_needs_it(): void
    {
        $region = $this->gpuRegion();
        $region->update(['gpu_settings' => ['runtime_class' => 'nvidia']]);
        [$project, $version] = $this->gpuCaasProject($region->fresh());

        $spec = $this->service($this->build($project, $version))['spec']['template']['spec'];

        $this->assertSame('nvidia', $spec['runtimeClassName']);
    }

    public function test_gvisor_keeps_its_runtime_class_with_nvproxy_and_is_refused_without(): void
    {
        $region = $this->gpuRegion(null, ['sandbox_caas_runtime' => 'gvisor']);
        $region->update(['gpu_settings' => ['gvisor_nvproxy' => true, 'runtime_class' => 'nvidia']]);
        [$project, $version] = $this->gpuCaasProject($region->fresh());

        $spec = $this->service($this->build($project, $version))['spec']['template']['spec'];
        $this->assertSame('gvisor', $spec['runtimeClassName'], 'nvproxy runs inside the gVisor sandbox');

        $region->update(['gpu_settings' => ['gvisor_nvproxy' => false]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nvproxy');
        $this->build($project->fresh(['containerConfig', 'knativeRegion']), $version);
    }

    public function test_kata_does_not_run_gpu_workloads(): void
    {
        $region = $this->gpuRegion(null, ['sandbox_caas_runtime' => 'kata-clh']);
        [$project, $version] = $this->gpuCaasProject($region);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kata');
        $this->build($project, $version);
    }

    public function test_projects_without_gpu_are_unchanged(): void
    {
        $region = $this->gpuRegion();
        [$project, $version] = $this->gpuCaasProject($region, null);

        $ksvc = $this->service($this->build($project, $version));
        $spec = $ksvc['spec']['template']['spec'];

        $this->assertArrayNotHasKey('nvidia.com/gpu', $spec['containers'][0]['resources']['limits']);
        $this->assertArrayNotHasKey('tolerations', $spec);
        $this->assertArrayNotHasKey('serving.knative.dev/progress-deadline', $ksvc['spec']['template']['metadata']['annotations']);
    }

    public function test_dedicated_worker_placement_is_kept_next_to_the_gpu_product(): void
    {
        $region = $this->gpuRegion(null, ['selected_workers' => ['gpu-a']]);
        [$project, $version] = $this->gpuCaasProject($region, ['count' => 1, 'type' => 'NVIDIA-L4']);

        $spec = $this->service($this->build($project, $version))['spec']['template']['spec'];

        $this->assertSame(['runr.dev/dedicated' => 'projects', 'nvidia.com/gpu.product' => 'NVIDIA-L4'], $spec['nodeSelector']);
        $this->assertContains(['key' => 'runr.dev/dedicated', 'operator' => 'Equal', 'value' => 'projects', 'effect' => 'NoSchedule'], $spec['tolerations']);
    }

    public function test_a_scheduled_gpu_project_gets_gpus_in_its_cronjob_pod(): void
    {
        $region = $this->gpuRegion();
        [$project, $version] = $this->gpuCaasProject($region, ['count' => 1], ['trigger_type' => 'schedule', 'schedule' => '0 * * * *']);

        $cron = collect($this->build($project, $version))->firstWhere('kind', 'CronJob');
        $pod = $cron['spec']['jobTemplate']['spec']['template']['spec'];

        $this->assertSame('1', $pod['containers'][0]['resources']['limits']['nvidia.com/gpu']);
        $this->assertContains(['key' => 'nvidia.com/gpu', 'operator' => 'Exists', 'effect' => 'NoSchedule'], $pod['tolerations']);
    }

    public function test_a_code_project_on_a_gpu_runtime_version_gets_a_gpu(): void
    {
        $this->seedBuiltinVersions();
        ExecutorImage::updateOrCreate(['runtime' => 'pytorch', 'version' => '2.3-gpu'], [
            'docker_image' => 'ghcr.io/runrsh/exec-pytorch:2.3-gpu', 'is_default' => false, 'is_active' => true, 'gpu_required' => true, 'category' => 'ml',
        ]);
        ExecutorImage::updateOrCreate(['runtime' => 'pytorch', 'version' => '2.3'], [
            'docker_image' => 'ghcr.io/runrsh/exec-pytorch:2.3', 'is_default' => true, 'is_active' => true, 'gpu_required' => false, 'category' => 'ml',
        ]);

        [$project, $version] = $this->runtimeProject('pytorch', 'http', ['main.py' => 'def handler(e, c): return 1']);
        $project->forceFill(['runtime_version' => '2.3-gpu', 'knative_region_id' => $this->gpuRegion()->id])->saveQuietly();
        $project = $project->fresh(['xaasConfig', 'knativeRegion']);

        $container = $this->service($this->build($project, $version))['spec']['template']['spec']['containers'][0];
        $this->assertSame('ghcr.io/runrsh/exec-pytorch:2.3-gpu', $container['image']);
        $this->assertSame('1', $container['resources']['limits']['nvidia.com/gpu'], 'GPU version without config.gpu: one GPU');

        $project->xaasConfig->update(['gpu' => ['count' => 2]]);
        $container = $this->service($this->build($project->fresh(['xaasConfig', 'knativeRegion']), $version))['spec']['template']['spec']['containers'][0];
        $this->assertSame('2', $container['resources']['limits']['nvidia.com/gpu']);

        // the CPU version stays a CPU workload
        $project->forceFill(['runtime_version' => '2.3'])->saveQuietly();
        $project->xaasConfig->update(['gpu' => null]);
        $container = $this->service($this->build($project->fresh(['xaasConfig', 'knativeRegion']), $version))['spec']['template']['spec']['containers'][0];
        $this->assertArrayNotHasKey('nvidia.com/gpu', $container['resources']['limits']);
    }
}
