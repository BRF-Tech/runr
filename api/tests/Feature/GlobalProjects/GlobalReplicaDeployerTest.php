<?php

namespace Tests\Feature\GlobalProjects;

use App\Jobs\Environments\CheckRegionImageBuildJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\GlobalProjects\CopyGlobalImageJob;
use App\Jobs\GlobalProjects\RolloutGlobalReplicaJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\ProjectRegion;
use App\Models\ProjectSecret;
use App\Models\Setting;
use App\Models\UploadedImage;
use App\Models\Version;
use App\Models\VersionRegionImage;
use App\Models\XaasConfig;
use App\Services\AgentMessageHandler;
use App\Services\FunctionBuildService;
use App\Services\GlobalProjects\GlobalImageCopy;
use App\Services\GlobalProjects\GlobalInternalEndpoint;
use App\Services\GlobalProjects\GlobalProjectsSchema;
use App\Services\GlobalProjects\GlobalReplicaDeployer;
use App\Services\Helpers\RegionCapacity;
use App\Services\InternalNetwork\InternalNetwork;
use App\Services\InternalNetwork\InternalNetworkManifests;
use App\Services\InternalNetwork\InternalNetworkService;
use App\Services\KnativeDeployService;
use App\Services\Secrets\ProjectSecrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * A global project's version reaching its other regions (docs/global-projects.md, "Deploys"): the
 * primary's ready deployment starts a rollout per region, a step applies the deploy's manifests to
 * that region's cluster and waits for the new revision, a version with a build image is built in
 * the region first, a newer rollout ends an older one; and the paths every project has that now
 * reach every region — full teardown, region capacity, the region agent's project lookup.
 */
class GlobalReplicaDeployerTest extends TestCase
{
    use FakesRegionSecrets, RefreshDatabase;

    private KnativeRegion $eu;

    private KnativeRegion $us;

    /** @var list<array{host: string, method: string, path: string, body: array}> */
    private array $calls = [];

    /** @var array<string, array> "host GET path" => response body */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('base_domain', 'example.com');

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['host' => $host, 'method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];

            if (isset($this->responses["{$host} {$request->method()} {$path}"])) {
                return Http::response($this->responses["{$host} {$request->method()} {$path}"]);
            }

            return $request->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response([], 200);
        });

        $this->eu = $this->region('eu-1');
        $this->us = $this->region('us-1');
    }

    private function region(string $name): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => $name,
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$name}.test:6443", 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 't']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'external_ip' => $name === 'eu-1' ? '203.0.113.10' : '198.51.100.10',
            'is_active' => true,
            'knative_serving_status' => 'ready',
            'total_cpu_m' => 4000,
            'total_memory_mb' => 8192,
        ]);
    }

    /** @return array{0: Project, 1: Deployment, 2: ProjectRegion} the project, its active deployment, the us row */
    private function globalProject(string $type = 'caas', ?string $buildImage = null): array
    {
        $project = Project::create(['name' => 'shop', 'service_type' => $type, 'runtime' => $type === 'caas' ? null : 'python']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => 'ha'])->saveQuietly();
        if ($type === 'caas') {
            ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http', 'cpu' => '500m', 'memory_mb' => 256, 'ports' => [8080]]);
        } else {
            XaasConfig::create(['project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler', 'trigger_type' => 'http', 'cpu' => '500m', 'memory_mb' => 256]);
        }

        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready', 'build_image' => $buildImage]);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev']);

        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->eu->id, 'position' => 0]);
        $us = ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->us->id, 'position' => 1]);

        return [$project->fresh(), $deployment, $us];
    }

    /** @return list<array{host: string, method: string, path: string, body: array}> */
    private function writesTo(string $host): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['host'] === $host && $c['method'] !== 'GET'));
    }

    public function test_the_ready_primary_serves_its_version_and_the_other_regions_get_it(): void
    {
        [$project, $deployment, $us] = $this->globalProject();

        app(GlobalReplicaDeployer::class)->afterPrimaryReady($project, $deployment);

        $primary = ProjectRegion::query()->where('knative_region_id', $this->eu->id)->first();
        $this->assertSame('ready', $primary->status);
        $this->assertSame($deployment->version_id, $primary->served_version_id);

        $us->refresh();
        $this->assertSame('deploying', $us->status);
        $this->assertSame($deployment->version_id, $us->target_version_id);
        $this->assertNotNull($us->rollout_token);
        Queue::assertPushed(RolloutGlobalReplicaJob::class, fn ($job) => $job->projectRegionId === $us->id && $job->token === $us->rollout_token);
        Queue::assertPushed(SyncGlobalZoneJob::class);

        // the primary's routes of shop.g.example.com
        $paths = array_column($this->writesTo('eu-1.test'), 'path');
        $this->assertContains('/apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/runr-global-'.substr(sha1($project->id), 0, 20), $paths);
    }

    public function test_a_rollout_step_applies_the_service_in_the_region_and_waits_for_its_revision(): void
    {
        [$project, $deployment, $us] = $this->globalProject();
        $deployer = app(GlobalReplicaDeployer::class);
        $deployer->startRollout($project, $us, $deployment);
        $us->refresh();

        // step 1: the manifests go to us-1's cluster, never to eu-1's
        $this->assertSame(GlobalReplicaDeployer::POLL_SECONDS, $deployer->step($project, $us, (string) $us->rollout_token));
        $service = collect($this->writesTo('us-1.test'))->first(fn ($c) => str_ends_with($c['path'], '/services/shop') && str_contains($c['path'], 'serving.knative.dev'));
        $this->assertNotNull($service, 'Knative Service applied in us-1');
        $this->assertSame('true', $service['body']['metadata']['labels']['runr/global-project']);
        $this->assertSame('ghcr.io/acme/shop:1.0', $service['body']['spec']['template']['spec']['containers'][0]['image']);
        $this->assertSame([], array_filter($this->writesTo('eu-1.test'), fn ($c) => str_contains($c['path'], 'serving.knative.dev')));

        // step 2: not ready yet
        $us->refresh();
        $this->assertSame(GlobalReplicaDeployer::POLL_SECONDS, $deployer->step($project, $us, (string) $us->rollout_token));

        // step 3: the revision of v1 is ready
        $this->responses['us-1.test GET /apis/serving.knative.dev/v1/namespaces/fn/services/shop'] = [
            'metadata' => ['generation' => 2],
            'spec' => ['template' => ['metadata' => ['labels' => ['runr/version' => '1']]]],
            'status' => [
                'observedGeneration' => 2,
                'latestCreatedRevisionName' => 'shop-00002',
                'latestReadyRevisionName' => 'shop-00002',
                'conditions' => [['type' => 'Ready', 'status' => 'True']],
            ],
        ];
        $us->refresh();
        $this->assertNull($deployer->step($project, $us, (string) $us->rollout_token));

        $us->refresh();
        $this->assertSame('ready', $us->status);
        $this->assertSame($deployment->version_id, $us->served_version_id);
        $this->assertNull($us->target_version_id);
        $this->assertContains('/apis/traefik.io/v1alpha1/namespaces/fn/middlewares/runr-global-'.substr(sha1($project->id), 0, 20), array_column($this->writesTo('us-1.test'), 'path'));
    }

    public function test_a_version_with_a_build_image_is_built_in_the_region_first(): void
    {
        $this->us->forceFill(['registry_address' => 'us-registry:5000'])->save();
        [$project, $deployment, $us] = $this->globalProject('xaas', 'eu-registry:5000/runr-builds/shop:v1');
        $builder = Mockery::mock(FunctionBuildService::class);
        $builder->shouldReceive('submitReplicaBuild')->once()->andReturn('us-registry:5000/runr-builds/shop:v1');
        $this->app->instance(FunctionBuildService::class, $builder);
        $deployer = app(GlobalReplicaDeployer::class);

        $deployer->startRollout($project, $us, $deployment);
        $us->refresh();
        $this->assertSame('building', $us->status);

        // the build goes into the store environments use (one build per version and region)
        $this->assertSame(GlobalReplicaDeployer::POLL_SECONDS, $deployer->step($project, $us, (string) $us->rollout_token));
        $row = VersionRegionImage::query()->where('version_id', $deployment->version_id)->where('knative_region_id', $this->us->id)->firstOrFail();
        $this->assertSame('building', $row->status);
        $this->assertSame('us-registry:5000', $row->registry);
        $this->assertSame('rbuild-shop-v1', $row->job_name);
        Queue::assertPushed(CheckRegionImageBuildJob::class);

        // still building: the copy waits, nothing is submitted twice
        $us->refresh();
        $this->assertSame(GlobalReplicaDeployer::POLL_SECONDS, $deployer->step($project, $us, (string) $us->rollout_token));

        $row->update(['status' => 'ready']);
        $us->refresh();
        $this->assertSame(1, $deployer->step($project, $us, (string) $us->rollout_token));
        $us->refresh();
        $this->assertSame('deploying', $us->status);
        $this->assertSame('us-registry:5000/runr-builds/shop:v1', $us->build_image);
    }

    public function test_a_region_build_an_environment_made_is_not_built_again(): void
    {
        $this->us->forceFill(['registry_address' => 'us-registry:5000'])->save();
        [$project, $deployment, $us] = $this->globalProject('xaas', 'eu-registry:5000/runr-builds/shop:v1');
        VersionRegionImage::query()->create([
            'project_id' => $project->id, 'version_id' => $deployment->version_id, 'knative_region_id' => $this->us->id,
            'registry' => 'us-registry:5000', 'status' => 'ready', 'image' => 'us-registry:5000/runr-builds/shop:v1-env', 'job_name' => 'rbuild-shop-v1',
        ]);
        $builder = Mockery::mock(FunctionBuildService::class);
        $builder->shouldNotReceive('submitReplicaBuild');
        $this->app->instance(FunctionBuildService::class, $builder);
        $deployer = app(GlobalReplicaDeployer::class);

        $deployer->startRollout($project, $us, $deployment);
        $us->refresh();
        $this->assertSame(1, $deployer->step($project, $us, (string) $us->rollout_token));
        $this->assertSame('us-registry:5000/runr-builds/shop:v1-env', $us->fresh()->build_image);
    }

    public function test_a_rollout_step_copies_the_secret_values_into_the_region_before_its_service(): void
    {
        config(['secrets.storage' => 'region']);
        $this->fakeRegionSecrets();
        [$project, $deployment, $us] = $this->globalProject();
        app(ProjectSecrets::class)->setValues($project, ['API_KEY' => 'sk_9'], 'dev');
        $deployer = app(GlobalReplicaDeployer::class);
        $deployer->startRollout($project, $us, $deployment);
        $us->refresh();

        $this->assertSame(GlobalReplicaDeployer::POLL_SECONDS, $deployer->step($project, $us, (string) $us->rollout_token));

        // the value the primary holds, and RunR's own keys signed for us-1, in us-1's Secret
        $values = $this->regionSecrets->values($this->us, 'shop-secret-env');
        $this->assertSame('sk_9', $values['API_KEY'] ?? null);
        // the project's token signed for us-1 (its iat is the second it was signed: compare the claims)
        $claims = InternalNetwork::verify((string) $this->us->id, (string) ($values[InternalNetwork::ENV_TOKEN] ?? ''));
        $this->assertSame(InternalNetwork::nameOf($project), $claims['sub'] ?? null);
        $this->assertSame((string) $this->us->id, $claims['reg'] ?? null);
        $this->assertSame('sk_9', $this->regionSecrets->values($this->eu, 'shop-secret-env')['API_KEY'], 'the primary keeps its copy');
        $this->assertSame('region', ProjectSecret::query()->where('project_id', $project->id)->value('source'));
        $this->assertSame('deploying', $us->fresh()->status);
    }

    public function test_a_value_missing_in_the_primary_fails_the_regions_rollout(): void
    {
        config(['secrets.storage' => 'region']);
        $this->fakeRegionSecrets();
        [$project, $deployment, $us] = $this->globalProject();
        app(ProjectSecrets::class)->setValues($project, ['API_KEY' => 'sk_9'], 'dev');
        $this->regionSecrets->seed($this->eu, 'shop-secret-env', []);
        $deployer = app(GlobalReplicaDeployer::class);
        $deployer->startRollout($project, $us, $deployment);
        $us->refresh();

        $this->assertNull($deployer->step($project, $us, (string) $us->rollout_token));

        $this->assertSame('failed', $us->fresh()->status);
        $this->assertStringContainsString('API_KEY', (string) $us->fresh()->status_message);
        $this->assertSame([], array_filter($this->writesTo('us-1.test'), fn ($c) => str_contains($c['path'], 'serving.knative.dev')), 'no Service without its values');
    }

    public function test_a_newer_rollout_ends_the_steps_of_an_older_one(): void
    {
        [$project, $deployment, $us] = $this->globalProject();
        $deployer = app(GlobalReplicaDeployer::class);
        $deployer->startRollout($project, $us, $deployment);
        $old = (string) $us->fresh()->rollout_token;
        $deployer->startRollout($project, $us->fresh(), $deployment);

        $this->assertNull($deployer->step($project, $us->fresh(), $old));
        $this->assertSame([], $this->writesTo('us-1.test'));
    }

    public function test_a_replaced_deployment_stops_the_rollout_and_keeps_what_the_region_serves(): void
    {
        [$project, $deployment, $us] = $this->globalProject();
        $deployer = app(GlobalReplicaDeployer::class);
        $deployer->startRollout($project, $us, $deployment);
        $deployment->update(['status' => 'shutting_down']);

        $this->assertNull($deployer->step($project, $us->fresh(), (string) $us->fresh()->rollout_token));
        $this->assertSame('pending', $us->fresh()->status);
        $this->assertNull($us->fresh()->target_version_id);
    }

    public function test_the_project_teardown_reaches_every_region(): void
    {
        [$project] = $this->globalProject();
        ProjectRegion::query()->update(['served_version_id' => $project->versions()->value('id'), 'status' => 'ready']);

        app(KnativeDeployService::class)->teardownProject($project);

        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $this->assertContains('/apis/serving.knative.dev/v1/namespaces/fn/services/shop', array_column(
                array_filter($this->writesTo($host), fn ($c) => $c['method'] === 'DELETE'), 'path'), "Knative Service deleted in {$host}");
        }
        $this->assertSame(['pending', 'pending'], ProjectRegion::query()->pluck('status')->all());
        $this->assertSame([null, null], ProjectRegion::query()->pluck('served_version_id')->all());
    }

    public function test_a_copy_counts_in_the_capacity_of_its_region(): void
    {
        $this->globalProject();

        $this->assertGreaterThan(0, RegionCapacity::usage($this->us->id)['burst']['cpu_m'] + RegionCapacity::usage($this->us->id)['hard']['cpu_m']);
    }

    public function test_an_access_mode_change_reaches_the_copies_and_the_global_routes_of_every_region(): void
    {
        [$project, $deployment, $us] = $this->globalProject();
        ProjectRegion::query()->where('project_id', $project->id)->update(['served_version_id' => $deployment->version_id, 'status' => 'ready']);
        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $this->responses["{$host} GET /apis/serving.knative.dev/v1/namespaces/fn/services/shop"] = ['metadata' => ['name' => 'shop'], 'status' => []];
        }
        $route = 'runr-global-'.substr(sha1($project->id), 0, 20);

        app(InternalNetworkService::class)->setAccess($project, Project::ACCESS_ALLOWLIST, ['192.0.2.0/24']);

        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $writes = $this->writesTo($host);
            $service = collect($writes)->first(fn ($c) => $c['method'] === 'PATCH' && str_ends_with($c['path'], '/services/shop'));
            $this->assertNotNull($service, "the Knative Service of {$host} gets the access policy");
            $allowList = collect($writes)->first(fn ($c) => ($c['body']['kind'] ?? null) === 'Middleware' && isset($c['body']['spec']['ipAllowList']));
            $this->assertSame(['192.0.2.0/24'], $allowList['body']['spec']['ipAllowList']['sourceRange'] ?? null, "the allow list in {$host}");
            $global = collect($writes)->first(fn ($c) => ($c['body']['kind'] ?? null) === 'IngressRoute' && ($c['body']['metadata']['name'] ?? null) === $route);
            $this->assertNotNull($global, "the global route in {$host}");
            $this->assertSame(InternalNetworkManifests::accessName('shop'), $global['body']['spec']['routes'][0]['middlewares'][0]['name'] ?? null, "the global route of {$host} checks the client first");
        }

        // Access mode internal: no public global route anywhere
        $this->calls = [];
        app(InternalNetworkService::class)->setAccess($project->fresh(), Project::ACCESS_INTERNAL, null);
        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $this->assertContains("/apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes/{$route}", array_column(array_filter($this->calls, fn ($c) => $c['host'] === $host && $c['method'] === 'DELETE'), 'path'));
        }
    }

    public function test_an_internal_call_prefers_the_copy_in_the_callers_region(): void
    {
        [$project, $deployment, $us] = $this->globalProject();
        $us->update(['served_version_id' => $deployment->version_id, 'status' => 'ready']);
        $endpoint = app(GlobalInternalEndpoint::class);

        // the copy on the caller's own cluster: its name on the internal network (docs/internal-network.md)
        $this->assertSame(['host' => InternalNetwork::hostOf($project), 'local' => true], $endpoint->resolve($project, $this->us));
        $this->assertSame(['host' => 'shop.g.example.com', 'local' => false], $endpoint->resolve($project, $this->region('ap-1')));
    }

    public function test_the_agent_of_another_region_reports_the_copy_there(): void
    {
        [$project, $deployment] = $this->globalProject();

        app(AgentMessageHandler::class)->handle(['type' => 'session_start', 'project_slug' => 'shop', 'pod' => 'shop-00001-deployment-abc', 'entry_point' => 'http'], $this->us->id);

        $session = DeploymentSession::query()->firstOrFail();
        $this->assertSame($project->id, $session->project_id);
        $this->assertSame($deployment->id, $session->deployment_id);
        $this->assertSame($this->us->id, $session->region_id);
    }

    public function test_a_secondary_holds_the_schedule_on_standby_and_is_ready_with_its_cronjob(): void
    {
        $project = Project::create(['name' => 'cron', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => 'ha'])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/job:1.0', 'trigger_type' => 'schedule', 'schedule' => '*/5 * * * *', 'cpu' => '250m', 'memory_mb' => 128]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev']);
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->eu->id, 'position' => 0]);
        $us = ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->us->id, 'position' => 1]);
        $project = $project->fresh();
        $deployer = app(GlobalReplicaDeployer::class);

        $deployer->startRollout($project, $us, $deployment);
        $us->refresh();
        $deployer->step($project, $us, (string) $us->rollout_token);

        // the schedule runs once, in the primary: us-1 holds the CronJob suspended, no Knative Service
        $cron = collect($this->writesTo('us-1.test'))->first(fn ($c) => str_ends_with($c['path'], '/cronjobs/cron-trigger'));
        $this->assertNotNull($cron, 'CronJob applied in us-1');
        $this->assertTrue($cron['body']['spec']['suspend']);

        $this->responses['us-1.test GET /apis/batch/v1/namespaces/fn/cronjobs/cron-trigger'] = ['metadata' => ['name' => 'cron-trigger'], 'spec' => ['suspend' => true]];
        $us->refresh();
        $this->assertNull($deployer->step($project, $us, (string) $us->rollout_token));
        $us->refresh();
        $this->assertSame('ready', $us->status);
        $this->assertFalse((bool) $us->triggers_active);
    }

    public function test_an_uploaded_image_is_copied_into_the_region_before_it_runs(): void
    {
        $digest = 'sha256:'.str_repeat('b', 64);
        $this->eu->forceFill(['registry_mode' => 'internal', 'registry_address' => 'eu-registry:5000'])->save();
        $this->us->forceFill(['registry_mode' => 'internal', 'registry_address' => 'us-registry:5000'])->save();
        UploadedImage::query()->create([
            'owner_key' => 'org', 'knative_region_id' => $this->eu->id, 'repository' => 'shop', 'tag' => '1.0',
            'registry_repository' => 'runr-uploads/org/shop', 'digest' => $digest, 'media_type' => 'application/vnd.oci.image.manifest.v1+json', 'kind' => 'image', 'source_format' => 'oci',
            'size_bytes' => 1, 'registry_address' => 'eu-registry:5000',
        ]);
        [$project, $deployment, $us] = $this->globalProject();
        ContainerConfig::query()->where('project_id', $project->id)->update(['image' => 'eu-registry:5000/runr-uploads/org/shop:1.0']);
        $project = $project->fresh();
        $deployer = app(GlobalReplicaDeployer::class);

        $deployer->startRollout($project, $us, $deployment);
        $us->refresh();
        $this->assertSame('building', $us->status);
        $deployer->step($project, $us, (string) $us->rollout_token);
        Queue::assertPushed(CopyGlobalImageJob::class, fn ($job) => $job->projectRegionId === $us->id);

        // a region that holds the digest already copies nothing
        UploadedImage::query()->create([
            'owner_key' => 'org', 'knative_region_id' => $this->us->id, 'repository' => 'shop', 'tag' => '1.0',
            'registry_repository' => 'runr-uploads/org/shop', 'digest' => $digest, 'media_type' => 'application/vnd.oci.image.manifest.v1+json', 'kind' => 'image', 'source_format' => 'oci',
            'size_bytes' => 1, 'registry_address' => 'us-registry:5000',
        ]);
        (new CopyGlobalImageJob($project, (string) $us->id, (string) $us->fresh()->rollout_token))->handle(app(GlobalImageCopy::class));
        $us->refresh();
        $this->assertSame("us-registry:5000/runr-uploads/org/shop@{$digest}", $us->build_image);

        // the copy is in place: the Knative Service of us-1 runs it
        $deployer->step($project, $us, (string) $us->rollout_token);
        $us->refresh();
        $this->assertSame('deploying', $us->status);
        $deployer->step($project, $us, (string) $us->rollout_token);
        $service = collect($this->writesTo('us-1.test'))->first(fn ($c) => str_ends_with($c['path'], '/services/shop') && str_contains($c['path'], 'serving.knative.dev'));
        $this->assertSame("us-registry:5000/runr-uploads/org/shop@{$digest}", $service['body']['spec']['template']['spec']['containers'][0]['image']);
    }

    public function test_an_environment_deployment_is_not_rolled_out_to_the_other_regions(): void
    {
        [$project, $deployment, $us] = $this->globalProject();
        $environment = ProjectEnvironment::query()->create(['project_id' => $project->id, 'name' => 'staging', 'workload_name' => 'shop-staging', 'is_primary' => false]);
        $deployment->forceFill(['environment_id' => $environment->id])->save();

        app(GlobalReplicaDeployer::class)->afterPrimaryReady($project, $deployment->fresh());

        $this->assertSame('pending', $us->fresh()->status);
        Queue::assertNotPushed(RolloutGlobalReplicaJob::class);
    }

    public function test_the_builds_kept_on_the_regions_rows_move_into_the_image_store(): void
    {
        $this->us->forceFill(['registry_address' => 'us-registry:5000'])->save();
        [$project, $deployment, $us] = $this->globalProject('xaas', 'eu-registry:5000/runr-builds/shop:v1');
        $us->update(['status' => 'ready', 'served_version_id' => $deployment->version_id, 'build_image' => 'us-registry:5000/runr-builds/shop:v1']);

        GlobalProjectsSchema::moveBuildImages();
        GlobalProjectsSchema::moveBuildImages();

        $rows = VersionRegionImage::query()->where('knative_region_id', $this->us->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('ready', $rows[0]->status);
        $this->assertSame('us-registry:5000/runr-builds/shop:v1', $rows[0]->image);
        $this->assertSame((string) $deployment->version_id, (string) $rows[0]->version_id);
    }
}
