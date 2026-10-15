<?php

namespace Tests\Feature\Bindings;

use App\Jobs\Bindings\BindingOperationJob;
use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingDrivers;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\Ops\OpsResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * Provisioning of each binding type through the job body, with in-cluster operations and the
 * Kubernetes API faked: what runs in the region, the credentials kept and the env functions get.
 */
class BindingProvisioningTest extends TestCase
{
    use FakesBindingOps, RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();
        $this->region = $this->bindingRegion();
    }

    private function pending(string $type, ?KnativeRegion $region, array $attributes = []): Binding
    {
        $binding = $this->makeBinding($type, $region, $attributes);
        $binding->forceFill([
            'status' => Binding::STATUS_PROVISIONING,
            'credentials' => $type === 'external' ? $binding->credentials : null,
        ])->save();

        return $binding->fresh(['region']);
    }

    private function provision(Binding $binding): Binding
    {
        (new BindingOperationJob('provision', $binding->id))->handle(app(BindingManager::class));

        return $binding->fresh(['region']);
    }

    private function env(Binding $binding): array
    {
        return app(BindingDrivers::class)->for($binding)->env($binding);
    }

    // ── KV ──────────────────────────────────────────────────────────────────────────────

    public function test_kv_binding_gets_an_acl_user_on_the_managed_kv_redis(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->provision($this->pending('kv', $this->region, ['name' => 'cache']));
        $rn = $binding->resource_name;

        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame('acl', $binding->credential('mode'));
        $this->assertSame($rn, $binding->credential('username'));
        $password = $binding->credential('password');
        $this->assertSame(32, strlen($password));

        $task = $this->opsTask('kv-provision');
        $commands = explode("\n", trim($task->files['commands.txt']));
        $this->assertStringStartsWith("ACL SETUSER {$rn} reset on >{$password} ~{$rn}:* &{$rn}:* +@all -@admin -@dangerous", $commands[0]);
        $this->assertSame('ACL SAVE', $commands[1], 'the managed KV Redis persists its ACL file');
        $this->assertSame('kv-admin-pw', $task->files['auth']);
        $this->assertSame(config('bindings.kv.image'), $task->image);

        $this->assertSame([
            'REDIS_URL' => "redis://{$rn}:{$password}@runr-kv.runr-data.svc.cluster.local:6379",
            'KV_PREFIX' => "{$rn}:",
        ], $this->env($binding));
    }

    public function test_kv_on_a_region_redis_without_acls_gets_a_logical_database(): void
    {
        $region = $this->bindingRegion('lab', [
            'redis_mode' => 'managed', 'knative_redis_status' => 'ready',
            'redis_address' => 'redis://runr-redis.lab:6379', 'redis_password' => 'region-pw',
        ]);
        $row = $this->readyService($region, 'kv', 'region', ['acl' => false, 'db_allocations' => []], []);

        $first = $this->provision($this->pending('kv', $region, ['name' => 'one']));
        $second = $this->provision($this->pending('kv', $region, ['name' => 'two']));

        $this->assertSame(['mode' => 'db', 'db' => 1], $first->credentials);
        $this->assertSame(['mode' => 'db', 'db' => 2], $second->credentials);
        $this->assertNull($this->opsTask('kv-provision'), 'no ACL user without ACL support');
        $this->assertSame([$first->id => 1, $second->id => 2], $row->fresh()->configValue('db_allocations'));
        $this->assertSame(['REDIS_URL' => 'redis://:region-pw@runr-redis.lab:6379/1', 'KV_PREFIX' => ''], $this->env($first));

        // Purge empties its database and frees it
        $this->assertTrue(app(BindingManager::class)->purge($first));
        $purge = $this->opsTask('kv-purge');
        $this->assertStringContainsString('-n 1', $purge->script);
        $this->assertStringContainsString("--eval /ops/purge.lua , '*'", $purge->script);
        $this->assertSame('region-pw', $purge->files['auth']);
        $this->assertSame([$second->id => 2], $row->fresh()->configValue('db_allocations'));
    }

    public function test_kv_without_a_free_logical_database_fails(): void
    {
        $region = $this->bindingRegion('lab', [
            'redis_mode' => 'managed', 'knative_redis_status' => 'ready',
            'redis_address' => 'redis://runr-redis.lab:6379', 'redis_password' => 'region-pw',
        ]);
        $full = [];
        foreach (range(1, 15) as $db) {
            $full["taken-{$db}"] = $db;
        }
        $this->readyService($region, 'kv', 'region', ['acl' => false, 'db_allocations' => $full], []);

        $binding = $this->provision($this->pending('kv', $region, ['name' => 'one']));

        $this->assertSame(Binding::STATUS_ERROR, $binding->status);
        $this->assertSame(__('api.bindings.kv_no_free_database'), $binding->status_message);
    }

    // ── Object storage ──────────────────────────────────────────────────────────────────

    public function test_object_binding_gets_a_bucket_and_a_scoped_key_on_garage(): void
    {
        $this->readyService($this->region, 'object');
        $binding = $this->provision($this->pending('object', $this->region, ['name' => 'assets', 'settings' => ['quota_gb' => 7]]));
        $rn = $binding->resource_name;

        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame([
            'bucket' => $rn,
            'bucket_id' => "bucket-{$rn}",
            'access_key_id' => 'GK1',
            'secret_access_key' => 'secret-1',
            'scoped' => true,
        ], $binding->credentials);

        $calls = $this->garageCalls();
        $this->assertSame(['/v2/CreateBucket', '/v2/CreateKey', '/v2/AllowBucketKey', '/v2/UpdateBucket'], array_column($calls, 'path'));
        $this->assertSame(['globalAlias' => $rn], $calls[0]['body']);
        $this->assertSame(['name' => $rn], $calls[1]['body']);
        $this->assertSame([
            'bucketId' => '{{bucket.id}}', 'accessKeyId' => '{{key.accessKeyId}}',
            'permissions' => ['read' => true, 'write' => true, 'owner' => false],
        ], $calls[2]['body']);
        $this->assertSame(['id' => '{{bucket.id}}'], $calls[3]['query']);
        $this->assertSame(7 * 1024 ** 3, $calls[3]['body']['quotas']['maxSize']);

        $task = $this->opsTask('object-provision');
        $this->assertSame(config('bindings.ops.toolbox_image'), $task->image);
        $this->assertSame('garage-admin-token', $task->files['token']);
        $this->assertSame([['name' => 'GARAGE_ADMIN_URL', 'value' => 'http://runr-objects:3903']], $task->env);
        $this->assertStringNotContainsString('garage-admin-token', $task->script);

        $this->assertSame([
            'S3_ENDPOINT' => 'http://runr-objects.runr-data.svc.cluster.local:3900',
            'S3_BUCKET' => $rn,
            'S3_ACCESS_KEY_ID' => 'GK1',
            'S3_SECRET_ACCESS_KEY' => 'secret-1',
            'S3_REGION' => 'garage',
            'S3_FORCE_PATH_STYLE' => 'true',
        ], $this->env($binding));
    }

    public function test_a_failed_admin_call_fails_the_binding(): void
    {
        $this->readyService($this->region, 'object');
        $this->garageResponses['/v2/CreateBucket'] = [409, ['message' => 'bucket already exists']];

        $binding = $this->provision($this->pending('object', $this->region, ['name' => 'assets']));

        $this->assertSame(Binding::STATUS_ERROR, $binding->status);
        $this->assertStringContainsString('/v2/CreateBucket', $binding->status_message);
        $this->assertStringContainsString('409', $binding->status_message);
    }

    public function test_object_binding_on_an_external_s3_endpoint_gets_its_own_bucket(): void
    {
        $this->readyService($this->region, 'object', 'external', [
            'provider' => 's3', 'endpoint' => 'https://s3.example.com', 's3_region' => 'eu-west-1',
            'path_style' => false, 'vendor' => 'AWS', 'bucket_prefix' => 'acme-',
        ], ['access_key_id' => 'AKIAEXAMPLE', 'secret_access_key' => 'sk-example']);

        $binding = $this->provision($this->pending('object', $this->region, ['name' => 'media']));
        $bucket = 'acme-'.$binding->resource_name;

        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame($bucket, $binding->credential('bucket'));
        $this->assertFalse($binding->credential('scoped'));

        $task = $this->opsTask('object-provision');
        $this->assertSame(config('bindings.object.client_image'), $task->image);
        $this->assertStringContainsString("rclone mkdir 't:{$bucket}'", $task->script);
        $this->assertStringContainsString("RCLONE_CONFIG_T_PROVIDER='AWS'", $task->files['rclone.env']);
        $this->assertStringContainsString("RCLONE_CONFIG_T_ACCESS_KEY_ID='AKIAEXAMPLE'", $task->files['rclone.env']);
        $this->assertStringContainsString("RCLONE_CONFIG_T_FORCE_PATH_STYLE='false'", $task->files['rclone.env']);
        $this->assertStringNotContainsString('sk-example', $task->script);

        $env = $this->env($binding);
        $this->assertSame('https://s3.example.com', $env['S3_ENDPOINT']);
        $this->assertSame('eu-west-1', $env['S3_REGION']);
        $this->assertSame('false', $env['S3_FORCE_PATH_STYLE']);
        $this->assertSame('AKIAEXAMPLE', $env['S3_ACCESS_KEY_ID']);
    }

    // ── Postgres ────────────────────────────────────────────────────────────────────────

    public function test_shared_postgres_binding_gets_a_database_and_role(): void
    {
        $this->readyService($this->region, 'postgres');
        $binding = $this->provision($this->pending('postgres', $this->region, ['name' => 'orders']));
        $db = $binding->pgName();

        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame('runr-pg-shared', $binding->credential('cluster'));
        $this->assertSame('runr-pg-shared-rw.runr-data.svc.cluster.local', $binding->credential('host'));
        $this->assertSame($db, $binding->credential('database'));
        $this->assertSame($db, $binding->credential('username'));

        $task = $this->opsTask('postgres-provision');
        $env = collect($task->env)->keyBy('name');
        $this->assertSame(config('bindings.postgres.image'), $task->image);
        $this->assertSame('runr-pg-shared-rw', $env['PGHOST']['value']);
        $this->assertSame('require', $env['PGSSLMODE']['value']);
        $this->assertSame(['name' => 'runr-pg-shared-superuser', 'key' => 'username'], $env['PGUSER']['valueFrom']['secretKeyRef']);
        $this->assertSame(['name' => 'runr-pg-shared-superuser', 'key' => 'password'], $env['PGPASSWORD']['valueFrom']['secretKeyRef']);
        $this->assertStringContainsString('psql -v ON_ERROR_STOP=1', $task->script);
        $this->assertStringContainsString("CREATE ROLE \"{$db}\" LOGIN", $task->files['script.sql']);
        $this->assertStringContainsString("CREATE DATABASE \"{$db}\" OWNER \"{$db}\"", $task->files['script.sql']);
        $this->assertStringContainsString("PASSWORD '".$binding->credential('password')."'", $task->files['script.sql']);

        $password = $binding->credential('password');
        $this->assertSame([
            'DATABASE_URL' => "postgresql://{$db}:{$password}@runr-pg-shared-rw.runr-data.svc.cluster.local:5432/{$db}?sslmode=require",
            'PGHOST' => 'runr-pg-shared-rw.runr-data.svc.cluster.local',
            'PGPORT' => '5432',
            'PGDATABASE' => $db,
            'PGUSER' => $db,
            'PGPASSWORD' => $password,
            'PGSSLMODE' => 'require',
        ], $this->env($binding));
    }

    public function test_a_failed_sql_statement_fails_the_binding(): void
    {
        $this->readyService($this->region, 'postgres');
        $this->opsResponses['postgres-provision'] = new OpsResult(false, 'ERROR:  permission denied to create role', 'Failed');

        $binding = $this->provision($this->pending('postgres', $this->region, ['name' => 'orders']));

        $this->assertSame(Binding::STATUS_ERROR, $binding->status);
        $this->assertStringContainsString('permission denied to create role', $binding->status_message);
        $this->assertNull($binding->credentials);
    }

    public function test_dedicated_postgres_binding_gets_its_own_cluster(): void
    {
        $this->readyService($this->region, 'postgres');
        $this->readyInstances = 2;
        $binding = $this->provision($this->pending('postgres', $this->region, [
            'name' => 'ledger', 'plan' => 'dedicated', 'settings' => ['instances' => 2, 'storage_gb' => 5],
        ]));
        $cluster = 'pg-'.$binding->resource_name;

        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame($cluster, $binding->credential('cluster'));
        $this->assertSame("{$cluster}-rw.runr-data.svc.cluster.local", $binding->credential('host'));

        $this->assertNotNull($this->k8sSent('PATCH', '/api/v1/namespaces/runr-data'), 'data namespace ensured');
        $owner = $this->assertApplied("/api/v1/namespaces/runr-data/secrets/{$cluster}-owner");
        $this->assertSame('kubernetes.io/basic-auth', $owner['type']);
        $this->assertSame($binding->credential('password'), base64_decode($owner['data']['password']));

        $manifest = $this->assertApplied("/apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/{$cluster}");
        $this->assertSame(2, $manifest['spec']['instances']);
        $this->assertSame('5Gi', $manifest['spec']['storage']['size']);
        $this->assertSame($binding->pgName(), $manifest['spec']['bootstrap']['initdb']['database']);
        $this->assertNull($this->opsTask('postgres-provision'), 'a dedicated cluster bootstraps its database itself');
    }

    public function test_dedicated_cluster_that_does_not_become_ready_fails(): void
    {
        $this->readyService($this->region, 'postgres');
        $this->readyInstances = 0;

        $binding = $this->provision($this->pending('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated']));

        $this->assertSame(Binding::STATUS_ERROR, $binding->status);
        $this->assertStringContainsString('did not become ready', $binding->status_message);
    }

    // ── External ────────────────────────────────────────────────────────────────────────

    public function test_external_values_are_injected_as_they_are(): void
    {
        $binding = $this->provision($this->pending('external', null, ['name' => 'stripe']));

        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame(['API_URL' => 'https://api.example.com', 'API_TOKEN' => 'tok-1'], $this->env($binding));
        $this->assertSame([], $this->opsTasks);
    }

    // ── Errors / idempotence ────────────────────────────────────────────────────────────

    public function test_a_failed_operation_marks_the_binding_failed(): void
    {
        $this->readyService($this->region, 'kv');
        $this->opsResponses['kv-provision'] = new OpsResult(false, "ERR Error in ACL SETUSER modifier\n", 'Failed');

        $binding = $this->provision($this->pending('kv', $this->region, ['name' => 'cache']));

        $this->assertSame(Binding::STATUS_ERROR, $binding->status);
        $this->assertStringContainsString('kv-provision', $binding->status_message);
        $this->assertStringContainsString('ACL SETUSER modifier', $binding->status_message);
    }

    public function test_a_service_that_is_not_ready_fails_the_binding(): void
    {
        $row = $this->readyService($this->region, 'kv');
        $row->update(['status' => BindingRegionService::STATUS_ERROR]);

        $binding = $this->provision($this->pending('kv', $this->region, ['name' => 'cache']));

        $this->assertSame(Binding::STATUS_ERROR, $binding->status);
        $this->assertSame(__('api.bindings.service_not_ready', ['service' => 'kv', 'region' => 'fn']), $binding->status_message);
    }

    public function test_only_a_provisioning_binding_is_provisioned(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->provision($binding);

        $this->assertSame([], $this->opsTasks);
        $this->assertSame('kv-pass-1', $binding->fresh()->credential('password'));
    }
}
