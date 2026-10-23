<?php

namespace Tests\Concerns;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * A region cluster for the deploy strategy tests (docs/deploy-strategies.md): the
 * project's Knative Service as the API server answers it ($ksvc, which a test sets), every write
 * recorded ($k8sCalls: method, path, content type, body) — the deploy's apply, the traffic merge
 * patches, the PodAutoscaler warm patch, the teardown deletes — and the health checks through
 * the activator, answered by $healthStatus.
 */
trait FakesRolloutCluster
{
    /** @var list<array{method: string, path: string, type: string, body: mixed}> */
    protected array $k8sCalls = [];

    /** The Knative Service GET answers (null: 404). */
    protected ?array $ksvc = null;

    /** Status of the health checks (activator proxy); a list is answered in order, the last one repeated. */
    protected int|array $healthStatus = 200;

    protected function rolloutKubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    protected function rolloutRegion(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'fn-'.substr(md5(uniqid('', true)), 0, 6),
            'project_namespace' => 'fn',
            'is_active' => true,
            'kubeconfig' => $this->rolloutKubeconfig(),
            'knative_settings' => ['knative_domain' => 'fn.example.com'],
        ]);
    }

    /** A container project (HTTP, nothing to build) in a region with a cluster. */
    protected function rolloutProject(string $name = 'shop', string $trigger = 'http', ?array $strategy = null, ?KnativeRegion $region = null): Project
    {
        $region ??= $this->rolloutRegion();
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        $config = ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'ports' => [8080],
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
            'queue_stream' => $trigger === 'queue' ? 'orders' : null,
            'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null,
        ]);
        if ($strategy !== null) {
            $config->forceFill(['deploy_strategy' => $strategy])->save();
        }

        return $project->fresh(['knativeRegion', 'containerConfig']);
    }

    protected function rolloutVersion(Project $project, int $number): Version
    {
        return Version::create(['project_id' => $project->id, 'number' => $number, 'created_by' => 'dev', 'build_status' => 'ready']);
    }

    protected function rolloutDeployment(Project $project, Version $version, string $status, bool $ready = true): Deployment
    {
        return $project->deployments()->create([
            'version_id' => $version->id,
            'status' => $status,
            'k8s_job_name' => "run-{$project->slug}-v{$version->number}",
            'deployed_by' => 'dev',
            'knative_ready' => $ready,
            'ready_at' => $ready ? now() : null,
        ]);
    }

    /** The service as it is before a rollout: $revision serves everything (Knative's default). */
    protected function servedBy(string $revision): void
    {
        $this->ksvc = [
            'metadata' => ['generation' => 1],
            'spec' => ['template' => ['spec' => ['containers' => []]]],
            'status' => [
                'observedGeneration' => 1,
                'latestCreatedRevisionName' => $revision,
                'latestReadyRevisionName' => $revision,
                'conditions' => [['type' => 'Ready', 'status' => 'True']],
                'traffic' => [['revisionName' => $revision, 'percent' => 100, 'latestRevision' => true]],
            ],
        ];
    }

    /** The new revision $revision is ready (the readiness check sees it). */
    protected function revisionReady(string $revision): void
    {
        $this->ksvc['metadata']['generation'] = 2;
        $this->ksvc['status']['observedGeneration'] = 2;
        $this->ksvc['status']['latestCreatedRevisionName'] = $revision;
        $this->ksvc['status']['latestReadyRevisionName'] = $revision;
    }

    protected function fakeRolloutCluster(): void
    {
        $healthCalls = 0;
        Http::fake(function (Request $request) use (&$healthCalls) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $type = (string) ($request->header('Content-Type')[0] ?? '');
            $body = json_decode($request->body(), true);
            $this->k8sCalls[] = ['method' => $method, 'path' => $path, 'type' => $type, 'body' => $body];

            if (str_contains($path, '/services/http:activator-service:80/proxy/')) {
                $statuses = (array) $this->healthStatus;
                $status = $statuses[min($healthCalls++, count($statuses) - 1)];

                return Http::response($status < 300 ? 'ok' : 'unhealthy', $status);
            }

            $ksvcPath = str_starts_with($path, '/apis/serving.knative.dev/v1/namespaces/fn/services/');

            return match (true) {
                $method === 'GET' && $ksvcPath => $this->ksvc !== null ? Http::response($this->ksvc) : Http::response(['kind' => 'Status', 'code' => 404], 404),
                $method === 'GET' => Http::response(['kind' => 'Status', 'code' => 404], 404),
                $method === 'DELETE' => Http::response(['kind' => 'Status'], 200),
                $method === 'POST' => Http::response(['kind' => 'Status'], 201),
                default => Http::response(['kind' => 'Status'], 200),
            };
        });
    }

    /**
     * The spec.traffic merge patches of the project's service, oldest first.
     *
     * @return list<array>
     */
    protected function trafficPatches(string $slug = 'shop'): array
    {
        $patches = [];
        foreach ($this->k8sCalls as $call) {
            if ($call['method'] === 'PATCH' && str_ends_with($call['path'], "/services/{$slug}") && str_contains($call['type'], 'merge-patch')) {
                $patches[] = $call['body']['spec']['traffic'] ?? null;
            }
        }

        return $patches;
    }

    /** The Knative Service the deploy applied (server-side apply), the last one. */
    protected function appliedService(string $slug = 'shop'): ?array
    {
        $applied = null;
        foreach ($this->k8sCalls as $call) {
            if ($call['method'] === 'PATCH' && str_ends_with($call['path'], "/services/{$slug}") && str_contains($call['type'], 'apply-patch')) {
                $applied = $call['body'];
            }
        }

        return $applied;
    }

    protected function lastTraffic(string $slug = 'shop'): ?array
    {
        $patches = $this->trafficPatches($slug);

        return $patches === [] ? null : $patches[count($patches) - 1];
    }
}
