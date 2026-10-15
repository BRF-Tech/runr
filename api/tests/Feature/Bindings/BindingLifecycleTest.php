<?php

namespace Tests\Feature\Bindings;

use App\Jobs\Bindings\BindingOperationJob;
use App\Jobs\Bindings\BindingsMaintenanceJob;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\BindingRegionService;
use App\Models\BindingUsageRecord;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingManager;
use App\Services\Bindings\Ops\OpsResult;
use App\Services\Bindings\Ops\OpsTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * Rotation (graceful where the backend allows), deletion with retention (access revoked, data
 * kept), restore, the purge job, purge for tenant deletion and the KV ACL reconcile.
 */
class BindingLifecycleTest extends TestCase
{
    use ActsWithRoles, FakesBindingOps, RefreshDatabase;

    private KnativeRegion $region;

    private BindingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();

        $this->region = $this->bindingRegion();
        $this->manager = app(BindingManager::class);
        foreach (['kv', 'object', 'postgres'] as $service) {
            $this->readyService($this->region, $service);
        }
    }

    private function runMaintenance(string $task): void
    {
        app()->call([new BindingsMaintenanceJob($task), 'handle']);
    }

    // ── Rotation ────────────────────────────────────────────────────────────────────────

    public function test_kv_rotation_keeps_the_old_password_until_the_rollout_finished(): void
    {
        Queue::fake([BindingOperationJob::class]);
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $rn = $binding->resource_name;

        $this->manager->rotate($binding);
        $binding->refresh();

        $new = $binding->credential('password');
        $this->assertNotSame('kv-pass-1', $new);
        $this->assertSame(32, strlen($new));
        $this->assertSame('kv-pass-1', $binding->previous_credentials['password']);
        $this->assertNotNull($binding->rotated_at);
        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame("ACL SETUSER {$rn} >{$new}\nACL SAVE\n", $this->opsTask('kv-rotate')->files['commands.txt']);

        Queue::assertPushed(BindingOperationJob::class, fn ($job) => $job->operation === 'finish_rotation' && $job->bindingId === $binding->id && $job->delay !== null);

        $this->manager->finishRotation($binding->fresh(['region']));

        $this->assertSame("ACL SETUSER {$rn} <kv-pass-1\nACL SAVE\n", $this->opsTask('kv-rotate-finish')->files['commands.txt']);
        $this->assertNull($binding->fresh()->previous_credentials);
    }

    public function test_object_rotation_creates_a_second_key_and_revokes_the_first_one_later(): void
    {
        Queue::fake([BindingOperationJob::class]);
        $binding = $this->makeBinding('object', $this->region, ['name' => 'assets']);
        $rn = $binding->resource_name;

        $this->manager->rotate($binding);
        $binding->refresh();

        $this->assertSame('GK1', $binding->credential('access_key_id'));
        $this->assertSame('secret-1', $binding->credential('secret_access_key'));
        $this->assertSame("bucket-{$rn}", $binding->credential('bucket_id'));
        $this->assertSame('GKold', $binding->previous_credentials['access_key_id']);

        $calls = $this->garageCalls();
        $this->assertSame(['/v2/CreateKey', '/v2/AllowBucketKey'], array_column($calls, 'path'));
        $this->assertStringStartsWith("{$rn}-", $calls[0]['body']['name']);
        $this->assertSame("bucket-{$rn}", $calls[1]['body']['bucketId']);

        $this->manager->finishRotation($binding->fresh(['region']));

        $this->assertSame(['GKold'], array_column(array_column(array_filter($this->garageCalls(), fn ($c) => $c['path'] === '/v2/DeleteKey'), 'query'), 'id'));
        $deny = $this->garageCallTo('/v2/DenyBucketKey');
        $this->assertSame(['bucketId' => "bucket-{$rn}", 'accessKeyId' => 'GKold', 'permissions' => ['read' => true, 'write' => true, 'owner' => true]], $deny['body']);
        $this->assertNull($binding->fresh()->previous_credentials);
    }

    public function test_postgres_rotation_changes_the_password(): void
    {
        Queue::fake([BindingOperationJob::class]);
        $shared = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);

        $this->manager->rotate($shared);
        $shared->refresh();

        $password = $shared->credential('password');
        $this->assertNotSame('pg-pass-1', $password);
        $this->assertSame('ALTER ROLE "'.$shared->pgName()."\" PASSWORD '{$password}';\n", $this->opsTask('postgres-rotate')->files['script.sql']);
        $this->assertNull($shared->previous_credentials);
        Queue::assertNotPushed(BindingOperationJob::class, fn ($job) => $job->operation === 'finish_rotation');

        $dedicated = $this->makeBinding('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated']);
        $this->manager->rotate($dedicated);
        $dedicated->refresh();

        $owner = $this->assertApplied('/api/v1/namespaces/runr-data/secrets/pg-'.$dedicated->resource_name.'-owner');
        $this->assertSame($dedicated->credential('password'), base64_decode($owner['data']['password']));
        $this->assertSame('true', $owner['metadata']['labels']['cnpg.io/reload']);
    }

    public function test_rotation_rolls_the_attached_workloads_to_the_new_secret(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->bindingProject($this->region);
        $this->k8sResources['/apis/serving.knative.dev/v1/namespaces/fn/services/orders-api'] = ['spec' => ['template' => [
            'metadata' => ['annotations' => ['runr.dev/deploy-timestamp' => '2026-10-10T08:00:00Z']],
            'spec' => ['containers' => [['name' => 'user-container', 'envFrom' => [['secretRef' => ['name' => 'orders-api-bindings', 'optional' => true]]]]]],
        ]]];
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        BindingAttachment::create(['binding_id' => $binding->id, 'project_id' => $project->id]);

        $this->manager->syncProject($project);
        $before = $this->applied('/api/v1/namespaces/fn/secrets/orders-api-bindings')['metadata']['annotations']['runr.dev/bindings-hash'];

        $this->signedIn($owner)->postJson("/api/bindings/{$binding->id}/rotate")->assertStatus(202);

        $secret = $this->applied('/api/v1/namespaces/fn/secrets/orders-api-bindings');
        $after = $secret['metadata']['annotations']['runr.dev/bindings-hash'];
        $this->assertNotSame($before, $after);
        $this->assertStringContainsString($binding->fresh()->credential('password'), base64_decode($secret['data']['REDIS_URL']));

        $patch = json_decode($this->k8sSent('PATCH', '/apis/serving.knative.dev/v1/namespaces/fn/services/orders-api')['body'], true);
        $this->assertContains(['op' => 'add', 'path' => '/spec/template/metadata/annotations/runr.dev~1bindings-hash', 'value' => $after], $patch);

        // The sync queue runs the delayed finish at once: the old password is gone
        $this->assertNotNull($this->opsTask('kv-rotate-finish'));
        $this->assertNull($binding->fresh()->previous_credentials);
        $this->assertSame(Binding::STATUS_READY, $binding->fresh()->status);
    }

    public function test_a_failed_rotation_keeps_the_credentials(): void
    {
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $binding->forceFill(['status' => Binding::STATUS_ROTATING])->save();
        $this->opsResponses['kv-rotate'] = new OpsResult(false, 'NOAUTH Authentication required.', 'Failed');

        (new BindingOperationJob('rotate', $binding->id))->handle($this->manager);
        $binding->refresh();

        $this->assertSame(Binding::STATUS_READY, $binding->status);
        $this->assertSame('kv-pass-1', $binding->credential('password'));
        $this->assertStringStartsWith('Credential rotation failed', $binding->status_message);
        $this->assertNull($binding->rotated_at);
    }

    // ── Delete / restore ────────────────────────────────────────────────────────────────

    public function test_deletion_revokes_access_per_type_and_restore_gives_it_back(): void
    {
        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $object = $this->makeBinding('object', $this->region, ['name' => 'assets']);
        $shared = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $dedicated = $this->makeBinding('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated']);

        foreach ([$kv, $object, $shared, $dedicated] as $binding) {
            $this->manager->scheduleDeletion($binding);
        }

        $suspend = $this->opsTask('kv-suspend')->files['commands.txt'];
        $this->assertStringContainsString("ACL SETUSER {$kv->resource_name} reset off >kv-pass-1 ", $suspend);
        $this->assertStringContainsString("CLIENT KILL USER {$kv->resource_name}", $suspend);

        $deny = $this->garageCallTo('/v2/DenyBucketKey');
        $this->assertSame("bucket-{$object->resource_name}", $deny['body']['bucketId']);
        $this->assertSame('GKold', $deny['body']['accessKeyId']);

        $this->assertStringContainsString('ALTER ROLE "'.$shared->pgName().'" NOLOGIN;', $this->opsTask('postgres-suspend')->files['script.sql']);

        $clusterPath = '/apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/pg-'.$dedicated->resource_name;
        $hibernate = json_decode($this->k8sSent('PATCH', $clusterPath)['body'], true);
        $this->assertSame(['metadata' => ['annotations' => ['cnpg.io/hibernation' => 'on']]], $hibernate);

        foreach ([$kv, $object, $shared, $dedicated] as $binding) {
            $this->assertSame(Binding::STATUS_DELETING, $binding->fresh()->status);
        }

        $this->manager->restore($kv->fresh(['region']));
        $this->manager->restore($dedicated->fresh(['region']));

        $this->assertStringContainsString("ACL SETUSER {$kv->resource_name} reset on >kv-pass-1 ", $this->opsTask('kv-resume')->files['commands.txt']);
        $this->assertSame(['metadata' => ['annotations' => ['cnpg.io/hibernation' => 'off']]], json_decode($this->k8sSent('PATCH', $clusterPath)['body'], true));
        $this->assertSame(Binding::STATUS_READY, $kv->fresh()->status);
        $this->assertNull($kv->fresh()->purge_at);
    }

    public function test_a_failed_suspend_is_recorded_and_the_deletion_stands(): void
    {
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $this->opsResponses['kv-suspend'] = new OpsResult(false, 'Could not connect to Redis', 'Failed');

        $this->manager->scheduleDeletion($binding);

        $binding->refresh();
        $this->assertSame(Binding::STATUS_DELETING, $binding->status);
        $this->assertStringContainsString('Could not connect to Redis', $binding->status_message);
    }

    // ── Purge ───────────────────────────────────────────────────────────────────────────

    public function test_the_purge_job_purges_only_bindings_whose_retention_ended(): void
    {
        $due = $this->makeBinding('kv', $this->region, ['name' => 'old', 'status' => Binding::STATUS_DELETING, 'purge_at' => now()->subMinute()]);
        $kept = $this->makeBinding('kv', $this->region, ['name' => 'recent', 'status' => Binding::STATUS_DELETING, 'purge_at' => now()->addDay()]);
        BindingUsageRecord::create([
            'binding_id' => $due->id, 'binding_name' => 'old', 'type' => 'kv', 'plan' => 'shared',
            'region_id' => $this->region->id, 'record_date' => now()->toDateString(), 'storage_gb_hours' => 0.5,
        ]);

        $this->runMaintenance('purge');

        $purge = $this->opsTask('kv-purge');
        $this->assertStringContainsString("--eval /ops/purge.lua , '{$due->resource_name}:*'", $purge->script);
        $this->assertSame("ACL DELUSER {$due->resource_name}\nACL SAVE\n", $purge->files['commands.txt']);
        $this->assertCount(1, $this->opsTasks('kv-purge'));

        $this->assertNull(Binding::find($due->id));
        $this->assertSame(Binding::STATUS_DELETING, $kept->fresh()->status);
        $this->assertSame(1, BindingUsageRecord::where('binding_id', $due->id)->count(), 'usage stays for billing');
        $this->assertNotNull($this->k8sSent('DELETE', '/apis/networking.k8s.io/v1/namespaces/runr-data/networkpolicies/runr-binding-'.$due->resource_name));
    }

    public function test_object_purge_empties_the_bucket_then_deletes_it_and_its_key(): void
    {
        $binding = $this->makeBinding('object', $this->region, ['name' => 'assets', 'status' => Binding::STATUS_DELETING, 'purge_at' => now()->subHour()]);
        $rn = $binding->resource_name;

        $this->assertTrue($this->manager->purge($binding));

        $paths = array_column($this->garageCalls(), 'path');
        $this->assertSame(['/v2/AllowBucketKey', '/v2/DeleteBucket', '/v2/DeleteKey'], $paths);

        $rclone = collect($this->opsTasks('object-purge'))->first(fn (OpsTask $t) => isset($t->files['rclone.env']));
        $this->assertNotNull($rclone);
        $this->assertStringContainsString("rclone delete --rmdirs 't:{$rn}'", $rclone->script);
        $this->assertStringContainsString("rclone cleanup 't:{$rn}' || true", $rclone->script);
        $this->assertStringContainsString("RCLONE_CONFIG_T_ACCESS_KEY_ID='GKold'", $rclone->files['rclone.env']);
        $this->assertStringContainsString("RCLONE_CONFIG_T_ENDPOINT='http://runr-objects.runr-data.svc.cluster.local:3900'", $rclone->files['rclone.env']);

        $this->assertSame(['id' => "bucket-{$rn}"], $this->garageCallTo('/v2/DeleteBucket')['query']);
        $this->assertSame(['id' => 'GKold'], $this->garageCallTo('/v2/DeleteKey')['query']);
        $this->assertNull(Binding::find($binding->id));
    }

    public function test_postgres_purge_drops_the_database_or_the_dedicated_cluster(): void
    {
        $shared = $this->makeBinding('postgres', $this->region, ['name' => 'orders', 'status' => Binding::STATUS_DELETING]);
        $dedicated = $this->makeBinding('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated', 'status' => Binding::STATUS_DELETING]);

        $this->assertTrue($this->manager->purge($shared));
        $this->assertTrue($this->manager->purge($dedicated));

        $this->assertStringContainsString('DROP DATABASE IF EXISTS "'.$shared->pgName().'" WITH (FORCE);', $this->opsTask('postgres-purge')->files['script.sql']);
        $this->assertStringContainsString('DROP ROLE IF EXISTS "'.$shared->pgName().'";', $this->opsTask('postgres-purge')->files['script.sql']);

        $cluster = 'pg-'.$dedicated->resource_name;
        $this->assertNotNull($this->k8sSent('DELETE', "/apis/postgresql.cnpg.io/v1/namespaces/runr-data/clusters/{$cluster}"));
        $this->assertNotNull($this->k8sSent('DELETE', "/apis/postgresql.cnpg.io/v1/namespaces/runr-data/scheduledbackups/{$cluster}-daily"));
        $this->assertNotNull($this->k8sSent('DELETE', "/api/v1/namespaces/runr-data/secrets/{$cluster}-owner"));
        $this->assertSame(0, Binding::count());
    }

    public function test_a_failed_purge_is_retried_later(): void
    {
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache', 'status' => Binding::STATUS_DELETING, 'purge_at' => now()->subMinute()]);
        $this->opsResponses['kv-purge'] = new OpsResult(false, 'LOADING Redis is loading the dataset in memory', 'Failed');

        $this->assertFalse($this->manager->purge($binding));

        $binding->refresh();
        $this->assertSame(Binding::STATUS_DELETING, $binding->status);
        $this->assertStringStartsWith('Deleting the binding data failed', $binding->status_message);
        $this->assertStringContainsString('LOADING', $binding->status_message);

        unset($this->opsResponses['kv-purge']);
        $this->runMaintenance('purge');
        $this->assertNull(Binding::find($binding->id));
    }

    public function test_a_binding_whose_service_is_gone_is_purged_without_region_work(): void
    {
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache', 'status' => Binding::STATUS_DELETING]);
        BindingRegionService::where('service', 'kv')->delete();

        $this->assertTrue($this->manager->purge($binding));
        $this->assertNull($this->opsTask('kv-purge'));
    }

    public function test_purge_all_now_for_a_deleted_organization(): void
    {
        $project = $this->bindingProject($this->region);
        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $ext = $this->makeBinding('external', null, ['name' => 'stripe']);
        BindingAttachment::create(['binding_id' => $kv->id, 'project_id' => $project->id]);

        $this->assertSame([], $this->manager->purgeAllNow());

        $this->assertSame(0, Binding::count());
        $this->assertSame(0, BindingAttachment::count());
        $this->assertNotNull($this->opsTask('kv-purge'));
        $this->assertNull(Binding::find($ext->id));
    }

    // ── Reconcile ───────────────────────────────────────────────────────────────────────

    public function test_acl_users_on_a_region_redis_are_reapplied(): void
    {
        $lab = $this->bindingRegion('lab', [
            'redis_mode' => 'in_cluster', 'knative_redis_status' => 'ready',
            'redis_address' => 'redis.data.svc:6379', 'redis_password' => 'region-pw',
        ]);
        $this->readyService($lab, 'kv', 'region', ['acl' => true], []);
        $over = $this->makeBinding('kv', $lab, ['name' => 'full', 'settings' => ['over_quota' => true]]);
        $deleted = $this->makeBinding('kv', $lab, ['name' => 'gone', 'status' => Binding::STATUS_DELETING]);
        $this->makeBinding('kv', $this->region, ['name' => 'managed']); // managed KV Redis persists its users

        $this->runMaintenance('reconcile');

        $tasks = $this->opsTasks('kv-reconcile');
        $this->assertCount(1, $tasks);
        $commands = collect(explode("\n", trim($tasks[0]->files['commands.txt'])));
        $this->assertCount(2, $commands, 'no ACL SAVE on a region Redis');
        $full = $commands->first(fn ($c) => str_starts_with($c, "ACL SETUSER {$over->resource_name} "));
        $gone = $commands->first(fn ($c) => str_starts_with($c, "ACL SETUSER {$deleted->resource_name} "));
        $this->assertStringStartsWith("ACL SETUSER {$over->resource_name} reset on >kv-pass-1", (string) $full);
        $this->assertStringEndsWith('-@write +del +unlink +expire +pexpire +persist', (string) $full, 'over its limit: read-only');
        $this->assertStringStartsWith("ACL SETUSER {$deleted->resource_name} reset off ", (string) $gone, 'deletion requested: off');
        $this->assertSame('region-pw', $tasks[0]->files['auth']);
        $this->assertStringContainsString("-h 'redis.data.svc' -p 6379", $tasks[0]->script);
    }
}
