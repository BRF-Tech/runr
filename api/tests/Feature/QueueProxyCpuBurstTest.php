<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\Concerns\KnativeCRTrait;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\ResourceReservation;
use App\Services\KnativeDeployService;
use App\Services\RegionNamespaceQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 0.4.2: every Knative Service RunR deploys lets its queue-proxy burst to 1 CPU over its 100m
 * request (revision annotation) — runc, gVisor and Kata alike, every service type and trigger
 * that runs as a Knative Service. Memory stays request == limit; reservation, admission and the
 * organization ceiling keep counting requests.
 */
class QueueProxyCpuBurstTest extends TestCase
{
    use RefreshDatabase;

    private const LIMIT = 'queue.sidecar.serving.knative.dev/cpu-resource-limit';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['python', '3.14'], ['nodejs', '24.14']] as $i => [$runtime, $version]) {
            ExecutorImage::create(['runtime' => $runtime, 'version' => $version, 'docker_image' => "ghcr.io/runrsh/exec-{$runtime}:{$version}",
                'is_default' => true, 'is_active' => true, 'sort_order' => $i]);
        }
    }

    private function region(array $mapping = []): KnativeRegion
    {
        return KnativeRegion::create($mapping + ['name' => 'fn-'.count(KnativeRegion::all()), 'project_namespace' => 'fn', 'is_active' => true,
            'total_cpu_m' => 16000, 'total_memory_mb' => 32768]);
    }

    private function project(KnativeRegion $region, string $type, string $trigger = 'http'): Project
    {
        $project = Project::create(['name' => "{$type}-{$trigger}-".uniqid(), 'service_type' => $type, 'runtime' => $type === 'caas' ? null : 'python']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        $common = ['project_id' => $project->id, 'cpu' => '250m', 'memory_mb' => 256, 'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null];
        match ($type) {
            'caas' => ContainerConfig::create($common + ['image' => 'nginx:1.27-alpine']),
            'faas' => FaasConfig::create($common + ['handler' => 'main.handler']),
            'xaas' => XaasConfig::create($common + ['filename' => 'main.py', 'handler' => 'handler']),
        };

        return $project->fresh();
    }

    private function manifests(Project $project): array
    {
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return app(KnativeDeployService::class)->buildManifests($project, $version);
    }

    private function serviceTemplate(Project $project): array
    {
        return collect($this->manifests($project))->firstWhere('kind', 'Service')['spec']['template'];
    }

    public function test_every_knative_service_lets_queue_proxy_burst_on_every_runtime(): void
    {
        $runc = $this->region();
        $gvisor = $this->region(['sandbox_xaas_runtime' => 'gvisor', 'sandbox_faas_runtime' => 'gvisor', 'sandbox_caas_runtime' => 'gvisor']);
        $kata = $this->region(['sandbox_xaas_runtime' => 'kata-clh', 'sandbox_faas_runtime' => 'kata-qemu', 'sandbox_caas_runtime' => 'kata-dragonball']);

        foreach ([$runc, $gvisor, $kata] as $region) {
            foreach (['xaas', 'faas', 'caas'] as $type) {
                $template = $this->serviceTemplate($this->project($region, $type));

                $this->assertSame('1000m', $template['metadata']['annotations'][self::LIMIT], "{$region->name} {$type}");
                // only the CPU limit: the request (what is reserved) and memory stay config-deployment's
                $this->assertSame([self::LIMIT], array_values(array_filter(
                    array_keys($template['metadata']['annotations']),
                    fn (string $k) => str_starts_with($k, 'queue.sidecar.serving.knative.dev/'),
                )));
                // the function itself keeps request == limit
                $resources = $template['spec']['containers'][0]['resources'];
                $this->assertSame($resources['requests'], $resources['limits']);
            }
        }

        $this->assertArrayNotHasKey('runtimeClassName', $this->serviceTemplate($this->project($runc, 'caas'))['spec']);
        $this->assertSame('gvisor', $this->serviceTemplate($this->project($gvisor, 'caas'))['spec']['runtimeClassName']);
        $this->assertSame('kata-dragonball', $this->serviceTemplate($this->project($kata, 'caas'))['spec']['runtimeClassName']);
    }

    public function test_queue_projects_burst_on_their_service_and_schedule_projects_have_no_queue_proxy(): void
    {
        $region = $this->region();

        // queue trigger: the function runs as a (cluster-local) Knative Service with a queue-proxy;
        // the consumer Deployment has no queue-proxy and keeps request == limit
        $queue = $this->manifests($this->project($region, 'caas', 'queue'));
        $this->assertSame('1000m', collect($queue)->firstWhere('kind', 'Service')['spec']['template']['metadata']['annotations'][self::LIMIT]);
        foreach (collect($queue)->firstWhere('kind', 'Deployment')['spec']['template']['spec']['containers'] as $c) {
            $this->assertSame($c['resources']['requests'], $c['resources']['limits']);
        }

        // schedule trigger: a CronJob, no Knative Service, no queue-proxy
        $cron = $this->manifests($this->project($region, 'caas', 'schedule'));
        $this->assertNull(collect($cron)->firstWhere('kind', 'Service'));
        $this->assertNotNull(collect($cron)->firstWhere('kind', 'CronJob'));
    }

    public function test_the_burst_is_not_reserved(): void
    {
        $runc = $this->region();
        $kata = $this->region(['sandbox_caas_runtime' => 'kata-clh']);

        // requests only: user container + queue-proxy request (+ Kata overhead)
        $this->assertSame(['cpu_m' => 250 + 100, 'memory_mb' => 256 + 100], ResourceReservation::forProject($this->project($runc, 'caas')));
        $this->assertSame(['cpu_m' => 250 + 100 + 250, 'memory_mb' => 256 + 100 + 256], ResourceReservation::forProject($this->project($kata, 'caas')));
        $this->assertSame(100, KnativeConstants::QUEUE_PROXY_CPU_M);
        $this->assertSame(1000, KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M);

        // Knative's own default for Revisions without the annotation stays request == limit
        $deployment = (new class
        {
            use KnativeCRTrait;
        })::configDeploymentValues();
        $this->assertSame('100m', $deployment['queue-sidecar-cpu-request']);
        $this->assertSame('100m', $deployment['queue-sidecar-cpu-limit']);

        // the organization ceiling counts CPU requests only — the burst would not fit a limits.cpu ceiling
        [$quota, $limitRange] = RegionNamespaceQuotaService::namespaceQuotaManifests('fn', 500, 1024);
        $this->assertSame(['requests.cpu' => '500m', 'requests.memory' => '1024Mi', 'limits.memory' => '1024Mi'], $quota['spec']['hard']);
        // the LimitRange only fills in containers without resources and has no max: a 1-CPU
        // queue-proxy limit is accepted even with a 0.5 CPU ceiling
        $this->assertArrayNotHasKey('max', $limitRange['spec']['limits'][0]);
    }
}
