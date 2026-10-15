<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\CheckDeploymentReadyJob;
use App\Listeners\ProcessDeployment;
use App\Listeners\ProcessTeardown;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Deleting a project removes its Knative resources whatever state its deployment
 * is in. A redeploy no longer deletes the Knative Service the new deployment reuses, and the
 * queued deploy / teardown listeners record their failures on the deployment.
 */
class ProjectTeardownTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    /** @var list<string> */
    private array $calls = [];

    private function kubeconfig(): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1',
            'kind' => 'Config',
            'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => self::SERVER, 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function project(string $trigger = 'http', bool $kubeconfig = true): array
    {
        $region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $kubeconfig ? $this->kubeconfig() : null,
            'is_active' => true,
        ]);
        $project = Project::create(['name' => 'orphan-check', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => $trigger,
            'queue_stream' => $trigger === 'queue' ? 'orders' : null,
        ]);

        $v1 = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(), $v1];
    }

    private function deployment(Project $project, Version $version, string $status): Deployment
    {
        return $project->deployments()->create([
            'version_id' => $version->id,
            'status' => $status,
            'k8s_job_name' => "run-{$project->slug}-v{$version->number}",
            'deployed_by' => 'dev',
        ]);
    }

    private function fakeCluster(int $deleteStatus = 200): void
    {
        Http::fake(function (Request $request) use ($deleteStatus) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $this->calls[] = "{$method} {$path}";

            return match (true) {
                $method === 'GET' && $path === '/api/v1/namespaces/fn/configmaps' => Http::response(['items' => [
                    ['metadata' => ['name' => 'orphan-check-v1']],
                    ['metadata' => ['name' => 'orphan-check-v2']],
                ]]),
                $method === 'DELETE' => Http::response(['kind' => 'Status'], $deleteStatus),
                default => Http::response(['kind' => 'Status', 'code' => 404], 404),
            };
        });
    }

    private function deletes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_starts_with($c, 'DELETE ')));
    }

    public function test_deleting_a_project_with_a_failed_deployment_removes_its_knative_service(): void
    {
        [$project, $v1] = $this->project();
        $this->deployment($project, $v1, 'failed');
        $this->fakeCluster();

        $project->delete();

        $this->assertEqualsCanonicalizing([
            'DELETE /apis/serving.knative.dev/v1/namespaces/fn/services/orphan-check',
            'DELETE /apis/batch/v1/namespaces/fn/cronjobs/orphan-check-trigger',
            'DELETE /apis/apps/v1/namespaces/fn/deployments/orphan-check-qc',
            'DELETE /api/v1/namespaces/fn/secrets/orphan-check-qc-redis',
            'DELETE /api/v1/namespaces/fn/secrets/orphan-check-secret-env',
            'DELETE /api/v1/namespaces/fn/configmaps/orphan-check-v1',
            'DELETE /api/v1/namespaces/fn/configmaps/orphan-check-v2',
        ], $this->deletes());
        $this->assertContains('GET /api/v1/namespaces/fn/configmaps', $this->calls, 'ConfigMaps looked up by project label');
        $this->assertNull(Project::find($project->id));
    }

    public function test_deleting_a_project_with_a_pending_deployment_removes_its_knative_service(): void
    {
        [$project, $v1] = $this->project();
        $this->deployment($project, $v1, 'pending');
        $this->fakeCluster();

        $project->delete();

        $this->assertContains('DELETE /apis/serving.knative.dev/v1/namespaces/fn/services/orphan-check', $this->deletes());
    }

    public function test_deleting_a_project_in_a_region_without_kubeconfig_touches_no_cluster(): void
    {
        [$project] = $this->project(kubeconfig: false);
        $this->fakeCluster();

        $project->delete();

        $this->assertSame([], $this->calls);
        $this->assertNull(Project::find($project->id));
    }

    public function test_a_redeploy_keeps_the_knative_service_of_the_new_deployment(): void
    {
        [$project, $v1] = $this->project();
        $old = $this->deployment($project, $v1, 'shutting_down');
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->deployment($project, $v2, 'pending');
        $this->fakeCluster();

        app(ProcessTeardown::class)->handle(new DeploymentStatusChanged($old, 'active', 'shutting_down'));

        $this->assertNotContains('DELETE /apis/serving.knative.dev/v1/namespaces/fn/services/orphan-check', $this->calls);
        $this->assertEqualsCanonicalizing([
            'DELETE /apis/batch/v1/namespaces/fn/cronjobs/orphan-check-trigger',
            'DELETE /apis/apps/v1/namespaces/fn/deployments/orphan-check-qc',
            'DELETE /api/v1/namespaces/fn/secrets/orphan-check-qc-redis',
        ], $this->deletes());
        $this->assertSame('shut_down', $old->fresh()->status);
    }

    public function test_a_shutdown_without_a_newer_deployment_removes_the_knative_service(): void
    {
        [$project, $v1] = $this->project();
        $old = $this->deployment($project, $v1, 'shutting_down');
        $this->fakeCluster();

        app(ProcessTeardown::class)->handle(new DeploymentStatusChanged($old, 'active', 'shutting_down'));

        $this->assertContains('DELETE /apis/serving.knative.dev/v1/namespaces/fn/services/orphan-check', $this->deletes());
        $this->assertSame('shut_down', $old->fresh()->status);
    }

    public function test_an_incomplete_teardown_is_retried_then_marked_failed(): void
    {
        [$project, $v1] = $this->project();
        $old = $this->deployment($project, $v1, 'shutting_down');
        $this->fakeCluster(deleteStatus: 500);
        $event = new DeploymentStatusChanged($old, 'active', 'shutting_down');
        $listener = app(ProcessTeardown::class);

        $this->assertSame(3, $listener->tries);
        $this->assertSame([15, 60], $listener->backoff);

        try {
            $listener->handle($event);
            $this->fail('an incomplete teardown must throw so the queue retries it');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('incomplete', $e->getMessage());
        }
        $this->assertSame('shutting_down', $old->fresh()->status);

        Event::fake([DeploymentStatusChanged::class]);
        $listener->failed($event, new \RuntimeException('API server unreachable'));

        $old->refresh();
        $this->assertSame('failed', $old->status);
        $this->assertStringContainsString('Teardown did not complete', $old->knative_message);
        Event::assertDispatched(DeploymentStatusChanged::class, fn ($e) => $e->newStatus === 'failed');
    }

    public function test_a_deploy_job_that_dies_marks_the_deployment_failed(): void
    {
        [$project, $v1] = $this->project();
        $pending = $this->deployment($project, $v1, 'pending');
        $active = $this->deployment($project, $v1, 'active');
        $listener = app(ProcessDeployment::class);

        $this->assertSame(1, $listener->tries);

        Event::fake([DeploymentStatusChanged::class]);
        $listener->failed(new DeploymentCreated($pending, $project, $v1), new \RuntimeException('worker killed'));
        $listener->failed(new DeploymentCreated($active, $project, $v1), new \RuntimeException('late failure'));

        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertStringContainsString('worker killed', $pending->fresh()->knative_message);
        $this->assertSame('active', $active->fresh()->status, 'an active deployment is left alone');
        Event::assertDispatchedTimes(DeploymentStatusChanged::class, 1);
    }

    public function test_readiness_waits_until_knative_has_seen_the_new_template(): void
    {
        [$project, $v1] = $this->project();
        $deployment = $this->deployment($project, $v1, 'pending');

        // Ready=True still describes the previous revision: generation 3, observed 2
        Http::fake(fn () => Http::response([
            'metadata' => ['generation' => 3],
            'status' => [
                'observedGeneration' => 2,
                'latestCreatedRevisionName' => 'orphan-check-00002',
                'latestReadyRevisionName' => 'orphan-check-00002',
                'conditions' => [['type' => 'Ready', 'status' => 'True']],
            ],
        ]));
        Bus::fake([CheckDeploymentReadyJob::class]);

        (new CheckDeploymentReadyJob($deployment, $project, 1))->handle();

        $this->assertSame('pending', $deployment->fresh()->status);
        Bus::assertDispatched(CheckDeploymentReadyJob::class, fn ($job) => $job->attempt === 2);

        // A crashed check is checked again while the overall timeout allows; the last one does not
        // leave the deployment pending forever
        (new CheckDeploymentReadyJob($deployment, $project, 2))->failed(new \RuntimeException('timeout'));
        $this->assertSame('pending', $deployment->fresh()->status);
        Bus::assertDispatched(CheckDeploymentReadyJob::class, fn ($job) => $job->attempt === 3);

        (new CheckDeploymentReadyJob($deployment, $project, CheckDeploymentReadyJob::maxAttempts()))->failed(new \RuntimeException('timeout'));
        $this->assertSame('failed', $deployment->fresh()->status);
    }

    public function test_queue_retry_after_exceeds_every_job_timeout(): void
    {
        $retryAfter = config('queue.connections.redis.retry_after');
        $classes = [];
        foreach (array_merge(glob(app_path('Jobs/*.php')), glob(app_path('Listeners/*.php')), glob(base_path('Modules/*/app/Jobs/*.php'))) as $file) {
            $src = file_get_contents($file);
            if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns) && preg_match('/^class\s+(\w+)/m', $src, $cl)) {
                $classes[] = $ns[1].'\\'.$cl[1];
            }
        }

        $this->assertNotEmpty($classes);
        foreach ($classes as $class) {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
            $timeout = $defaults['timeout'] ?? config('horizon.defaults.supervisor-1.timeout');
            $this->assertGreaterThan($timeout, $retryAfter, "{$class}: timeout {$timeout}s must stay below the queue retry_after ({$retryAfter}s)");
        }
        $this->assertSame(1, config('horizon.defaults.supervisor-1.tries'), 'jobs retry only when they declare it');
    }
}
