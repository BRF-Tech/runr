<?php

namespace Tests\Concerns;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectTrigger;
use App\Models\Version;
use App\Triggers\ProjectTriggers;

/**
 * Regions and projects for the trigger tests (docs/triggers.md): a region with or without Redis
 * and a kubeconfig, a caas project whose config row creates its primary trigger.
 */
trait BuildsTriggerFixtures
{
    protected const TRIGGER_KUBECONFIG = "apiVersion: v1\nkind: Config\ncurrent-context: c\nclusters:\n- name: k\n  cluster:\n    server: https://k8s.test\n    insecure-skip-tls-verify: true\ncontexts:\n- name: c\n  context: {cluster: k, user: u}\nusers:\n- name: u\n  user: {token: abc}\n";

    protected function triggerRegion(bool $redis = true, string $name = 'ev'): KnativeRegion
    {
        $region = KnativeRegion::create(['name' => $name, 'project_namespace' => $name, 'kubeconfig' => self::TRIGGER_KUBECONFIG, 'is_active' => true]);
        if ($redis) {
            $region->forceFill([
                'redis_mode' => 'external', 'redis_address' => "redis.{$name}.svc:6379", 'redis_password' => 'redis-pw',
                'knative_redis_status' => 'ready',
            ])->saveQuietly();
        }

        return $region->fresh();
    }

    protected function triggerProject(KnativeRegion $region, string $trigger = 'http', string $name = 'orders-fn', array $config = []): Project
    {
        ProjectTriggers::flush();
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        ContainerConfig::create(array_merge([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null,
            'queue_stream' => $trigger === 'queue' ? 'orders' : null,
        ], $config));

        return $project->fresh();
    }

    protected function addTrigger(Project $project, string $type, array $config = [], array $attributes = []): ProjectTrigger
    {
        $trigger = new ProjectTrigger(array_merge([
            'project_id' => $project->id,
            'name' => $attributes['name'] ?? $type,
            'type' => $type,
            'config' => $config,
            'enabled' => true,
            'position' => (int) $project->triggers()->max('position') + 1,
        ], $attributes));
        if ($handler = $trigger->handler()) {
            $handler->creating($trigger);
        }
        ProjectTriggers::withoutSync(fn () => $trigger->save());
        $project->unsetRelation('triggers');

        return $trigger;
    }

    protected function triggerVersion(Project $project, int $number = 1): Version
    {
        // the same version when asked again (a test that builds the manifests twice)
        return Version::query()->firstOrCreate(['project_id' => $project->id, 'number' => $number], ['created_by' => 'dev', 'build_status' => 'ready']);
    }

    /** @param list<array> $manifests */
    protected function manifestNamed(array $manifests, string $kind, string $name): ?array
    {
        return collect($manifests)->first(fn ($m) => ($m['kind'] ?? null) === $kind && ($m['metadata']['name'] ?? null) === $name);
    }
}
