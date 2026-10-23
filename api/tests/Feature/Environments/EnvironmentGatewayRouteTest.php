<?php

namespace Tests\Feature\Environments;

use App\Jobs\CheckDeploymentReadyJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentManager;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EmulatesConcurrentWrites;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * Gateway routes to an environment (docs/environments.md#gateway-routes): the route hands requests
 * to the environment's Knative Service <slug>-<name> in the region the environment runs in, the
 * gateway must be in that region, one route per project environment; "only through the gateway"
 * takes the environment's own URL away and leaves production's; the environment's deploy syncs its
 * gateway; deleting the environment removes its route.
 */
class EnvironmentGatewayRouteTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, EmulatesConcurrentWrites, RefreshDatabase;

    private User $owner;

    private KnativeRegion $fn;

    private Project $shop;

    private ProjectEnvironment $staging;

    private Gateway $gw;

    protected function setUp(): void
    {
        parent::setUp();
        config(['api_product.callback_url' => 'https://runr.example.com']);

        $this->owner = $this->userWithRole('owner', ['username' => 'jane']);
        $this->fn = $this->region('fn', ['knative_settings' => ['knative_domain' => 'fn.example.com']]);
        $this->shop = $this->project($this->fn, 'shop');
        $this->shop->forceFill(['endpoint_type' => 'subdomain'])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $this->shop->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'scale_to_zero' => true, 'min_scale' => 0, 'max_scale' => 2,
        ]);
        $this->shop = $this->shop->fresh();
        $this->staging = app(EnvironmentManager::class)->create($this->shop, ['name' => 'staging']);
        $this->gw = $this->gateway($this->owner, $this->fn);

        // the environment's Knative Service has a public URL of its own
        $workload = $this->staging->workload_name;
        $this->k8sResponses["GET /apis/serving.knative.dev/v1/namespaces/fn/services/{$workload}"] = [200, [
            'metadata' => ['name' => $workload, 'namespace' => 'fn'],
            'status' => ['url' => "https://{$workload}.fn.example.com"],
        ]];
        $this->fakeCluster();
    }

    private function addRoute(array $body): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/gateways/{$this->gw->id}/routes", $body + ['project_id' => $this->shop->id]);
    }

    public function test_a_route_to_an_environment_hands_requests_to_its_workload(): void
    {
        $this->addRoute(['environment' => 'staging', 'path' => '/staging'])
            ->assertCreated()
            ->assertJsonPath('data.environment', 'staging')
            ->assertJsonPath('data.environment_id', $this->staging->id);

        $route = GatewayRoute::sole();
        $this->assertSame($this->staging->id, $route->environment_id);
        $this->assertSame('subdomain', $this->shop->fresh()->endpoint_type, 'production keeps its own endpoint');

        $host = $this->k8sCall('PATCH', "/middlewares/gw-host-{$route->id}");
        $this->assertNotNull($host);
        $this->assertSame("{$this->staging->workload_name}.fn.example.com", $host['body']['spec']['headers']['customRequestHeaders']['Host']);

        $ingress = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame('Host(`jane.fn.example.com`) && PathPrefix(`/staging`)', $ingress['body']['spec']['routes'][0]['match']);

        // the gateway lists it with its environment; production's own route is another one
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/gateways/{$this->gw->id}")
            ->assertOk()->assertJsonPath('data.routes.0.environment', 'staging');
        $this->addRoute(['path' => '/shop'])->assertCreated()->assertJsonPath('data.environment', null);
        $this->assertSame('gateway', $this->shop->fresh()->endpoint_type);
        $this->assertSame((string) GatewayRoute::query()->whereNull('environment_id')->value('id'), (string) $this->shop->fresh()->gatewayRoute->id);
    }

    public function test_one_route_per_environment_in_the_environment_s_region(): void
    {
        $this->addRoute(['environment' => 'staging', 'path' => '/staging'])->assertCreated();
        $this->addRoute(['environment' => 'staging', 'path' => '/staging-2'])
            ->assertUnprocessable()->assertJsonValidationErrors('environment');
        $this->addRoute(['environment' => 'staging', 'path' => '/staging'])
            ->assertUnprocessable()->assertJsonValidationErrors('path');
        $this->addRoute(['environment' => 'nope', 'path' => '/nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('environment');

        // an environment in another region is reached by a gateway of that region
        $eu = $this->region('eu');
        app(EnvironmentManager::class)->create($this->shop, ['name' => 'qa', 'knative_region_id' => $eu->id]);
        $this->addRoute(['environment' => 'qa', 'path' => '/qa'])
            ->assertUnprocessable()->assertJsonValidationErrors('environment');
        // the caller's gateway there: created once, then found
        $euGateway = $this->actingAs($this->owner, 'sanctum')->postJson('/api/gateways', ['knative_region_id' => $eu->id])
            ->assertCreated()->assertJsonPath('data.knative_region_id', $eu->id)->json('data.id');
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/gateways', ['knative_region_id' => $eu->id])
            ->assertOk()->assertJsonPath('data.id', $euGateway);
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/gateways/{$euGateway}/routes", ['project_id' => $this->shop->id, 'environment' => 'qa', 'path' => '/qa'])
            ->assertCreated()->assertJsonPath('data.environment', 'qa');
    }

    public function test_the_environment_s_route_another_request_took_after_the_check_is_a_422(): void
    {
        // another request routes the environment between the check and the insert (the last query
        // before it reads the gateway's highest sort order)
        $this->afterQuery('max("sort_order")', fn () => DB::table('gateway_routes')->insert([
            'id' => (string) Str::ulid(), 'gateway_id' => $this->gw->id, 'project_id' => $this->shop->id,
            'environment_id' => $this->staging->id, 'path' => '/staging-1', 'strip_prefix' => true, 'sort_order' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->addRoute(['environment' => 'staging', 'path' => '/staging-2'])
            ->assertUnprocessable()->assertJsonValidationErrors('environment');
        $this->assertSame(['/staging-1'], GatewayRoute::query()->pluck('path')->all());
    }

    public function test_gateway_only_closes_the_environment_s_own_url_and_leaves_production_s(): void
    {
        $this->addRoute(['environment' => 'staging', 'path' => '/staging'])->assertCreated();
        $route = GatewayRoute::sole();
        $this->calls = [];

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$route->id}", ['gateway_only' => true])->assertOk();

        $patch = $this->k8sCall('PATCH', "/services/{$this->staging->workload_name}");
        $this->assertNotNull($patch, 'the environment\'s Service goes cluster-local');
        $this->assertSame('cluster-local', $patch['body']['metadata']['labels']['networking.knative.dev/visibility']);
        $this->assertNull($this->k8sCall('PATCH', "/services/{$this->shop->slug}"), 'production\'s Service is not touched');

        // and every deploy of the environment renders it so; production's stays public
        $version = Version::create(['project_id' => $this->shop->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready', 'config_snapshot' => ['image' => 'nginx:1.27-alpine']]);
        $deployer = app(KnativeDeployService::class);
        $visibility = fn (Project $p) => collect($deployer->buildManifests($p, $version))->firstWhere('kind', 'Service')['metadata']['labels']['networking.knative.dev/visibility'] ?? null;
        $this->assertSame('cluster-local', $visibility(DeployTarget::environment($this->shop->fresh(), $this->staging)));
        $this->assertNull($visibility($this->shop->fresh()));
    }

    public function test_the_environment_s_url_is_its_gateway_route_s(): void
    {
        $this->addRoute(['environment' => 'staging', 'path' => '/staging'])->assertCreated();

        $environments = collect($this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$this->shop->id}/environments")->assertOk()->json('data'))->keyBy('name');
        $this->assertSame('https://jane.fn.example.com/staging', $environments['staging']['url']);
        $this->assertSame("https://{$this->staging->workload_name}.fn.example.com", $environments['staging']['default_url']);
        $this->assertSame('/staging', $environments['staging']['gateway_route']['path']);
        $this->assertNull($environments['production']['gateway_route'], 'production has no route');
    }

    public function test_the_environment_s_deploy_syncs_its_gateway(): void
    {
        $this->addRoute(['environment' => 'staging', 'path' => '/staging'])->assertCreated();
        $version = Version::create(['project_id' => $this->shop->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready', 'config_snapshot' => ['image' => 'nginx:1.27-alpine']]);
        $deployment = Deployment::query()->create([
            'project_id' => $this->shop->id, 'version_id' => $version->id, 'environment_id' => $this->staging->id, 'status' => 'pending',
            'k8s_job_name' => 'run-x', 'deployed_by' => 'dev',
        ]);
        Queue::fake([CheckDeploymentReadyJob::class]);
        $this->calls = [];

        app(KnativeDeployService::class)->deploy($this->shop->fresh(), $version, $deployment);

        $this->assertNotNull($this->k8sCall('PATCH', "/services/{$this->staging->workload_name}"), 'the environment is deployed');
        $this->assertNotNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"), 'its gateway is synced');
    }

    public function test_deleting_the_environment_removes_its_route_and_its_region_cannot_change_meanwhile(): void
    {
        $this->addRoute(['environment' => 'staging', 'path' => '/staging'])->assertCreated();
        $this->addRoute(['path' => '/shop'])->assertCreated();

        try {
            app(EnvironmentManager::class)->update($this->staging->fresh(), ['knative_region_id' => $this->region('eu')->id]);
            $this->fail('the region of an environment with a gateway route does not change');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        $this->calls = [];
        app(EnvironmentManager::class)->delete($this->staging->fresh());

        $this->assertSame(1, GatewayRoute::count(), 'production\'s route stays');
        $this->assertNull(GatewayRoute::query()->whereNotNull('environment_id')->first());
        $ingress = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}");
        $this->assertNotNull($ingress, 'the gateway is synced without the route');
        $this->assertSame(['Host(`jane.fn.example.com`) && PathPrefix(`/shop`)'], array_column($ingress['body']['spec']['routes'], 'match'));
    }

    public function test_project_delete_removes_its_routes_from_the_gateway(): void
    {
        $other = $this->project($this->fn, 'other');
        $this->route($this->gw, $other, '/other');
        $this->addRoute(['path' => '/shop'])->assertCreated();
        $this->calls = [];

        $this->shop->fresh()->delete();

        $this->assertSame(['/other'], GatewayRoute::query()->pluck('path')->all());
        $ingress = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}");
        $this->assertNotNull($ingress);
        $this->assertSame(['Host(`jane.fn.example.com`) && PathPrefix(`/other`)'], array_column($ingress['body']['spec']['routes'], 'match'));
    }
}
