<?php

namespace Tests\Feature\Projects;

use App\Events\DeploymentStatusChanged;
use App\Listeners\ProcessTeardown;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A stop that lands while a deploy applies its manifests wins (KnativeDeployService): the deploy
 * does not overwrite the status the stop set, and removes what it applied — the teardown the stop
 * queued may already have run before the apply finished.
 */
class DeployStopRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> */
    private array $calls = [];

    public function test_a_stop_during_the_apply_wins_and_the_applied_service_goes(): void
    {
        $region = KnativeRegion::create([
            'name' => 'live', 'project_namespace' => 'live', 'is_active' => true,
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'k', 'cluster' => ['server' => 'https://k8s.test', 'insecure-skip-tls-verify' => true]]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'k', 'user' => 'u']]],
                'users' => [['name' => 'u', 'user' => ['token' => 'abc']]],
            ], 10, 2),
        ]);
        $project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 256, 'trigger_type' => 'http', 'timeout_seconds' => 30]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);

        Http::fake(function (Request $request) use ($project, $deployment) {
            $this->calls[] = [$request->method(), (string) parse_url($request->url(), PHP_URL_PATH)];
            // The Knative Service is applied: the project is stopped at this very moment
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/serving.knative.dev/')) {
                $project->newQuery()->whereKey($project->id)->update(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill']);
                $deployment->newQuery()->whereKey($deployment->id)->update(['status' => 'shutting_down']);
            }

            return $request->method() === 'DELETE'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Status'], 200);
        });

        app(KnativeDeployService::class)->deploy($project->fresh(['knativeRegion', 'containerConfig']), $version, $deployment);

        $this->assertSame('shutting_down', $deployment->fresh()->status, 'the deploy did not overwrite the stop');
        $deletes = array_filter($this->calls, fn ($c) => $c[0] === 'DELETE' && str_contains($c[1], '/services/orders'));
        $this->assertNotEmpty($deletes, 'the applied Knative Service was removed again');
    }

    public function test_a_stopped_projects_teardown_removes_the_service_although_a_racing_deploy_is_pending(): void
    {
        [$project, $version] = $this->project();
        $this->recordCalls();
        // The stop marked the running deployment; a deploy created between its read and its claim stays pending
        $running = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'shutting_down']);
        Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        $project->newQuery()->whereKey($project->id)->update(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill']);

        (new ProcessTeardown(app(KnativeDeployService::class)))->handle(new DeploymentStatusChanged($running, 'active', 'shutting_down'));

        $this->assertSame('shut_down', $running->fresh()->status);
        $this->assertNotEmpty($this->deletesOf('/services/orders'), 'a stopped project keeps no Knative Service for the racing deploy');
    }

    public function test_a_deploy_refused_for_a_stop_removes_the_service_when_nothing_else_runs(): void
    {
        [$project, $version] = $this->project();
        $this->recordCalls();
        // The previous deployment was torn down as superseded by this one (Knative Service kept)
        Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'shut_down']);
        $racing = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        $project->newQuery()->whereKey($project->id)->update(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill']);

        app(KnativeDeployService::class)->deploy($project->fresh(['knativeRegion', 'containerConfig']), $version, $racing);

        $this->assertSame('failed', $racing->fresh()->status);
        $this->assertNotEmpty($this->deletesOf('/services/orders'), 'the service the superseded teardown kept goes');

        // Another live deployment (a resume's redeploy that came first) keeps its service
        $this->calls = [];
        $project->newQuery()->whereKey($project->id)->update(['stopped_at' => now()]);
        Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        $second = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);
        app(KnativeDeployService::class)->deploy($project->fresh(['knativeRegion', 'containerConfig']), $version, $second);
        $this->assertSame('failed', $second->fresh()->status);
        $this->assertSame([], $this->deletesOf('/services/orders'));
    }

    public function test_a_stop_during_a_cronjob_apply_ends_the_already_active_deployment(): void
    {
        [$project, $version] = $this->project(trigger: 'schedule');
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'pending']);

        Http::fake(function (Request $request) use ($project) {
            $this->calls[] = [$request->method(), (string) parse_url($request->url(), PHP_URL_PATH)];
            // The CronJob is applied: the project is stopped now, its deployments not marked yet
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/cronjobs/')) {
                $project->newQuery()->whereKey($project->id)->update(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill']);
            }

            return $request->method() === 'DELETE'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Status'], 200);
        });

        app(KnativeDeployService::class)->deploy($project->fresh(['knativeRegion', 'containerConfig']), $version, $deployment);

        $this->assertSame('failed', $deployment->fresh()->status, 'the CronJob deploy went active, and is withdrawn from there');
        $this->assertNotEmpty($this->deletesOf('/cronjobs/'), 'the applied CronJob was removed again');
    }

    /** @return array{0: Project, 1: Version} */
    private function project(string $trigger = 'http'): array
    {
        $region = KnativeRegion::create([
            'name' => 'live', 'project_namespace' => 'live', 'is_active' => true,
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'k', 'cluster' => ['server' => 'https://k8s.test', 'insecure-skip-tls-verify' => true]]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'k', 'user' => 'u']]],
                'users' => [['name' => 'u', 'user' => ['token' => 'abc']]],
            ], 10, 2),
        ]);
        $project = Project::create(['name' => 'orders', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(array_filter(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 256,
            'trigger_type' => $trigger, 'timeout_seconds' => 30, 'schedule' => $trigger === 'schedule' ? '*/5 * * * *' : null]));
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        return [$project->fresh(['knativeRegion', 'containerConfig']), $version];
    }

    private function recordCalls(): void
    {
        Http::fake(function (Request $request) {
            $this->calls[] = [$request->method(), (string) parse_url($request->url(), PHP_URL_PATH)];

            return $request->method() === 'DELETE'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response(['kind' => 'Status'], 200);
        });
    }

    /** @return list<array{0: string, 1: string}> */
    private function deletesOf(string $path): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === 'DELETE' && str_contains($c[1], $path)));
    }
}
