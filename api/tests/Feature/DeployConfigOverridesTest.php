<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * deploy_config cpu / memory_mb / timeout_seconds were validated but never
 * reached the manifests. They now override the project config for that deployment.
 */
class DeployConfigOverridesTest extends TestCase
{
    use RefreshDatabase;

    private function caasProject(string $trigger): array
    {
        $region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op']);
        $project = Project::create(['name' => "svc-{$trigger}", 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null,
        ]);

        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(), $version];
    }

    private function manifest(array $manifests, string $kind): array
    {
        return collect($manifests)->firstWhere('kind', $kind);
    }

    public function test_overrides_reach_the_knative_service(): void
    {
        [$project, $version] = $this->caasProject('http');
        $service = app(KnativeDeployService::class);

        $plain = $this->manifest($service->buildManifests($project, $version), 'Service')['spec']['template']['spec'];
        $this->assertSame(30, $plain['timeoutSeconds']);
        $this->assertSame(['cpu' => '250m', 'memory' => '128Mi'], $plain['containers'][0]['resources']['limits']);

        $spec = $this->manifest($service->buildManifests($project, $version, [
            'cpu' => '1', 'memory_mb' => 512, 'timeout_seconds' => 90,
        ]), 'Service')['spec']['template']['spec'];
        $this->assertSame(90, $spec['timeoutSeconds']);
        $this->assertSame(['cpu' => '1', 'memory' => '512Mi'], $spec['containers'][0]['resources']['requests']);
        $this->assertSame(['cpu' => '1', 'memory' => '512Mi'], $spec['containers'][0]['resources']['limits']);

        // Only memory overridden: CPU stays the project's
        $spec = $this->manifest($service->buildManifests($project, $version, ['memory_mb' => 256]), 'Service')['spec']['template']['spec'];
        $this->assertSame(['cpu' => '250m', 'memory' => '256Mi'], $spec['containers'][0]['resources']['limits']);
    }

    public function test_resource_overrides_reach_the_cronjob(): void
    {
        [$project, $version] = $this->caasProject('schedule');

        $cron = $this->manifest(app(KnativeDeployService::class)->buildManifests($project, $version, ['cpu' => '500m']), 'CronJob');
        $container = $cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0];

        $this->assertSame(['cpu' => '500m', 'memory' => '128Mi'], $container['resources']['limits']);
    }
}
