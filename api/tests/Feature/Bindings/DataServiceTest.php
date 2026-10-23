<?php

namespace Tests\Feature\Bindings;

use App\Events\BindingAudited;
use App\Jobs\Bindings\DataServiceJob;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\Bindings\DataServiceManager;
use App\Services\Bindings\DataServices\KvService;
use App\Services\Bindings\Ops\OpsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * /api/knative-regions/{region}/data-services: status, install / configure (KV Redis, Garage or
 * an external S3 endpoint, CloudNativePG + shared cluster), uninstall guarded by bindings in
 * use, credentials kept out of the queue, permissions.
 */
class DataServiceTest extends TestCase
{
    use ActsWithRoles, FakesBindingOps, RefreshDatabase;

    private const NS = '/api/v1/namespaces/runr-data';

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();

        $this->owner = $this->userWithRole('owner');
        $this->region = $this->bindingRegion();
    }

    private function install(string $service, array $options = [], ?User $as = null)
    {
        return $this->signedIn($as ?? $this->owner)->postJson("/api/knative-regions/{$this->region->id}/data-services/{$service}", $options);
    }

    private function row(string $service): ?BindingRegionService
    {
        return BindingRegionService::forRegion($this->region, $service);
    }

    // ── Status ──────────────────────────────────────────────────────────────────────────

    public function test_status_lists_every_service(): void
    {
        $response = $this->signedIn($this->owner)->getJson("/api/knative-regions/{$this->region->id}/data-services")->assertOk();

        $response->assertJsonPath('data.namespace', 'runr-data')
            ->assertJsonPath('data.services.kv.status', 'not_installed')
            ->assertJsonPath('data.services.kv.installed', false)
            ->assertJsonPath('data.services.object.status', 'not_installed')
            ->assertJsonPath('data.services.postgres.config', null)
            ->assertJsonPath('data.region_redis.has_password', false)
            ->assertJsonPath('abilities.manage', true);

        $this->readyService($this->region, 'kv');
        $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->signedIn($this->owner)->getJson("/api/knative-regions/{$this->region->id}/data-services?live=1")
            ->assertOk()
            ->assertJsonPath('data.services.kv.status', 'ready')
            ->assertJsonPath('data.services.kv.mode', 'managed')
            ->assertJsonPath('data.services.kv.config.host', 'runr-kv.runr-data.svc.cluster.local')
            ->assertJsonPath('data.services.kv.config.acl', true)
            ->assertJsonPath('data.services.kv.bindings_count', 1)
            ->assertJsonPath('data.services.kv.health.state', 'ready');

        $this->assertStringNotContainsString('kv-admin-pw', $this->signedIn($this->owner)->getJson("/api/knative-regions/{$this->region->id}/data-services")->getContent());

        $this->signedIn($this->userWithRole('viewer'))->getJson("/api/knative-regions/{$this->region->id}/data-services")
            ->assertOk()
            ->assertJsonPath('abilities.manage', false);

        $bare = KnativeRegion::create(['name' => 'bare', 'is_active' => true]);
        $this->signedIn($this->owner)->getJson("/api/knative-regions/{$bare->id}/data-services")->assertOk()->assertJsonPath('data', null);
    }

    // ── KV ──────────────────────────────────────────────────────────────────────────────

    public function test_managed_kv_redis_is_installed(): void
    {
        Event::fake([BindingAudited::class]);

        $this->install('kv', ['mode' => 'managed', 'maxmemory_mb' => 256, 'storage' => '1Gi'])
            ->assertStatus(202)
            ->assertJsonPath('data.service', 'kv');

        $row = $this->row('kv');
        $this->assertSame(BindingRegionService::STATUS_READY, $row->status, (string) $row->status_message);
        $this->assertSame('managed', $row->mode);
        $this->assertSame(['maxmemory_mb' => 256, 'storage' => '1Gi', 'acl' => true, 'redis_version' => '7.4.1'], $row->config);
        $password = $row->secret('password');
        $this->assertSame(40, strlen($password));

        $this->assertNotNull($this->applied(self::NS), 'data namespace');
        $this->assertNotNull($this->applied('/apis/networking.k8s.io/v1/namespaces/runr-data/networkpolicies/runr-data-default-deny'));
        $this->assertSame($password, base64_decode($this->assertApplied(self::NS.'/secrets/runr-kv-auth')['data']['password']));
        $this->assertSame('1Gi', $this->assertApplied(self::NS.'/persistentvolumeclaims/runr-kv-data')['spec']['resources']['requests']['storage']);
        $args = $this->assertApplied('/apis/apps/v1/namespaces/runr-data/deployments/runr-kv')['spec']['template']['spec']['containers'][0]['args'];
        $this->assertSame('256mb', $args[array_search('--maxmemory', $args, true) + 1]);
        $this->assertNotNull($this->applied(self::NS.'/services/runr-kv'));

        $probe = $this->opsTask('kv-probe');
        $this->assertSame($password, $probe->files['auth']);
        $this->assertStringContainsString("-h 'runr-kv.runr-data.svc.cluster.local'", $probe->script);

        Event::assertDispatched(BindingAudited::class, fn (BindingAudited $e) => $e->action === 'data_service.installed' && $e->subject['service'] === 'kv');
    }

    public function test_the_kv_redis_version_is_recorded_and_read_again_on_request(): void
    {
        $this->readyService($this->region, 'kv', 'managed', ['acl' => true], ['password' => 'pw']);
        $kv = app(KvService::class);

        // None recorded (installed by an earlier release): probed once, then kept
        $this->redisVersion = '7.2.4';
        $this->assertSame('7.2.4', $kv->serverVersion($this->region));
        $this->assertSame('7.2.4', $this->row('kv')->configValue('redis_version'));
        $probes = count(array_filter($this->opsTasks, fn ($t) => $t->purpose === 'kv-probe'));
        $this->redisVersion = '7.4.1';
        $this->assertSame('7.2.4', $kv->serverVersion($this->region), 'the recorded version, no probe');
        $this->assertSame($probes, count(array_filter($this->opsTasks, fn ($t) => $t->purpose === 'kv-probe')));

        $this->assertSame('7.4.1', $kv->serverVersion($this->region, true));
        $this->assertSame('7.4.1', $this->row('kv')->configValue('redis_version'));

        // A probe that fails keeps what was recorded; no service: unknown
        $this->opsResponses['kv-probe'] = new OpsResult(false, 'connection refused');
        $this->assertSame('7.4.1', $kv->serverVersion($this->region, true));
        $this->row('kv')->delete();
        $this->assertNull($kv->serverVersion($this->region));
    }

    public function test_a_reinstall_keeps_the_admin_password(): void
    {
        $this->readyService($this->region, 'kv', 'managed', [], ['password' => 'keep-me']);

        $this->install('kv', ['maxmemory_mb' => 1024])->assertStatus(202);

        $this->assertSame('keep-me', $this->row('kv')->secret('password'));
        $this->assertSame(1024, $this->row('kv')->configValue('maxmemory_mb'));
    }

    public function test_kv_on_the_region_redis_needs_its_password(): void
    {
        $this->region->update(['redis_mode' => 'managed', 'knative_redis_status' => 'ready', 'redis_address' => 'redis://runr-redis.fn:6379']);

        $this->install('kv', ['mode' => 'region'])->assertStatus(202);

        $row = $this->row('kv');
        $this->assertSame(BindingRegionService::STATUS_ERROR, $row->status);
        $this->assertSame(__('api.bindings.kv_region_password_required'), $row->status_message);

        $this->region->update(['redis_password' => 'region-pw']);
        $this->aclSupported = false;

        $this->install('kv', ['mode' => 'region'])->assertStatus(202);

        $row = $this->row('kv');
        $this->assertSame(BindingRegionService::STATUS_READY, $row->status);
        $this->assertSame('region', $row->mode);
        $this->assertFalse($row->configValue('acl'), 'logical databases without ACL support');
        $this->assertSame('region-pw', $this->opsTask('kv-probe')->files['auth']);
        $this->assertNull($this->applied('/apis/apps/v1/namespaces/runr-data/deployments/runr-kv'), 'nothing installed');
    }

    // ── Object storage ──────────────────────────────────────────────────────────────────

    public function test_garage_is_installed_and_its_layout_applied(): void
    {
        $this->install('object', ['mode' => 'managed', 'storage' => '10Gi'])->assertStatus(202);

        $row = $this->row('object');
        $this->assertSame(BindingRegionService::STATUS_READY, $row->status, (string) $row->status_message);
        $this->assertSame(['provider' => 'garage', 'storage' => '10Gi', 's3_region' => 'garage'], $row->config);
        $token = $row->secret('admin_token');
        $this->assertSame(48, strlen($token));
        $this->assertSame(64, strlen($row->secret('rpc_secret')));

        $toml = base64_decode($this->assertApplied(self::NS.'/secrets/runr-objects-config')['data']['garage.toml']);
        $this->assertStringContainsString("admin_token = \"{$token}\"", $toml);
        $sts = $this->assertApplied('/apis/apps/v1/namespaces/runr-data/statefulsets/runr-objects');
        $this->assertSame('10Gi', $sts['spec']['volumeClaimTemplates'][1]['spec']['resources']['requests']['storage']);
        $this->assertNotNull($this->applied(self::NS.'/services/runr-objects'));

        $this->assertSame(
            ['/v2/GetClusterStatus', '/v2/GetClusterLayout', '/v2/UpdateClusterLayout', '/v2/ApplyClusterLayout'],
            array_column($this->garageCalls(), 'path'),
        );
        $this->assertSame([['id' => 'node-1', 'zone' => 'dc1', 'capacity' => 10 * 1024 ** 3, 'tags' => ['runr']]], $this->garageCallTo('/v2/UpdateClusterLayout')['body']['roles']);
        $this->assertSame(['version' => 1], $this->garageCallTo('/v2/ApplyClusterLayout')['body']);
        $this->assertSame($token, $this->opsTask('object-layout')->files['token']);
    }

    public function test_a_node_with_a_role_keeps_its_layout(): void
    {
        $this->garageLayout = ['version' => 3, 'roles' => [['id' => 'node-1', 'zone' => 'dc1', 'capacity' => 1]]];

        $this->install('object')->assertStatus(202);

        $this->assertSame(BindingRegionService::STATUS_READY, $this->row('object')->status);
        $this->assertNull($this->garageCallTo('/v2/UpdateClusterLayout'));
    }

    public function test_external_s3_credentials_stay_out_of_the_queue(): void
    {
        Queue::fake([DataServiceJob::class]);

        $this->install('object', [
            'mode' => 'external', 'provider' => 's3', 'endpoint' => 'https://s3.example.com', 'region' => 'eu-west-1',
            'vendor' => 'AWS', 'bucket_prefix' => 'acme-', 'access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => 'sk-example-secret',
        ])->assertStatus(202);

        $pushed = null;
        Queue::assertPushed(DataServiceJob::class, function (DataServiceJob $job) use (&$pushed) {
            $pushed = $job;

            return $job->operation === 'install' && $job->service === 'object';
        });
        $this->assertArrayNotHasKey('access_key_id', $pushed->options);
        $this->assertArrayNotHasKey('secret_access_key', $pushed->options);
        $this->assertSame('https://s3.example.com', $pushed->options['endpoint']);
        $this->assertStringNotContainsString('sk-example-secret', serialize($pushed));

        $row = $this->row('object');
        $this->assertSame(BindingRegionService::STATUS_INSTALLING, $row->status);
        $this->assertSame('sk-example-secret', $row->secret('secret_access_key'));
        $raw = DB::table('binding_region_services')->where('id', $row->id)->value('secrets');
        $this->assertStringNotContainsString('sk-example-secret', (string) $raw, 'encrypted at rest');

        $pushed->handle(app(DataServiceManager::class));

        $row = $this->row('object');
        $this->assertSame(BindingRegionService::STATUS_READY, $row->status, (string) $row->status_message);
        $this->assertSame('external', $row->mode);
        $this->assertSame('s3', $row->configValue('provider'));
        $this->assertSame('eu-west-1', $row->configValue('s3_region'));
        $this->assertSame('AKIAEXAMPLE', $row->secret('access_key_id'));

        $probe = $this->opsTask('object-probe');
        $this->assertMatchesRegularExpression("/rclone mkdir 't:acme-runr-probe-[a-z0-9]{6}'/", $probe->script);
        $this->assertStringContainsString('rclone rmdir', $probe->script);
        $this->assertStringContainsString("RCLONE_CONFIG_T_SECRET_ACCESS_KEY='sk-example-secret'", $probe->files['rclone.env']);
    }

    public function test_external_store_validation(): void
    {
        $this->install('object', ['mode' => 'external', 'provider' => 's3'])->assertUnprocessable()->assertJsonValidationErrors(['endpoint', 'access_key_id', 'secret_access_key']);
        $this->install('object', ['mode' => 'external', 'provider' => 'garage', 'endpoint' => 'https://s3.example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors(['admin_endpoint', 'admin_token']);
        $this->install('object', ['mode' => 'external', 'provider' => 'minio', 'endpoint' => 'https://s3.example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('provider');
        $this->install('object', ['storage' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors('storage');
        $this->install('kv', ['mode' => 'cluster'])->assertUnprocessable()->assertJsonValidationErrors('mode');

        $this->assertNull($this->row('object'));
    }

    public function test_an_external_garage_is_probed_through_its_admin_api(): void
    {
        $this->install('object', [
            'mode' => 'external', 'provider' => 'garage', 'endpoint' => 'https://s3.example.com',
            'admin_endpoint' => 'https://garage-admin.example.com', 'admin_token' => 'ext-token',
        ])->assertStatus(202);

        $row = $this->row('object');
        $this->assertSame(BindingRegionService::STATUS_READY, $row->status, (string) $row->status_message);
        $this->assertSame('https://garage-admin.example.com', $row->configValue('admin_endpoint'));
        $this->assertSame('garage', $row->configValue('s3_region'));

        $probe = $this->opsTask('object-probe');
        $this->assertSame([['name' => 'GARAGE_ADMIN_URL', 'value' => 'https://garage-admin.example.com']], $probe->env);
        $this->assertSame('ext-token', $probe->files['token']);
        $this->assertSame(['/v2/GetClusterStatus'], array_column($this->garageCalls(), 'path'));
    }

    // ── Postgres ────────────────────────────────────────────────────────────────────────

    public function test_cloudnative_pg_and_the_shared_cluster_are_installed(): void
    {
        $this->install('postgres', ['instances' => 1, 'storage' => '5Gi'])->assertStatus(202);

        $row = $this->row('postgres');
        $this->assertSame(BindingRegionService::STATUS_READY, $row->status, (string) $row->status_message);
        $this->assertSame(['instances' => 1, 'storage' => '5Gi', 'backups' => false, 'chart_version' => '0.26.0'], $row->config);

        $this->assertContains(['helm', 'repo', 'add', 'cnpg', 'https://cloudnative-pg.github.io/charts', '--force-update'], $this->helmCommands);
        $install = collect($this->helmCommands)->first(fn ($argv) => ($argv[1] ?? null) === 'upgrade');
        $this->assertSame(['helm', 'upgrade', '--install', 'cnpg', 'cnpg/cloudnative-pg', '-n', 'cnpg-system'], array_slice($install, 0, 7));
        $this->assertSame('0.26.0', $install[array_search('--version', $install, true) + 1]);

        $cluster = $this->assertApplied('/apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/runr-pg-shared');
        $this->assertSame(1, $cluster['spec']['instances']);
        $this->assertSame('5Gi', $cluster['spec']['storage']['size']);
        $this->assertArrayNotHasKey('backup', $cluster['spec']);
        $this->assertNotNull($this->applied('/apis/networking.k8s.io/v1/namespaces/runr-data/networkpolicies/runr-data-cnpg-operator'));

        $init = $this->opsTask('postgres-init');
        $this->assertStringContainsString('REVOKE CONNECT ON DATABASE postgres FROM PUBLIC;', $init->files['script.sql']);
        $this->assertSame(['name' => 'runr-pg-shared-superuser', 'key' => 'password'], collect($init->env)->firstWhere('name', 'PGPASSWORD')['valueFrom']['secretKeyRef']);
    }

    public function test_a_failed_operator_install_is_reported(): void
    {
        $this->helmFails = true;

        $this->install('postgres')->assertStatus(202);

        $row = $this->row('postgres');
        $this->assertSame(BindingRegionService::STATUS_ERROR, $row->status);
        $this->assertStringStartsWith('CloudNativePG could not be installed', $row->status_message);
        $this->assertStringContainsString('chart not found', $row->status_message);
    }

    public function test_backups_need_the_object_store_and_then_go_to_it(): void
    {
        $this->install('postgres', ['backups' => true])->assertStatus(202);
        $this->assertSame(__('api.bindings.backups_need_object_store'), $this->row('postgres')->status_message);

        $this->readyService($this->region, 'object');
        $this->install('postgres', ['backups' => true])->assertStatus(202);

        $row = $this->row('postgres');
        $this->assertSame(BindingRegionService::STATUS_READY, $row->status, (string) $row->status_message);
        $this->assertSame('runr-pg-backups', $row->secret('backup')['bucket']);
        $this->assertSame('GK1', $row->secret('backup')['access_key_id']);

        $allow = $this->garageCallTo('/v2/AllowBucketKey');
        $this->assertSame(['read' => true, 'write' => true, 'owner' => true], $allow['body']['permissions']);
        $this->assertSame('GK1', base64_decode($this->assertApplied(self::NS.'/secrets/runr-pg-backup-s3')['data']['ACCESS_KEY_ID']));

        $cluster = $this->assertApplied('/apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/runr-pg-shared');
        $this->assertSame('s3://runr-pg-backups/runr-pg-shared', $cluster['spec']['backup']['barmanObjectStore']['destinationPath']);
        $this->assertSame('0 0 3 * * *', $this->assertApplied('/apis/postgresql.cnpg.io/v1/namespaces/runr-data/scheduledbackups/runr-pg-shared-daily')['spec']['schedule']);
    }

    // ── Uninstall ───────────────────────────────────────────────────────────────────────

    public function test_uninstall_is_refused_while_bindings_use_the_service(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->signedIn($this->owner)->deleteJson("/api/knative-regions/{$this->region->id}/data-services/kv")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.bindings.service_in_use', ['service' => 'kv', 'count' => 1]));

        $binding->delete();

        $this->signedIn($this->owner)->deleteJson("/api/knative-regions/{$this->region->id}/data-services/kv")->assertStatus(202);

        $this->assertNull($this->row('kv'));
        $this->assertNotNull($this->k8sSent('DELETE', '/apis/apps/v1/namespaces/runr-data/deployments/runr-kv'));
        $this->assertNotNull($this->k8sSent('DELETE', self::NS.'/persistentvolumeclaims/runr-kv-data'));
        $this->assertNotNull($this->k8sSent('DELETE', self::NS.'/secrets/runr-kv-auth'));

        $this->signedIn($this->owner)->deleteJson("/api/knative-regions/{$this->region->id}/data-services/kv")->assertUnprocessable();
    }

    public function test_the_object_store_stays_while_postgres_backs_up_to_it(): void
    {
        $this->readyService($this->region, 'object');
        $this->readyService($this->region, 'postgres', 'managed', ['backups' => true]);

        $this->signedIn($this->owner)->deleteJson("/api/knative-regions/{$this->region->id}/data-services/object")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.bindings.object_used_by_backups'));
    }

    public function test_postgres_uninstall_removes_the_cluster_and_the_operator(): void
    {
        $this->readyService($this->region, 'postgres');

        $this->signedIn($this->owner)->deleteJson("/api/knative-regions/{$this->region->id}/data-services/postgres")->assertStatus(202);

        $this->assertNull($this->row('postgres'));
        $this->assertNotNull($this->k8sSent('DELETE', '/apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/runr-pg-shared'));
        $this->assertContains('uninstall', array_column($this->helmCommands, 1));
    }

    public function test_a_busy_service_takes_no_second_request(): void
    {
        $this->readyService($this->region, 'kv')->update(['status' => BindingRegionService::STATUS_INSTALLING]);

        $this->install('kv')->assertUnprocessable()->assertJsonPath('message', __('api.bindings.service_busy', ['service' => 'kv']));
    }

    // ── Permissions / routing ───────────────────────────────────────────────────────────

    public function test_changes_need_regions_data_services_manage(): void
    {
        foreach (['developer', 'viewer'] as $role) {
            $user = $this->userWithRole($role);
            $this->install('kv', [], $user)->assertForbidden();
            $this->signedIn($user)->deleteJson("/api/knative-regions/{$this->region->id}/data-services/kv")->assertForbidden();
        }
        $this->assertNull($this->row('kv'));

        foreach (['POST', 'DELETE'] as $method) {
            $route = Route::getRoutes()->match(Request::create("/api/knative-regions/{$this->region->id}/data-services/kv", $method));
            $this->assertContains('region.mutable', $route->gatherMiddleware(), "{$method} carries region.mutable");
        }
    }

    public function test_unknown_services_and_demo_regions(): void
    {
        $this->install('mysql')->assertNotFound();

        $demo = $this->bindingRegion('demo', ['is_demo' => true]);
        $this->signedIn($this->owner)->postJson("/api/knative-regions/{$demo->id}/data-services/kv")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('region');
    }
}
