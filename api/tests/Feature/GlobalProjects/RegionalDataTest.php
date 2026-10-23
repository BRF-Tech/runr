<?php

namespace Tests\Feature\GlobalProjects;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\BindingRegionService;
use App\Models\BindingReplica;
use App\Models\BindingUsageRecord;
use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\Setting;
use App\Services\Bindings\BindingEnv;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\BindingUsageMeter;
use App\Services\Bindings\Ops\BindingOpsRunner;
use App\Services\Bindings\Ops\OpsResult;
use App\Services\Bindings\Ops\OpsTask;
use App\Services\GlobalProjects\Data\DataLinkManifests;
use App\Services\GlobalProjects\Data\RegionalData;
use App\Services\GlobalProjects\Data\RegionalPostgresManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Regional data of global projects (docs/global-projects.md, "Data"): a binding's data link from
 * the other regions, a dedicated Postgres binding's replica cluster in every region, the env each
 * region's copy gets, a switchover and a failover of the binding's primary, the rejoin of the
 * region that lost it.
 */
class RegionalDataTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $eu;

    private KnativeRegion $us;

    /** @var list<array{host: string, method: string, path: string, body: array}> */
    private array $calls = [];

    /** @var array<string, array> "host GET path" => body */
    private array $responses = [];

    /** @var array<string, array{int, array}> "host METHOD path" => [status, body] of a refused write */
    private array $refusals = [];

    /** answer of the replica state SQL per region host */
    private array $replicaState = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('base_domain', 'example.com');
        config(['bindings.poll_interval' => 0]);

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['host' => $host, 'method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];

            if ($path === '/version') {
                return Http::response(['major' => '1', 'minor' => '32']);
            }
            if (isset($this->refusals["{$host} {$request->method()} {$path}"])) {
                [$status, $body] = $this->refusals["{$host} {$request->method()} {$path}"];

                return Http::response($body, $status);
            }
            if (isset($this->responses["{$host} {$request->method()} {$path}"])) {
                return Http::response($this->responses["{$host} {$request->method()} {$path}"]);
            }

            return $request->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response([], 200);
        });

        $state = &$this->replicaState;
        $this->app->instance(BindingOpsRunner::class, new class($state) implements BindingOpsRunner
        {
            public function __construct(private array &$state) {}

            public function run(KnativeRegion $region, OpsTask $task): OpsResult
            {
                if ($task->purpose === 'postgres-replica-state') {
                    return new OpsResult(true, 'STATE '.($this->state[$region->name] ?? 't streaming 1')."\nRUNR_OK\n");
                }

                return new OpsResult(true, "RUNR_OK\n");
            }
        });

        $this->eu = $this->region('eu-1', '203.0.113.10');
        $this->us = $this->region('us-1', '198.51.100.10');
    }

    private function region(string $name, string $ip): KnativeRegion
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
            'external_ip' => $ip,
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ]);
    }

    private function globalProject(): Project
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => 'ha'])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128]);
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->eu->id, 'position' => 0, 'status' => 'ready']);
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->us->id, 'position' => 1, 'status' => 'ready']);

        return $project->fresh();
    }

    private function binding(string $type, string $plan = 'shared', ?KnativeRegion $region = null): Binding
    {
        $region ??= $this->eu;
        foreach ([$this->eu, $this->us] as $r) {
            if (! BindingRegionService::forRegion($r, $type)) {
                BindingRegionService::create([
                    'knative_region_id' => $r->id,
                    'service' => $type,
                    'mode' => 'managed',
                    'status' => BindingRegionService::STATUS_READY,
                    'config' => $type === 'kv' ? ['acl' => true] : ['instances' => 1],
                    'secrets' => $type === 'kv' ? ['password' => 'kv-admin'] : null,
                ]);
            }
        }

        $id = strtolower((string) Str::ulid());
        $rn = Binding::resourceNameFor($type === 'kv' ? 'cache' : 'orders', $id);
        $pg = 'b_'.str_replace('-', '_', $rn);
        $binding = new Binding([
            'name' => $type === 'kv' ? 'cache' : 'orders',
            'resource_name' => $rn,
            'type' => $type,
            'plan' => $plan,
            'knative_region_id' => $region->id,
            'status' => Binding::STATUS_READY,
            'settings' => BindingManager::normalizeSettings($type, $plan, []),
            'credentials' => $type === 'kv'
                ? ['mode' => 'acl', 'username' => $rn, 'password' => 'kv-pass']
                : ['cluster' => "pg-{$rn}", 'host' => "pg-{$rn}-rw.runr-data.svc.cluster.local", 'port' => 5432, 'database' => $pg, 'username' => $pg, 'password' => 'pg-pass'],
        ]);
        $binding->id = $id;
        $binding->save();

        return $binding->fresh(['region']);
    }

    private function attach(Binding $binding, Project $project): void
    {
        BindingAttachment::create(['binding_id' => $binding->id, 'project_id' => $project->id, 'env_prefix' => '']);
    }

    /** @return list<array{host: string, method: string, path: string, body: array}> */
    private function writes(string $host, string $suffix): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['host'] === $host && $c['method'] !== 'GET' && str_ends_with($c['path'], $suffix)));
    }

    private function lastWrite(string $host, string $suffix): ?array
    {
        $writes = $this->writes($host, $suffix);

        return $writes === [] ? null : end($writes)['body'];
    }

    /** The home's Secrets and its live cluster (initdb) the replica copies. */
    private function homeSecrets(Binding $binding, string $host = 'eu-1.test'): void
    {
        $cluster = $binding->dedicatedClusterName();
        $base = "{$host} GET /api/v1/namespaces/runr-data/secrets/";
        $this->responses[$base."{$cluster}-superuser"] = ['data' => ['username' => base64_encode('postgres'), 'password' => base64_encode('su')]];
        $this->responses[$base."{$cluster}-owner"] = ['data' => ['username' => base64_encode($binding->pgName()), 'password' => base64_encode('pg-pass')]];
        $this->responses[$base."{$cluster}-replication"] = ['data' => ['tls.crt' => base64_encode('crt'), 'tls.key' => base64_encode('key')]];
        $this->responses[$base."{$cluster}-ca"] = ['data' => ['ca.crt' => base64_encode('ca'), 'ca.key' => base64_encode('ca-key')]];
    }

    private function clusterReady(Binding $binding, string $host, array $extra = []): void
    {
        $this->responses["{$host} GET /apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/".$binding->dedicatedClusterName()] = array_replace_recursive([
            'spec' => ['instances' => 1, 'bootstrap' => ['initdb' => ['database' => $binding->pgName()]]],
            'status' => ['readyInstances' => 1, 'phase' => 'Cluster in healthy state'],
        ], $extra);
    }

    public function test_a_kv_binding_is_reached_from_the_other_regions_through_its_data_link(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('kv');
        $this->attach($binding, $project);
        $rn = $binding->resource_name;
        $this->responses["us-1.test GET /apis/apps/v1/namespaces/runr-data/deployments/runr-link-{$rn}"] = ['status' => ['availableReplicas' => 2]];

        app(RegionalData::class)->syncProject($project);

        // the home answers the link's SNI name for the binding's own CA only
        $route = $this->lastWrite('eu-1.test', "/ingressroutetcps/runr-link-{$rn}");
        $this->assertSame('HostSNI(`'.DataLinkManifests::sni($rn).'`)', $route['spec']['routes'][0]['match']);
        $this->assertSame('runr-kv', $route['spec']['routes'][0]['services'][0]['name']);
        $this->assertNotNull($this->lastWrite('eu-1.test', "/tlsoptions/runr-link-{$rn}"));
        // us-1 forwards to eu-1's Traefik
        $forwarder = $this->lastWrite('us-1.test', "/configmaps/runr-link-{$rn}");
        $this->assertStringContainsString('proxy_pass 203.0.113.10:443;', $forwarder['data']['nginx.conf']);
        $this->assertSame([], $this->writes('eu-1.test', "/deployments/runr-link-{$rn}"));

        $rows = BindingReplica::query()->where('binding_id', $binding->id)->get()->keyBy('knative_region_id');
        $this->assertSame('home', $rows[$this->eu->id]->kind);
        $this->assertSame('link', $rows[$this->us->id]->kind);
        $this->assertSame('ready', $rows[$this->us->id]->status);
        $this->assertSame('ready', ProjectRegion::query()->where('knative_region_id', $this->us->id)->value('data_status'));
        $this->assertSame(DataLinkManifests::sni($rn), $binding->fresh()->replication['sni']);

        // the copy in us-1 connects to the link with the same credentials; eu-1 as before
        $usView = (clone $project)->setRelation('knativeRegion', $this->us);
        $usView->setAttribute('knative_region_id', $this->us->id);
        $this->assertSame("redis://{$rn}:kv-pass@runr-link-{$rn}.runr-data.svc.cluster.local:6379", BindingEnv::projectEnv($usView)['REDIS_URL']);
        $this->assertSame("redis://{$rn}:kv-pass@runr-kv.runr-data.svc.cluster.local:6379", BindingEnv::projectEnv($project)['REDIS_URL']);
    }

    public function test_a_detached_binding_leaves_the_other_regions(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('kv');
        $this->attach($binding, $project);
        app(RegionalData::class)->syncProject($project);
        $rn = $binding->resource_name;

        BindingAttachment::query()->where('binding_id', $binding->id)->delete();
        $this->calls = [];
        app(RegionalData::class)->syncProject($project, [$binding->id]);

        $this->assertNotEmpty(array_filter($this->calls, fn ($c) => $c['host'] === 'us-1.test' && $c['method'] === 'DELETE' && str_ends_with($c['path'], "/deployments/runr-link-{$rn}")));
        $this->assertNotEmpty(array_filter($this->calls, fn ($c) => $c['host'] === 'eu-1.test' && $c['method'] === 'DELETE' && str_ends_with($c['path'], "/ingressroutetcps/runr-link-{$rn}")));
        $this->assertSame(0, BindingReplica::query()->where('binding_id', $binding->id)->count());
    }

    public function test_a_dedicated_postgres_binding_streams_into_a_replica_cluster_in_every_region(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('postgres', 'dedicated');
        $this->attach($binding, $project);
        $this->homeSecrets($binding);
        $this->clusterReady($binding, 'eu-1.test');
        $this->clusterReady($binding, 'us-1.test');
        $cluster = $binding->dedicatedClusterName();
        $eu = RegionalPostgresManifests::topologyName($this->eu->id);
        $us = RegionalPostgresManifests::topologyName($this->us->id);
        $this->responses["us-1.test GET /apis/apps/v1/namespaces/runr-data/deployments/runr-link-{$binding->resource_name}"] = ['status' => ['availableReplicas' => 1]];
        $this->replicaState['us-1'] = 't streaming 4';

        app(RegionalData::class)->syncProject($project);

        // the home keeps its bootstrap and names itself the primary of the topology
        $home = $this->lastWrite('eu-1.test', "/clusters/{$cluster}");
        $this->assertSame(['self' => $eu, 'primary' => $eu, 'source' => $us], $home['spec']['replica']);
        $this->assertArrayHasKey('initdb', $home['spec']['bootstrap']);
        // us-1: the home's Secrets, a replica cluster that streams through the link
        $this->assertNotNull($this->lastWrite('us-1.test', "/secrets/{$cluster}-src-replication"));
        $this->assertSame(base64_encode('su'), $this->lastWrite('us-1.test', "/secrets/{$cluster}-superuser")['data']['password']);
        $replica = $this->lastWrite('us-1.test', "/clusters/{$cluster}");
        $this->assertSame(['self' => $us, 'primary' => $eu, 'source' => $eu], $replica['spec']['replica']);
        $this->assertSame("runr-link-{$binding->resource_name}.runr-data.svc.cluster.local", $replica['spec']['externalClusters'][0]['connectionParameters']['host']);

        $row = BindingReplica::query()->where('binding_id', $binding->id)->where('knative_region_id', $this->us->id)->firstOrFail();
        $this->assertSame('replica', $row->kind);
        $this->assertSame('ready', $row->status);
        $this->assertSame(4, $row->lag_seconds);

        // reads stay in the region, writes go to the primary through the link
        $usView = (clone $project)->setRelation('knativeRegion', $this->us);
        $usView->setAttribute('knative_region_id', $this->us->id);
        $env = BindingEnv::projectEnv($usView);
        $this->assertStringContainsString("@runr-link-{$binding->resource_name}.runr-data.svc.cluster.local:5432/", $env['DATABASE_URL']);
        $this->assertStringContainsString("@{$cluster}-r.runr-data.svc.cluster.local:5432/", $env['DATABASE_URL_RO']);
        $this->assertSame("{$cluster}-r.runr-data.svc.cluster.local", $env['PGHOST_RO']);
        $this->assertStringContainsString("@{$cluster}-rw.runr-data.svc.cluster.local:5432/", BindingEnv::projectEnv($project)['DATABASE_URL']);
    }

    public function test_a_cluster_the_operator_refuses_fails_with_the_operators_reason(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('postgres', 'dedicated');
        $this->attach($binding, $project);
        $this->homeSecrets($binding);
        $this->clusterReady($binding, 'eu-1.test');
        $cluster = $binding->dedicatedClusterName();
        $this->refusals["eu-1.test PATCH /apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/{$cluster}"] = [422, [
            'kind' => 'Status', 'code' => 422, 'reason' => 'Invalid',
            'message' => 'admission webhook "vcluster.cnpg.io" denied the request: Cluster.postgresql.cnpg.io "'.$cluster.'" is invalid: spec.replicaCluster.primary: Invalid value: "r01": External cluster r01 not found',
        ]];

        app(RegionalData::class)->syncProject($project);

        $home = BindingReplica::query()->where('binding_id', $binding->id)->where('knative_region_id', $this->eu->id)->firstOrFail();
        $this->assertSame('failed', $home->status);
        $this->assertStringContainsString("Postgres cluster {$cluster} could not be created or changed: HTTP 422: admission webhook \"vcluster.cnpg.io\" denied the request", (string) $home->status_message);
        $this->assertStringContainsString('External cluster r01 not found', (string) $home->status_message);
    }

    public function test_a_switchover_promotes_the_replica_with_the_demotion_token(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('postgres', 'dedicated');
        $this->attach($binding, $project);
        $this->homeSecrets($binding);
        $this->homeSecrets($binding, 'us-1.test');
        $this->clusterReady($binding, 'eu-1.test', ['status' => ['demotionToken' => 'TOKEN']]);
        $this->clusterReady($binding, 'us-1.test');
        app(RegionalData::class)->syncProject($project);
        $cluster = $binding->dedicatedClusterName();
        $us = RegionalPostgresManifests::topologyName($this->us->id);
        $this->replicaState['us-1'] = 'f none -';

        app(RegionalData::class)->switchover($binding->fresh(['region']), $this->us);

        // eu-1 demoted first (it follows us-1), then us-1 promoted with eu-1's token
        $this->assertSame(['self' => RegionalPostgresManifests::topologyName($this->eu->id), 'primary' => $us, 'source' => $us], $this->writes('eu-1.test', "/clusters/{$cluster}")[1]['body']['spec']['replica'] ?? null);
        $promotions = array_filter($this->writes('us-1.test', "/clusters/{$cluster}"), fn ($w) => ($w['body']['spec']['replica']['promotionToken'] ?? null) === 'TOKEN');
        $this->assertCount(1, $promotions);
        $this->assertSame($us, array_values($promotions)[0]['body']['spec']['replica']['primary']);

        $this->assertSame($this->us->id, $binding->fresh()->knative_region_id);
        $rows = BindingReplica::query()->where('binding_id', $binding->id)->get()->keyBy('knative_region_id');
        $this->assertSame('home', $rows[$this->us->id]->kind);
        $this->assertSame('replica', $rows[$this->eu->id]->kind);
        // eu-1 now forwards to us-1
        $this->assertStringContainsString('proxy_pass 198.51.100.10:443;', $this->lastWrite('eu-1.test', "/configmaps/runr-link-{$binding->resource_name}")['data']['nginx.conf']);
    }

    public function test_a_failover_promotes_at_once_and_the_old_home_is_rebuilt_when_it_answers(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('postgres', 'dedicated');
        $this->attach($binding, $project);
        $this->homeSecrets($binding);
        $this->homeSecrets($binding, 'us-1.test');
        $this->clusterReady($binding, 'eu-1.test');
        $this->clusterReady($binding, 'us-1.test');
        app(RegionalData::class)->syncProject($project);
        $cluster = $binding->dedicatedClusterName();
        $us = RegionalPostgresManifests::topologyName($this->us->id);
        $this->replicaState['us-1'] = 'f none -';

        app(RegionalData::class)->failover($binding->fresh(['region']), $this->us);

        $last = $this->lastWrite('us-1.test', "/clusters/{$cluster}");
        $this->assertSame($us, $last['spec']['replica']['primary']);
        $this->assertArrayNotHasKey('promotionToken', $last['spec']['replica']);
        $row = BindingReplica::query()->where('binding_id', $binding->id)->where('knative_region_id', $this->eu->id)->firstOrFail();
        $this->assertSame('recloning', $row->status);

        // eu-1 answers again: its old cluster (and volumes) go, a replica of us-1 is cloned
        $this->clusterReady($binding, 'eu-1.test', ['spec' => ['replica' => ['self' => 'x', 'primary' => 'x']]]);
        $this->calls = [];
        $this->assertTrue(app(RegionalData::class)->rejoin($binding->fresh(['region']), $this->eu));

        $this->assertNotEmpty(array_filter($this->calls, fn ($c) => $c['host'] === 'eu-1.test' && $c['method'] === 'DELETE' && str_ends_with($c['path'], "/clusters/{$cluster}")));
        $rebuilt = $this->lastWrite('eu-1.test', "/clusters/{$cluster}");
        $this->assertSame(['pg_basebackup' => ['source' => $us]], $rebuilt['spec']['bootstrap']);
        $this->assertSame($us, $rebuilt['spec']['replica']['primary']);
        $this->assertSame('provisioning', $row->fresh()->status);
    }

    public function test_the_replica_clusters_are_metered_with_their_binding(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('postgres', 'dedicated');
        $this->attach($binding, $project);
        $this->homeSecrets($binding);
        $this->clusterReady($binding, 'eu-1.test');
        $this->clusterReady($binding, 'us-1.test');
        app(RegionalData::class)->syncProject($project);
        $binding->forceFill(['usage_measured_at' => now()->subHour()])->save();

        app(BindingUsageMeter::class)->measure();

        // the home's instance and the replica's: two instance hours
        $record = BindingUsageRecord::query()->where('binding_id', $binding->id)->firstOrFail();
        $this->assertEqualsWithDelta(2.0, (float) $record->instance_hours, 0.01);
        $this->assertSame($this->eu->id, $record->region_id);
    }

    public function test_a_binding_of_another_global_project_or_dynamic_credentials_are_refused(): void
    {
        $project = $this->globalProject();
        $binding = $this->binding('postgres', 'dedicated');
        $data = app(RegionalData::class);

        $this->assertNull($data->attachRefusal($binding, $project));
        $this->assertStringContainsString('Dynamic credentials', (string) $data->attachRefusal($binding, $project, true));

        $this->attach($binding, $project);
        $other = $this->globalProject();
        $this->assertStringContainsString('replicated for a global project already', (string) $data->attachRefusal($binding, $other));
    }
}
