<?php

namespace Tests\Feature\Kubeconfigs;

use App\Http\Middleware\EnsureRegionMutable;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\Dns\DnsResolver;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KnativeStatusService;
use App\Services\Kubeconfigs\KubeconfigPin;
use App\Services\RegionReconcile\RegionReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Tenancy\Http\Middleware\EnsureTenantRegionMutable;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantRegionReconcileSource;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * An organization's Owner or Admin registers the organization's own regions: the kubeconfig they
 * send is held to KubeconfigPolicy (no credential plugin, no file, no proxy, no impersonation, the
 * current context only) and its server to a public address, before RunR connects anywhere — at
 * validate-kubeconfig, at a replacement of the kubeconfig and when the region is used. What a
 * failed connection said reaches platform administrators only; the organization's users read a
 * short reason (docs/security.md, "Tenant region kubeconfigs").
 */
class TenantRegionKubeconfigTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, WiresTenancy;

    private Tenant $acme;

    /** @var list<string> URLs the API requested */
    private array $requested = [];

    /** @var array<int|string, mixed> the cluster's answers (fakeCluster()) */
    private array $routes = [];

    private bool $faked = false;

    /** @var DnsResolver&object{answers: array<string, list<string>>} */
    private DnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);
        // what TenancyServiceProvider binds when tenancy is enabled
        $this->app->bind(EnsureRegionMutable::class, EnsureTenantRegionMutable::class);

        $this->dns = new class extends DnsResolver
        {
            /** @var array<string, list<string>> */
            public array $answers = ['kube.acme.example' => ['93.184.216.34']];

            public function addresses(string $name): array
            {
                return $this->answers[rtrim($name, '.')] ?? [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);

        $this->fakeCluster();
        $this->acme = $this->createTenantWithSchema('acme', [
            ...self::TENANT_REGION_MIGRATIONS,
            'Modules/Tenancy/Database/TenantMigrations/0040_create_rbac_tables.php',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /**
     * The cluster's answers: $routes replace the earlier ones (one fake, registered once).
     *
     * @param  array<int|string, mixed>  $routes  "GET /path" => [body, status] | Closure
     */
    private function fakeCluster(array $routes = []): void
    {
        $this->routes = $routes;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            $this->requested[] = $request->url();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $route = $this->routes[$request->method().' '.$path] ?? null;
            if ($route instanceof \Closure) {
                return $route($request);
            }
            if (is_array($route)) {
                return Http::response($route[0], $route[1]);
            }
            if ($path === '/version') {
                return Http::response(['major' => '1', 'minor' => '33', 'gitVersion' => 'v1.33.0']);
            }

            return $request->method() === 'GET' ? Http::response(['metadata' => ['uid' => 'kube-system-uid']]) : Http::response([]);
        });
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['username' => 'u'.Str::lower(Str::random(10))]);
        DB::connection('central')->table('tenant_users')->insert([
            'tenant_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $cluster
     * @param  array<string, mixed>  $extra  top-level fields
     */
    private static function kubeconfig(array $user = ['token' => 'sa-token'], array $cluster = [], array $extra = []): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'acme',
            'clusters' => [['name' => 'acme', 'cluster' => $cluster + ['server' => 'https://kube.acme.example:6443', 'insecure-skip-tls-verify' => true]]],
            'users' => [['name' => 'sa', 'user' => $user]],
            'contexts' => [['name' => 'acme', 'context' => ['cluster' => 'acme', 'user' => 'sa']]],
        ] + $extra, 10, 2);
    }

    private function inTenant(): void
    {
        tenancy()->initialize($this->acme);
        $this->flushRbac();
    }

    public function test_an_organization_owner_cannot_send_a_kubeconfig_that_runs_a_program_or_reads_a_file(): void
    {
        $owner = $this->member('owner');
        $this->inTenant();

        foreach ([
            'users[0].user.exec' => ['exec' => ['apiVersion' => 'client.authentication.k8s.io/v1', 'command' => '/bin/sh', 'args' => ['-c', 'id']]],
            'users[0].user.tokenFile' => ['tokenFile' => '/var/run/secrets/kubernetes.io/serviceaccount/token'],
            'users[0].user.auth-provider' => ['auth-provider' => ['name' => 'gcp']],
        ] as $field => $user) {
            foreach (['/api/knative-regions/validate-kubeconfig', '/api/knative-regions/preview-nodes', '/api/knative-regions/preview-storage-classes'] as $uri) {
                $this->signedIn($owner)->postJson($uri, ['kubeconfig' => self::kubeconfig($user)])
                    ->assertStatus(422)
                    ->assertJsonPath('errors.kubeconfig.0', fn (string $message) => str_contains($message, $field));
            }
        }
        $this->signedIn($owner)->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => self::kubeconfig(cluster: ['certificate-authority' => '/etc/ssl/certs/ca.crt'])])
            ->assertStatus(422)->assertJsonPath('errors.kubeconfig.0', fn (string $m) => str_contains($m, 'certificate-authority'));

        $this->assertSame([], $this->requested, 'RunR connected nowhere');
    }

    public function test_an_internal_server_is_refused_before_anything_connects(): void
    {
        $owner = $this->member('owner');
        $this->inTenant();
        $this->dns->answers['kube.acme.example'] = ['10.0.0.5'];

        foreach ([self::kubeconfig(), self::kubeconfig(cluster: ['server' => 'https://169.254.169.254']), self::kubeconfig(cluster: ['server' => 'https://kubernetes.default.svc'])] as $kubeconfig) {
            $message = $this->signedIn($owner)->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => $kubeconfig])
                ->assertStatus(422)->json('errors.kubeconfig.0');
            $this->assertStringContainsString(__('observability.url.private'), $message);
            $this->assertStringNotContainsString('10.0.0.5', $message);
        }

        $this->assertSame([], $this->requested);
    }

    public function test_a_failed_connection_gives_the_organization_a_short_reason_and_platform_administrators_the_details(): void
    {
        $owner = $this->member('owner');
        $admin = $this->member('owner');
        $admin->forceFill(['is_admin' => true])->save();
        $this->inTenant();

        $curl = 'cURL error 7: Failed to connect to 93.184.216.34 port 6443 after 3 ms: Could not connect to server';
        $this->fakeCluster(['GET /version' => fn () => Http::failedConnection($curl)]);

        $short = $this->signedIn($owner)->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => self::kubeconfig()])
            ->assertStatus(422)->json('errors.kubeconfig.0');
        $this->assertSame(__('api.region.connect_failed', ['message' => __('regions.kubeconfig.unreachable')]), $short);
        $this->assertStringNotContainsString('93.184.216.34', $short);

        $detailed = $this->signedIn($admin)->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => self::kubeconfig()])
            ->assertStatus(422)->json('errors.kubeconfig.0');
        $this->assertStringContainsString($curl, $detailed);

        // an answer that is no version: its status only
        $this->fakeCluster(['GET /version' => [['kind' => 'Status', 'message' => 'Unauthorized from 10.1.2.3'], 401]]);
        $this->signedIn($owner)->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => self::kubeconfig()])
            ->assertStatus(422)->assertJsonPath('errors.kubeconfig.0', __('api.region.connect_failed', ['message' => __('regions.kubeconfig.rejected', ['status' => 401])]));
    }

    /**
     * The same holds for every read of the organization's own region that reaches its cluster or
     * shows what a job stored about it: the nodes, a component's install status, the region's
     * status messages. A message of RunR's own (no connection error in it) reaches them as it is.
     */
    public function test_reads_of_an_organizations_region_give_its_users_the_short_reason(): void
    {
        $owner = $this->member('owner');
        $admin = $this->member('owner');
        $admin->forceFill(['is_admin' => true])->save();
        $this->inTenant();
        $helm = 'Error: Kubernetes cluster unreachable: Get "https://kube.acme.example:6443/version": dial tcp 93.184.216.34:6443: connect: connection refused';
        $region = new KnativeRegion(['name' => 'acme-dc', 'project_namespace' => 'acme-dc']);
        $region->forceFill([
            'is_synced' => false, 'owner_tenant_id' => $this->acme->id, 'kubeconfig' => self::kubeconfig(),
            'knative_serving_status' => 'error', 'knative_serving_status_message' => $helm,
            'knative_operator_status' => 'error', 'knative_operator_status_message' => 'Knative Operator 1.21 needs Kubernetes 1.33 or newer',
        ])->save();

        $curl = 'cURL error 7: Failed to connect to 93.184.216.34 port 6443 after 3 ms: Could not connect to server';
        $this->fakeCluster(['GET /api/v1/nodes' => fn () => Http::failedConnection($curl)]);

        $nodes = $this->signedIn($owner)->getJson("/api/knative-regions/{$region->id}/nodes")->assertStatus(400)->json('message');
        $this->assertSame(__('api.region.connect_failed', ['message' => __('regions.kubeconfig.unreachable')]), $nodes);
        $this->assertStringContainsString($curl, $this->signedIn($admin)->getJson("/api/knative-regions/{$region->id}/nodes")->assertStatus(400)->json('message'));

        $status = $this->signedIn($owner)->getJson("/api/knative-regions/{$region->id}/component-status")->assertOk();
        $this->assertSame(__('regions.kubeconfig.unreachable'), $status->json('serving.message'));
        $this->assertSame('Knative Operator 1.21 needs Kubernetes 1.33 or newer', $status->json('operator.message'), "RunR's own message");
        $this->assertSame(__('regions.kubeconfig.unreachable'), $status->json('region.knative_serving_status_message'));
        $this->assertStringNotContainsString('93.184.216.34', $status->getContent());

        $this->assertSame($helm, $this->signedIn($admin)->getJson("/api/knative-regions/{$region->id}/component-status")->json('serving.message'));
    }

    public function test_an_accepted_kubeconfig_reaches_the_checked_address_and_answers_its_version(): void
    {
        $owner = $this->member('owner');
        $this->inTenant();

        $this->signedIn($owner)->postJson('/api/knative-regions/validate-kubeconfig', ['kubeconfig' => self::kubeconfig(extra: ['preferences' => []])])
            ->assertOk()->assertJsonPath('version', '1.33')->assertJsonPath('kubeconfig', null);
        $this->assertSame(['https://kube.acme.example:6443/version'], $this->requested);
    }

    public function test_the_kubeconfig_of_the_organizations_region_is_replaced_by_the_policys_rebuild(): void
    {
        $owner = $this->member('owner');
        $this->inTenant();
        $region = new KnativeRegion(['name' => 'acme-dc', 'project_namespace' => 'acme-dc']);
        $region->forceFill(['is_synced' => false, 'owner_tenant_id' => $this->acme->id, 'kubeconfig' => self::kubeconfig(['token' => 'old'])])->save();

        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}", ['kubeconfig' => self::kubeconfig(['exec' => ['command' => 'sh', 'apiVersion' => 'client.authentication.k8s.io/v1']])])
            ->assertStatus(422)->assertJsonPath('errors.kubeconfig.0', fn (string $m) => str_contains($m, 'users[0].user.exec'));

        $this->signedIn($owner)->putJson("/api/knative-regions/{$region->id}", ['kubeconfig' => self::kubeconfig(['token' => 'new'], extra: ['preferences' => ['colors' => true]])])
            ->assertOk()->assertJsonPath('has_kubeconfig', true);

        $region = KnativeRegion::query()->findOrFail($region->id);
        $stored = Yaml::parse((string) $region->storedKubeconfig());
        $this->assertSame(['token' => 'new'], $stored['users'][0]['user']);
        $this->assertArrayNotHasKey('preferences', $stored, 'what is stored is the policy\'s rebuild');
        $this->assertSame(['93.184.216.34'], KubeconfigPin::addresses(Yaml::parse((string) $region->kubeconfig)['clusters'][0]['cluster']));

        // the owner downloads what was stored, without RunR's pin
        $download = $this->signedIn($owner)->get("/api/knative-regions/{$region->id}/kubeconfig")->assertOk()->streamedContent();
        $this->assertStringNotContainsString(KubeconfigPin::SCHEME, $download);
    }

    public function test_region_reconcile_leaves_a_refused_kubeconfig_alone_and_marks_its_region(): void
    {
        $ids = $this->acme->run(function () {
            $ids = [];
            foreach (['refused' => self::kubeconfig(['tokenFile' => '/var/run/secrets/kubernetes.io/serviceaccount/token']), 'fine' => self::kubeconfig()] as $name => $kubeconfig) {
                $region = new KnativeRegion(['name' => "acme-{$name}", 'project_namespace' => "acme-{$name}"]);
                $region->forceFill([
                    'is_synced' => false, 'owner_tenant_id' => $this->acme->id, 'is_active' => true, 'is_demo' => false,
                    'knative_serving_status' => 'ready', 'kubeconfig' => $kubeconfig, 'cluster_status' => 'online',
                ])->save();
                $ids[$name] = $region->id;
            }

            return $ids;
        });

        TenantRegionReconcileSource::register();
        $reconciler = new class(app(RunrClusterIdentity::class)) extends RegionReconciler
        {
            public function steps(): array
            {
                return [];
            }
        };
        $lines = [];
        try {
            $outcome = $reconciler->reconcile(null, false, function (string $level, string $message) use (&$lines) {
                $lines[] = "{$level} {$message}";
            });
        } finally {
            RegionReconciler::clearRegionSources();
        }

        $this->assertStringStartsWith('kubeconfig refused: ', $outcome->regions[$ids['refused']][0] ?? '', implode("\n", $lines));
        $this->assertStringContainsString('users[0].user.tokenFile', $outcome->regions[$ids['refused']][0]);
        $this->assertArrayNotHasKey($ids['fine'], $outcome->regions, implode("\n", $lines));
        $this->assertNotEmpty(array_filter($outcome->unreachable, fn (string $u) => str_contains($u, 'acme-refused')));

        [$status, $message] = $this->acme->run(fn () => [
            KnativeRegion::query()->findOrFail($ids['refused'])->cluster_status,
            KnativeRegion::query()->findOrFail($ids['refused'])->cluster_status_message,
        ]);
        $this->assertSame('unreachable', $status);
        $this->assertStringContainsString('users[0].user.tokenFile', (string) $message);
        $this->assertSame([], array_filter($this->requested, fn (string $url) => ! str_starts_with($url, 'https://kube.acme.example:6443/')), 'only the accepted region\'s cluster was asked');
    }

    public function test_a_stored_kubeconfig_of_an_earlier_release_is_not_used_and_says_why(): void
    {
        $this->inTenant();
        $region = new KnativeRegion(['name' => 'acme-dc', 'project_namespace' => 'acme-dc']);
        $region->forceFill([
            'is_synced' => false, 'owner_tenant_id' => $this->acme->id, 'is_active' => true,
            'kubeconfig' => self::kubeconfig(['exec' => ['command' => '/bin/sh', 'apiVersion' => 'client.authentication.k8s.io/v1']]),
        ])->save();
        $region = KnativeRegion::query()->findOrFail($region->id);

        $this->assertNull($region->kubeconfig);
        $result = app(KnativeStatusService::class)->checkStatus($region);
        $this->assertSame('unreachable', $result['clusterStatus']);
        $this->assertStringContainsString('users[0].user.exec', (string) $result['clusterMessage']);
        $this->assertStringStartsWith(Str::before(__('regions.kubeconfig.unusable', ['reason' => '§']), '§'), (string) $result['clusterMessage']);
        $this->assertSame([], $this->requested, 'nothing connected');
    }
}
