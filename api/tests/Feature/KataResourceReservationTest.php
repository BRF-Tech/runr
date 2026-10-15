<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Rules\WithinResourceLimit;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\ResourceReservation;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * A project whose service type the region runs with a Kata RuntimeClass reserves that
 * RuntimeClass's pod overhead per pod — the same amount Kubernetes adds to the pod's requests /
 * limits (scheduler, ResourceQuota).
 */
class KataResourceReservationTest extends TestCase
{
    use RefreshDatabase;

    private const QP_CPU = KnativeConstants::QUEUE_PROXY_CPU_M;

    private const QP_MEM = KnativeConstants::QUEUE_PROXY_MEMORY_MB;

    private function region(string $name, array $mapping = [], int $cpuM = 16000, int $memMb = 32768): KnativeRegion
    {
        return KnativeRegion::create($mapping + [
            'name' => $name,
            'project_namespace' => $name,
            'is_active' => true,
            'total_cpu_m' => $cpuM,
            'total_memory_mb' => $memMb,
        ]);
    }

    private function project(KnativeRegion $region, string $name, string $cpu = '250m', int $memoryMb = 256, string $trigger = 'http', int $minScale = 0): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => 'main.py',
            'handler' => 'handler',
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'trigger_type' => $trigger,
            'min_scale' => $minScale,
            'max_scale' => 5,
        ]);

        return $project->fresh(['xaasConfig']);
    }

    public function test_kata_mapped_service_types_reserve_the_pod_overhead_per_pod(): void
    {
        $kata = $this->region('kata', ['sandbox_xaas_runtime' => 'kata-qemu', 'sandbox_faas_runtime' => 'gvisor']);

        // http, min_scale 2: two pods, each with queue-proxy and the kata-qemu overhead (250m / 320Mi)
        $this->assertSame(
            ['cpu_m' => 2 * (250 + self::QP_CPU + 250), 'memory_mb' => 2 * (256 + self::QP_MEM + 320)],
            ResourceReservation::forConfig(['cpu' => '250m', 'memory_mb' => 256, 'min_scale' => 2], 'xaas', $kata),
        );
        // schedule: one CronJob pod, no queue-proxy, still a Kata pod
        $this->assertSame(
            ['cpu_m' => 250 + 250, 'memory_mb' => 256 + 320],
            ResourceReservation::forConfig(['cpu' => '250m', 'memory_mb' => 256, 'trigger_type' => 'schedule'], 'xaas', $kata),
        );
        // queue: the consumer Deployment runs runc — no overhead on it
        $this->assertSame(
            ['cpu_m' => 250 + self::QP_CPU + 250 + KnativeConstants::QUEUE_CONSUMER_CPU_M, 'memory_mb' => 256 + self::QP_MEM + 320 + KnativeConstants::QUEUE_CONSUMER_MEMORY_MB],
            ResourceReservation::forConfig(['cpu' => '250m', 'memory_mb' => 256, 'trigger_type' => 'queue'], 'xaas', $kata),
        );
        // gVisor / runc: no RuntimeClass overhead
        $this->assertSame(
            ResourceReservation::forConfig(['cpu' => '250m', 'memory_mb' => 256], 'faas'),
            ResourceReservation::forConfig(['cpu' => '250m', 'memory_mb' => 256], 'faas', $kata),
        );
    }

    public function test_region_usage_counts_the_overhead_of_kata_projects_only(): void
    {
        $kata = $this->region('kata', ['sandbox_xaas_runtime' => 'kata-clh']);
        $runc = $this->region('runc');

        $a = $this->project($kata, 'on-kata');
        $b = $this->project($runc, 'on-runc');

        $this->assertSame(['cpu_m' => 250 + self::QP_CPU + 250, 'memory_mb' => 256 + self::QP_MEM + 256], ResourceReservation::forProject($a));
        $this->assertSame(['cpu_m' => 250 + self::QP_CPU, 'memory_mb' => 256 + self::QP_MEM], ResourceReservation::forProject($b));
        $this->assertSame(
            ['cpu_m' => 2 * (250 + self::QP_CPU) + 250, 'memory_mb' => 2 * (256 + self::QP_MEM) + 256],
            ResourceReservation::sum(Project::with('xaasConfig')->get()),
        );
    }

    /**
     * Kata pods boot the VM inside the pod's CPU limit: their queue-proxy may burst to
     * KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M (limit only — the reservation stays on requests).
     * Since 0.4.2 runc and gVisor pods get the same (QueueProxyCpuBurstTest).
     */
    public function test_kata_pods_get_the_queue_proxy_cpu_burst_and_reserve_only_requests(): void
    {
        $kata = $this->region('kata', ['sandbox_caas_runtime' => 'kata-clh']);
        $gvisor = $this->region('gvisor', ['sandbox_caas_runtime' => 'gvisor']);
        $caas = function (KnativeRegion $region, string $name) {
            $project = Project::create(['name' => $name, 'service_type' => 'caas']);
            $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
            ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 256, 'trigger_type' => 'http']);

            return $project->fresh();
        };
        $template = function (Project $project) {
            $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

            return collect(app(KnativeDeployService::class)->buildManifests($project, $version))->firstWhere('kind', 'Service')['spec']['template'];
        };

        $onKata = $template($caas($kata, 'on-kata'));
        $this->assertSame('kata-clh', $onKata['spec']['runtimeClassName']);
        $this->assertSame('1000m', $onKata['metadata']['annotations']['queue.sidecar.serving.knative.dev/cpu-resource-limit']);
        $this->assertArrayNotHasKey('queue.sidecar.serving.knative.dev/cpu-resource-request', $onKata['metadata']['annotations']);

        // gVisor pods too (0.4.2: every runtime)
        $onGvisor = $template($caas($gvisor, 'on-gvisor'));
        $this->assertSame('gvisor', $onGvisor['spec']['runtimeClassName']);
        $this->assertSame('1000m', $onGvisor['metadata']['annotations']['queue.sidecar.serving.knative.dev/cpu-resource-limit']);

        // the burst is not reserved: capacity and quota admission count requests
        $this->assertSame(['cpu_m' => 250 + self::QP_CPU + 250, 'memory_mb' => 256 + self::QP_MEM + 256], ResourceReservation::forProject($caas($kata, 'on-kata-2')));
    }

    public function test_region_capacity_check_includes_the_kata_overhead(): void
    {
        // 1000m region, always-on xaas on kata-clh: 400m + 100m queue-proxy + 250m overhead =
        // 750m fits, 700m would be 1050m (without the overhead: 800m)
        $kata = $this->region('kata', ['sandbox_xaas_runtime' => 'kata-clh'], 1000, 8192);

        $rule = fn () => ['cpu' => [new WithinResourceLimit('cpu', null, $kata->id, 'http', 1, 'xaas')]];

        $this->assertTrue(Validator::make(['cpu' => '400m'], $rule())->passes());
        $this->assertFalse(Validator::make(['cpu' => '700m'], $rule())->passes());

        // same size on a runc service type fits
        $faas = ['cpu' => [new WithinResourceLimit('cpu', null, $kata->id, 'http', 1, 'faas')]];
        $this->assertTrue(Validator::make(['cpu' => '700m'], $faas)->passes());
    }
}
