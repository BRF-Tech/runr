<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\PrivateDatabaseBinding;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\KnativeDeployService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Private access from functions to Managed PostgreSQL through Skupper v2 —
 * API, cluster resources (faked by URL), env injection and cleanup.
 */
class PrivateDatabaseBindingTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = 'https://region.test:6443';

    private const SKUPPER = '/apis/skupper.io/v2alpha1';

    /** @var list<array{method:string, path:string, body:string}> */
    private array $calls = [];

    /** Cluster behaviour knobs for the fake */
    private bool $skupperInstalled = true;

    private array $listeners = [];

    private array $ksvcEnv = [['name' => 'FOO', 'value' => 'bar']];

    private ?string $failApplyOf = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid()]);
        $this->fakeCluster();
    }

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

    private function fakeCluster(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = $request->method();
            $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $request->body()];

            if ($method === 'GET' && $path === self::SKUPPER) {
                return $this->skupperInstalled
                    ? Http::response(['kind' => 'APIResourceList', 'resources' => array_map(fn ($n) => ['name' => $n], ['sites', 'links', 'listeners', 'connectors', 'accesstokens', 'accessgrants'])])
                    : Http::response(['kind' => 'Status', 'code' => 404], 404);
            }
            if ($method === 'GET' && $path === self::SKUPPER.'/namespaces/fn/sites') {
                return Http::response(['items' => []]);
            }
            if ($method === 'GET' && $path === self::SKUPPER.'/namespaces/fn/listeners') {
                return Http::response(['items' => $this->listeners]);
            }
            if ($method === 'GET' && str_starts_with($path, self::SKUPPER.'/namespaces/fn/links/')) {
                return Http::response(['status' => ['status' => 'Ready', 'message' => 'OK', 'remoteSiteName' => 'pg-site']]);
            }
            if ($method === 'GET' && str_starts_with($path, self::SKUPPER.'/namespaces/fn/listeners/')) {
                return Http::response(['status' => ['status' => 'Ready', 'hasMatchingConnector' => true]]);
            }
            if ($method === 'GET' && str_starts_with($path, self::SKUPPER.'/namespaces/fn/accesstokens/')) {
                return Http::response(['status' => ['status' => 'Ready', 'redeemed' => true]]);
            }
            if ($method === 'GET' && $path === '/apis/serving.knative.dev/v1/namespaces/fn/services/orders-api') {
                return Http::response(['spec' => ['template' => ['spec' => ['containers' => [['name' => 'user-container', 'env' => $this->ksvcEnv]]]]]]);
            }
            if ($method === 'PATCH' && $this->failApplyOf && str_contains($path, $this->failApplyOf)) {
                return Http::response(['message' => 'denied'], 500);
            }
            if (in_array($method, ['PATCH', 'DELETE', 'POST', 'PUT'], true)) {
                return Http::response([], 200);
            }

            return Http::response(['kind' => 'Status', 'code' => 404], 404);
        });
    }

    private function project(): Project
    {
        $region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'kubeconfig' => $this->kubeconfig(),
            'is_active' => true,
        ]);
        $project = Project::create(['name' => 'orders-api', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'trigger_type' => 'http',
        ]);

        return $project->fresh();
    }

    private ?User $user = null;

    private function user(): User
    {
        return $this->user ??= User::factory()->create(['username' => 'dev']);
    }

    private function accessToken(): string
    {
        return "apiVersion: skupper.io/v2alpha1\nkind: AccessToken\nmetadata:\n  name: from-pg\nspec:\n"
            ."  url: https://skupper-grant.cnpg-link.example.io:9090/abc\n  code: s3cr3tc0de\n"
            ."  ca: |\n    -----BEGIN CERTIFICATE-----\n    MIIB\n    -----END CERTIFICATE-----\n";
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'orders-db',
            'routing_key' => 'pg-orders-rw',
            'link_token' => $this->accessToken(),
            'database' => 'orders',
            'username' => 'app',
            'password' => 'Sup3r-S3cret!',
        ], $overrides);
    }

    private function sent(string $method, string $pathPart): ?array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method && str_contains($call['path'], $pathPart)) {
                return $call;
            }
        }

        return null;
    }

    public function test_store_applies_site_secret_token_listener_and_env(): void
    {
        $project = $this->project();

        $response = $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'orders-db')
            ->assertJsonPath('data.link_kind', 'access_token')
            ->assertJsonPath('env_synced', true);

        $binding = PrivateDatabaseBinding::sole();
        $name = $binding->resource_name;
        $this->assertMatchesRegularExpression('/^orders-db-[0-9a-z]{6}$/', $name);
        $response->assertJsonPath('data.host', $name);

        $this->assertNotNull($this->sent('PATCH', self::SKUPPER.'/namespaces/fn/sites/runr'));

        $secret = json_decode($this->sent('PATCH', "/api/v1/namespaces/fn/secrets/{$name}-pgconn")['body'], true);
        $this->assertSame('Sup3r-S3cret!', base64_decode($secret['data']['PGPASSWORD']));
        $this->assertSame($name, base64_decode($secret['data']['PGHOST']));

        $token = json_decode($this->sent('PATCH', self::SKUPPER."/namespaces/fn/accesstokens/{$name}")['body'], true);
        $this->assertSame('s3cr3tc0de', $token['spec']['code']);

        $listener = json_decode($this->sent('PATCH', self::SKUPPER."/namespaces/fn/listeners/{$name}")['body'], true);
        $this->assertSame(['routingKey' => 'pg-orders-rw', 'host' => $name, 'port' => 5432], $listener['spec']);

        // running Knative Service gets the env (JSON patch), user env kept
        $patch = $this->sent('PATCH', '/apis/serving.knative.dev/v1/namespaces/fn/services/orders-api');
        $ops = json_decode($patch['body'], true);
        $this->assertSame('/spec/template/spec/containers/0/env', $ops[0]['path']);
        $this->assertSame(['FOO', 'DATABASE_URL', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD', 'PGSSLMODE'], array_column($ops[0]['value'], 'name'));
        $this->assertSame("{$name}-pgconn", $ops[0]['value'][6]['valueFrom']['secretKeyRef']['name']);

        // credentials never reach the database
        $row = json_encode(\DB::table('private_db_bindings')->first());
        $this->assertStringNotContainsString('Sup3r-S3cret!', $row);
        $this->assertStringNotContainsString('s3cr3tc0de', $row);
        $this->assertStringNotContainsString('"orders"', $row);
    }

    public function test_region_without_skupper_is_rejected(): void
    {
        $this->skupperInstalled = false;
        $project = $this->project();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project');

        $this->assertSame(0, PrivateDatabaseBinding::count());
        $this->assertNull($this->sent('PATCH', '/'));

        $this->actingAs($this->user(), 'sanctum')
            ->getJson("/api/knative-regions/{$project->knative_region_id}/skupper")
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    public function test_invalid_link_token_and_duplicate_prefix_are_rejected(): void
    {
        $project = $this->project();
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload([
                'link_token' => "apiVersion: v1\nkind: Secret\nmetadata:\n  labels:\n    skupper.io/type: connection-token\n",
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('link_token');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertCreated();

        // second binding with the same (empty) env prefix would overwrite DATABASE_URL
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload(['name' => 'reports-db']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('env_prefix');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload(['name' => 'reports-db', 'env_prefix' => 'REPORTS_']))
            ->assertCreated();
    }

    public function test_routing_key_of_another_tenant_is_rejected(): void
    {
        $project = $this->project();
        $this->listeners = [[
            'metadata' => ['name' => 'x-db-aaaaaa', 'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr/tenant' => 'other']],
            'spec' => ['routingKey' => 'pg-orders-rw', 'host' => 'x-db-aaaaaa', 'port' => 5432],
        ]];

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('routing_key');

        // same installation / tenant: sharing a routing key is allowed
        unset($this->listeners[0]['metadata']['labels']['runr/tenant']);
        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertCreated();
    }

    public function test_a_failed_apply_leaves_nothing_behind(): void
    {
        $project = $this->project();
        $this->failApplyOf = '/listeners/';

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertStatus(502);

        $this->assertSame(0, PrivateDatabaseBinding::count());
        $this->assertNotNull($this->sent('DELETE', '/secrets/'));
        $this->assertNotNull($this->sent('DELETE', '/accesstokens/'));
    }

    public function test_status_reports_link_and_listener(): void
    {
        $project = $this->project();
        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertCreated();
        $binding = PrivateDatabaseBinding::sole();

        $this->actingAs($this->user(), 'sanctum')
            ->getJson("/api/projects/{$project->id}/private-connections/{$binding->id}/status")
            ->assertOk()
            ->assertJsonPath('status.state', 'ready')
            ->assertJsonPath('status.link.status', 'Ready')
            ->assertJsonPath('status.listener.has_matching_connector', true)
            ->assertJsonPath('status.access_token.redeemed', true);
    }

    public function test_destroy_removes_the_resources_and_the_env(): void
    {
        $project = $this->project();
        $user = $this->user();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/private-connections", $this->payload())
            ->assertCreated();
        $binding = PrivateDatabaseBinding::sole();
        $name = $binding->resource_name;

        // the running service now carries the binding's env
        $this->ksvcEnv = array_merge([['name' => 'FOO', 'value' => 'bar']], $binding->envVars());
        $this->calls = [];

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/projects/{$project->id}/private-connections/{$binding->id}")
            ->assertOk()
            ->assertJsonPath('env_synced', true);

        foreach ([
            self::SKUPPER."/namespaces/fn/listeners/{$name}",
            self::SKUPPER."/namespaces/fn/accesstokens/{$name}",
            self::SKUPPER."/namespaces/fn/links/{$name}",
            "/api/v1/namespaces/fn/secrets/{$name}-pgconn",
            "/api/v1/namespaces/fn/secrets/{$name}-link",
        ] as $path) {
            $this->assertNotNull($this->sent('DELETE', $path), "DELETE {$path}");
        }

        $ops = json_decode($this->sent('PATCH', '/services/orders-api')['body'], true);
        $this->assertSame([['name' => 'FOO', 'value' => 'bar']], $ops[0]['value']);
        $this->assertSame(0, PrivateDatabaseBinding::count());
    }

    public function test_env_is_injected_into_the_ksvc_and_cronjob_manifests(): void
    {
        $project = $this->project();
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        PrivateDatabaseBinding::create([
            'project_id' => $project->id, 'name' => 'orders-db', 'resource_name' => 'orders-db-abc123',
            'env_prefix' => 'ORDERS_', 'routing_key' => 'pg', 'port' => 5432, 'sslmode' => 'require', 'link_kind' => 'link',
        ]);

        $ksvc = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(), $version))->firstWhere('kind', 'Service');
        $env = $ksvc['spec']['template']['spec']['containers'][0]['env'];
        $url = collect($env)->firstWhere('name', 'ORDERS_DATABASE_URL');
        $this->assertSame(['name' => 'orders-db-abc123-pgconn', 'key' => 'DATABASE_URL', 'optional' => true], $url['valueFrom']['secretKeyRef']);

        ContainerConfig::where('project_id', $project->id)->update(['trigger_type' => 'schedule', 'schedule' => '*/5 * * * *']);
        $cron = collect(app(KnativeDeployService::class)->buildManifests($project->fresh(), $version))->firstWhere('kind', 'CronJob');
        $names = array_column($cron['spec']['jobTemplate']['spec']['template']['spec']['containers'][0]['env'], 'name');
        $this->assertContains('ORDERS_PGPASSWORD', $names);
    }

    public function test_project_delete_cleans_up_its_private_connections(): void
    {
        $project = $this->project();
        PrivateDatabaseBinding::create([
            'project_id' => $project->id, 'name' => 'orders-db', 'resource_name' => 'orders-db-abc123',
            'env_prefix' => '', 'routing_key' => 'pg', 'port' => 5432, 'sslmode' => 'require', 'link_kind' => 'access_token',
        ]);

        $project->delete();

        $this->assertNotNull($this->sent('DELETE', self::SKUPPER.'/namespaces/fn/listeners/orders-db-abc123'));
        $this->assertNotNull($this->sent('DELETE', self::SKUPPER.'/namespaces/fn/accesstokens/orders-db-abc123'));
        $this->assertNotNull($this->sent('DELETE', '/api/v1/namespaces/fn/secrets/orders-db-abc123-pgconn'));
        $this->assertSame(0, PrivateDatabaseBinding::count());
    }

    public function test_region_site_is_ensured_once(): void
    {
        $project = $this->project();

        $this->actingAs($this->user(), 'sanctum')
            ->postJson("/api/knative-regions/{$project->knative_region_id}/skupper/site")
            ->assertOk()
            ->assertJsonPath('data.name', 'runr');

        $site = json_decode($this->sent('PATCH', self::SKUPPER.'/namespaces/fn/sites/runr')['body'], true);
        $this->assertSame('Site', $site['kind']);
        $this->assertSame([], $site['spec']);
    }
}
