<?php

namespace Tests\Feature\Rightsizing;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ResourcePeak;
use App\Models\Version;
use Illuminate\Support\Carbon;

/**
 * Fixtures of the rightsizing tests: a real region (active, not a demo, with a kubeconfig — no
 * cluster is reached, the Prometheus query is mocked), projects with a size and a running
 * deployment, and days of peaks.
 */
trait RightsizingFixtures
{
    protected function realRegion(string $name = 'eu-1', array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => $name,
            'project_namespace' => 'fn',
            'is_active' => true,
            'is_demo' => false,
            'kubeconfig' => 'apiVersion: v1',
        ]);
    }

    /**
     * A FaaS project (or CaaS with $type) of this size with an active deployment applied with it,
     * created a month ago.
     */
    protected function sizedProject(
        string $name,
        KnativeRegion $region,
        string $cpu = '500m',
        int $memoryMb = 512,
        bool $alwaysOn = false,
        string $type = 'faas',
        string $readyAt = '2026-09-01 10:00:00',
    ): Project {
        $project = Project::create(['name' => $name, 'service_type' => $type, 'runtime' => $type === 'caas' ? null : 'python']);
        $project->forceFill(['knative_region_id' => $region->id, 'created_at' => Carbon::parse('2026-09-01 09:00:00')])->saveQuietly();

        $config = [
            'project_id' => $project->id,
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
            'scale_to_zero' => ! $alwaysOn,
            'min_scale' => $alwaysOn ? 1 : 0,
            'max_scale' => 3,
        ];
        $type === 'caas'
            ? ContainerConfig::create($config + ['image' => 'nginx:1.27-alpine', 'ports' => [8080]])
            : FaasConfig::create($config + ['handler' => 'main.handler']);

        $this->runningDeployment($project->fresh(), $readyAt);

        return $project->fresh(['xaasConfig', 'faasConfig', 'containerConfig', 'knativeRegion', 'activeDeployment']);
    }

    /** An active deployment of the project with its current size, ready at $readyAt. */
    protected function runningDeployment(Project $project, string $readyAt, ?array $sizing = null, string $status = 'active', ?string $endedAt = null): Deployment
    {
        $number = (int) $project->versions()->max('number') + 1;
        $version = Version::create(['project_id' => $project->id, 'number' => $number, 'created_by' => 'dev', 'build_status' => 'ready', 'commit_id' => 'c'.$number]);
        $config = $project->faasConfig ?? $project->containerConfig;

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'version_id' => $version->id,
            'status' => $status,
            'deployed_by' => 'dev',
            'ready_at' => $readyAt,
            'ended_at' => $endedAt,
            'sizing' => $sizing ?? [
                'region_id' => $project->knative_region_id,
                'cpu_m' => (int) rtrim((string) $config->cpu, 'm'),
                'memory_mb' => (int) $config->memory_mb,
                'trigger_type' => 'http',
                'min_scale' => (int) $config->min_scale,
                'max_scale' => (int) $config->max_scale,
                'scale_to_zero' => (bool) $config->scale_to_zero,
                'gpu_count' => 0,
            ],
        ]);
        $deployment->forceFill(['created_at' => Carbon::parse($readyAt)])->saveQuietly();

        return $deployment;
    }

    /**
     * Peaks of the project for $count days up to yesterday (today: the test's now), with its
     * current size.
     */
    protected function peaks(Project $project, int $count, array $values = []): void
    {
        $config = $project->faasConfig ?? $project->containerConfig;
        for ($i = $count; $i >= 1; $i--) {
            ResourcePeak::create($values + [
                'project_id' => $project->id,
                'region_id' => $project->knative_region_id,
                'day' => now()->subDays($i)->toDateString(),
                'trigger_type' => 'http',
                'cpu_m' => (int) rtrim((string) $config->cpu, 'm'),
                'memory_mb' => (int) $config->memory_mb,
                'memory_max_mb' => 140.0,
                'memory_p95_mb' => 110.0,
                'cpu_max_m' => 210,
                'cpu_p95_m' => 80,
                'throttled_ratio' => 0.0,
                'oom_kills' => 0,
                'samples' => 7200,
                'pods' => 40,
            ]);
        }
    }
}
