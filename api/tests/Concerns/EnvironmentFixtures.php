<?php

namespace Tests\Concerns;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Version;
use App\Services\Environments\EnvironmentManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * Projects with environments for the environment and rollback tests (docs/environments.md): a
 * region with a cluster that records what is applied and deleted (Http::fake), a CaaS project
 * with versions whose config snapshots carry their image, environments created through
 * EnvironmentManager.
 */
trait EnvironmentFixtures
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>|null}> */
    protected array $clusterCalls = [];

    protected function region(string $name, bool $withCluster = true, array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_filter([
            'name' => $name,
            'project_namespace' => $name.'-fn',
            'is_active' => true,
            'kubeconfig' => $withCluster ? Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$name}.test:6443", 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2) : null,
        ] + $attributes, fn ($v) => $v !== null));
    }

    /** @param  array<string, mixed>  $config */
    protected function caasProject(string $name, KnativeRegion $region, array $config = []): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(array_merge([
            'project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => true, 'min_scale' => 0, 'max_scale' => 3,
            'env_vars' => ['GREETING' => 'hello'],
        ], $config));

        return $project->fresh();
    }

    /** A version; a CaaS one records the image (and the config) it was saved with. */
    protected function caasVersion(Project $project, int $number, ?string $image = null): Version
    {
        /** @var ContainerConfig $config */
        $config = $project->containerConfig()->first();

        return Version::create([
            'project_id' => $project->id, 'number' => $number, 'created_by' => 'dev', 'build_status' => 'ready',
            'config_snapshot' => ['image' => $image ?? $config->image, 'env_vars' => $config->env_vars, 'min_scale' => $config->min_scale, 'trigger_type' => $config->trigger_type],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function environment(Project $project, string $name, array $data = []): ProjectEnvironment
    {
        return app(EnvironmentManager::class)->create($project, ['name' => $name] + $data);
    }

    protected function liveDeployment(Project $project, Version $version, string $status = 'active', ?ProjectEnvironment $environment = null): Deployment
    {
        return Deployment::query()->create([
            'project_id' => $project->id,
            'version_id' => $version->id,
            'environment_id' => $environment !== null && ! $environment->is_primary ? $environment->id : null,
            'status' => $status,
            'k8s_job_name' => "run-{$project->slug}-v{$version->number}",
            'deployed_by' => 'dev',
            'ready_at' => $status === 'active' ? now() : null,
        ]);
    }

    /**
     * The region's API server: applies and deletes succeed, a Knative Service reads back Ready,
     * lists are empty; every call is recorded in $clusterCalls.
     */
    protected function fakeCluster(): void
    {
        $this->clusterCalls = [];
        Http::fake(function (Request $request) {
            $body = json_decode((string) $request->body(), true);
            $this->clusterCalls[] = ['method' => $request->method(), 'url' => $request->url(), 'body' => is_array($body) ? $body : null];

            return match ($request->method()) {
                'GET' => str_contains($request->url(), '/services/')
                    ? Http::response([
                        'metadata' => ['generation' => 1],
                        'status' => ['observedGeneration' => 1, 'conditions' => [['type' => 'Ready', 'status' => 'True']]],
                    ])
                    : Http::response(['items' => []]),
                'DELETE' => Http::response(['kind' => 'Status', 'code' => 404], 404),
                default => Http::response(is_array($body) ? $body : []),
            };
        });
    }

    /**
     * The manifests applied to the cluster (server-side apply), by kind and name.
     *
     * @return array<string, array<string, mixed>> "Kind/name" => manifest
     */
    protected function applied(): array
    {
        $applied = [];
        foreach ($this->clusterCalls as $call) {
            if ($call['method'] === 'PATCH' && is_array($call['body']) && isset($call['body']['kind'], $call['body']['metadata']['name'])) {
                $applied[$call['body']['kind'].'/'.$call['body']['metadata']['name']] = $call['body'];
            }
        }

        return $applied;
    }

    /** @return list<string> URLs of the DELETE calls */
    protected function deleted(): array
    {
        return array_values(array_map(fn ($c) => $c['url'], array_filter($this->clusterCalls, fn ($c) => $c['method'] === 'DELETE')));
    }

    /** @return array<string, string> env name => value (or "secret:<Secret>/<key>") of a container */
    protected function containerEnv(array $container): array
    {
        $env = [];
        foreach ($container['env'] ?? [] as $entry) {
            $env[$entry['name']] = isset($entry['valueFrom']['secretKeyRef'])
                ? 'secret:'.$entry['valueFrom']['secretKeyRef']['name'].'/'.$entry['valueFrom']['secretKeyRef']['key']
                : (string) ($entry['value'] ?? '');
        }

        return $env;
    }
}
