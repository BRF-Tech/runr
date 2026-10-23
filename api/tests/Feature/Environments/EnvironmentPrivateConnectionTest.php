<?php

namespace Tests\Feature\Environments;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentManager;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Private database connections of an environment (docs/environments.md#private-database-connections):
 * the connection lives in the namespace of the region the environment runs in, carries the
 * environment's labels, and only the environment's workloads get its env vars — production keeps
 * its own, and a name or env var prefix production uses is free in an environment.
 */
class EnvironmentPrivateConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const SKUPPER = '/apis/skupper.io/v2alpha1';

    /** @var list<array{method: string, host: string, path: string, body: string}> */
    private array $calls = [];

    private Project $project;

    private ProjectEnvironment $staging;

    private KnativeRegion $eu;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        $this->fakeCluster();

        $fn = $this->region('fn');
        $this->eu = $this->region('eu');
        $this->project = Project::create(['name' => 'orders-api', 'service_type' => 'caas']);
        $this->project->forceFill(['knative_region_id' => $fn->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $this->project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'scale_to_zero' => true, 'min_scale' => 0, 'max_scale' => 2,
        ]);
        $this->project = $this->project->fresh();
        $this->staging = app(EnvironmentManager::class)->create($this->project, ['name' => 'staging', 'knative_region_id' => $this->eu->id]);
    }

    private function region(string $name): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => $name,
            'project_namespace' => $name,
            'is_active' => true,
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$name}.test:6443", 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
        ]);
    }

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $method = $request->method();
            $this->calls[] = ['method' => $method, 'host' => $host, 'path' => $path, 'body' => $request->body()];

            if ($method === 'GET' && $path === self::SKUPPER) {
                return Http::response(['kind' => 'APIResourceList', 'resources' => array_map(fn ($n) => ['name' => $n], ['sites', 'links', 'listeners', 'connectors', 'accesstokens'])]);
            }
            if ($method === 'GET' && preg_match('#^'.self::SKUPPER.'/namespaces/[a-z]+/(sites|listeners)$#', $path)) {
                return Http::response(['items' => []]);
            }
            if ($method === 'GET' && preg_match('#/serving.knative.dev/v1/namespaces/[a-z]+/services/(.+)$#', $path, $m)) {
                return Http::response(['metadata' => ['name' => $m[1]], 'spec' => ['template' => ['spec' => ['containers' => [['name' => 'user-container', 'env' => [['name' => 'FOO', 'value' => 'bar']]]]]]]]);
            }
            if (in_array($method, ['PATCH', 'DELETE', 'POST', 'PUT'], true)) {
                return Http::response([], 200);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });
    }

    /** @return list<array{method: string, host: string, path: string, body: string}> */
    private function sentAll(string $method, string $pathPart, ?string $host = null): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method && str_contains($c['path'], $pathPart) && ($host === null || $c['host'] === $host)));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'orders-db',
            'routing_key' => 'pg-orders-rw',
            'link_token' => "apiVersion: skupper.io/v2alpha1\nkind: AccessToken\nmetadata:\n  name: from-pg\nspec:\n"
                ."  url: https://skupper-grant.pg.example.io:9090/abc\n  code: s3cr3tc0de\n"
                ."  ca: |\n    -----BEGIN CERTIFICATE-----\n    MIIB\n    -----END CERTIFICATE-----\n",
            'database' => 'orders',
            'username' => 'app',
            'password' => 'Sup3r-S3cret!',
        ], $overrides);
    }

    private function user(): User
    {
        return User::query()->where('username', 'dev')->first() ?? User::factory()->create(['username' => 'dev']);
    }

    private function connect(array $payload): TestResponse
    {
        return $this->actingAs($this->user(), 'sanctum')->postJson("/api/projects/{$this->project->id}/private-connections", $this->payload($payload));
    }

    public function test_an_environment_s_connection_lives_in_its_region_and_reaches_its_workload_only(): void
    {
        $this->connect(['environment' => 'staging'])
            ->assertCreated()
            ->assertJsonPath('data.environment', 'staging')
            ->assertJsonPath('data.environment_id', $this->staging->id)
            ->assertJsonPath('env_synced', true);

        $binding = PrivateDatabaseBinding::sole();
        $this->assertSame($this->staging->id, $binding->environment_id);
        $name = $binding->resource_name;

        // its Site, Secret, AccessToken and Listener in the environment's region (eu), none in production's (fn)
        $this->assertNotEmpty($this->sentAll('PATCH', self::SKUPPER.'/namespaces/eu/sites/runr', 'eu.test'));
        $listener = $this->sentAll('PATCH', self::SKUPPER."/namespaces/eu/listeners/{$name}", 'eu.test');
        $this->assertCount(1, $listener);
        $labels = json_decode($listener[0]['body'], true)['metadata']['labels'];
        $this->assertSame($this->staging->id, $labels['runr/environment-id']);
        $this->assertSame('staging', $labels['runr/environment']);
        $this->assertNotEmpty($this->sentAll('PATCH', "/namespaces/eu/secrets/{$name}-pgconn", 'eu.test'));
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c['host'] === 'fn.test' && $c['method'] !== 'GET'), 'nothing is applied in production\'s region');

        // the environment's running workload gets the env vars, production's does not
        $patch = $this->sentAll('PATCH', '/apis/serving.knative.dev/v1/namespaces/eu/services/orders-api-staging', 'eu.test');
        $this->assertCount(1, $patch);
        $ops = json_decode($patch[0]['body'], true);
        $this->assertContains('DATABASE_URL', array_column($ops[0]['value'], 'name'));
        $this->assertSame([], $this->sentAll('PATCH', '/services/orders-api', 'fn.test'));
    }

    public function test_names_and_prefixes_are_per_environment_and_lists_follow_the_environment(): void
    {
        $this->connect([])->assertCreated()->assertJsonPath('data.environment', null);
        // the same name and (empty) prefix in staging: its own DATABASE_URL
        $this->connect(['environment' => 'staging'])->assertCreated();
        // but not twice in staging
        $this->connect(['environment' => 'staging', 'name' => 'reports-db'])
            ->assertUnprocessable()->assertJsonValidationErrors('env_prefix');
        $this->connect(['environment' => 'staging'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->connect(['environment' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('environment');

        $user = $this->user();
        $production = $this->actingAs($user, 'sanctum')->getJson("/api/projects/{$this->project->id}/private-connections")->assertOk()->json('data');
        $staging = $this->actingAs($user, 'sanctum')->getJson("/api/projects/{$this->project->id}/private-connections?environment=staging")->assertOk()->json('data');
        $this->assertCount(1, $production);
        $this->assertNull($production[0]['environment']);
        $this->assertCount(1, $staging);
        $this->assertSame('staging', $staging[0]['environment']);
        $this->assertNotSame($production[0]['resource_name'], $staging[0]['resource_name']);
    }

    public function test_the_manifests_of_each_workload_carry_its_own_connections(): void
    {
        $version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        PrivateDatabaseBinding::create([
            'project_id' => $this->project->id, 'name' => 'orders-db', 'resource_name' => 'orders-db-aaaaaa',
            'env_prefix' => 'ORDERS_', 'routing_key' => 'pg', 'port' => 5432, 'sslmode' => 'require', 'link_kind' => 'link',
        ]);
        PrivateDatabaseBinding::create([
            'project_id' => $this->project->id, 'environment_id' => $this->staging->id, 'name' => 'orders-db', 'resource_name' => 'orders-db-bbbbbb',
            'env_prefix' => 'ORDERS_', 'routing_key' => 'pg-staging', 'port' => 5432, 'sslmode' => 'require', 'link_kind' => 'link',
        ]);
        $deployer = app(KnativeDeployService::class);

        $refOf = function (Project $project) use ($deployer, $version): ?string {
            $ksvc = collect($deployer->buildManifests($project, $version))->firstWhere('kind', 'Service');
            $url = collect($ksvc['spec']['template']['spec']['containers'][0]['env'])->firstWhere('name', 'ORDERS_DATABASE_URL');

            return $url['valueFrom']['secretKeyRef']['name'] ?? null;
        };

        $this->assertSame('orders-db-aaaaaa-pgconn', $refOf($this->project->fresh()));
        $this->assertSame('orders-db-bbbbbb-pgconn', $refOf(DeployTarget::environment($this->project->fresh(), $this->staging)));
        $this->assertSame(['orders-db'], $this->project->fresh()->privateDatabaseBindings()->pluck('name')->all(), 'production lists its own');
    }

    public function test_deleting_the_environment_removes_its_connections_from_its_region(): void
    {
        $this->connect(['environment' => 'staging'])->assertCreated();
        $this->connect([])->assertCreated();
        $staging = PrivateDatabaseBinding::query()->whereNotNull('environment_id')->sole();
        $this->calls = [];

        app(EnvironmentManager::class)->delete($this->staging->fresh());

        $this->assertNotEmpty($this->sentAll('DELETE', self::SKUPPER."/namespaces/eu/listeners/{$staging->resource_name}", 'eu.test'));
        $this->assertNotEmpty($this->sentAll('DELETE', "/namespaces/eu/secrets/{$staging->resource_name}-pgconn", 'eu.test'));
        $this->assertSame(1, PrivateDatabaseBinding::count(), 'production\'s connection stays');
        $this->assertNull(PrivateDatabaseBinding::query()->whereNotNull('environment_id')->first());
    }

    public function test_the_region_of_an_environment_with_a_connection_does_not_change(): void
    {
        $this->connect(['environment' => 'staging'])->assertCreated();

        $this->assertTrue(PrivateDatabaseBinding::usedInRegion($this->eu), 'the environment\'s region needs its Skupper site');

        try {
            app(EnvironmentManager::class)->update($this->staging->fresh(), ['knative_region_id' => $this->project->knative_region_id]);
            $this->fail('the region change is refused');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertArrayHasKey('knative_region_id', $e->getResponse()->getData(true)['errors']);
        }
    }

    public function test_project_delete_removes_the_connections_of_its_environments(): void
    {
        $this->connect(['environment' => 'staging'])->assertCreated();
        $staging = PrivateDatabaseBinding::sole();

        $this->project->fresh()->delete();

        $this->assertNotEmpty($this->sentAll('DELETE', self::SKUPPER."/namespaces/eu/listeners/{$staging->resource_name}", 'eu.test'));
        $this->assertSame(0, PrivateDatabaseBinding::count());
    }
}
