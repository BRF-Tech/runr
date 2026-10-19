<?php

namespace Tests\Feature\Bindings;

use App\Events\BindingAudited;
use App\Jobs\Bindings\BindingOperationJob;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\BindingUsageRecord;
use App\Models\KnativeRegion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * /api/bindings and /api/projects/{project}/bindings: create per type, validation, data service
 * readiness, quotas, credentials never listed (reveal only with bindings.view_credentials,
 * audited), settings, delete with retention + restore, rotation, permissions.
 */
class BindingApiTest extends TestCase
{
    use ActsWithRoles, FakesBindingOps, RefreshDatabase;

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();

        $this->owner = $this->userWithRole('owner');
        $this->region = $this->bindingRegion();
    }

    private function create(array $payload, ?User $as = null)
    {
        return $this->signedIn($as ?? $this->owner)->postJson('/api/bindings', $payload);
    }

    // ── Create ──────────────────────────────────────────────────────────────────────────

    public function test_a_kv_binding_is_created_and_provisioned(): void
    {
        $this->readyService($this->region, 'kv');

        $response = $this->create([
            'name' => 'cache', 'type' => 'kv', 'knative_region_id' => $this->region->id, 'settings' => ['memory_mb' => 128],
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'cache')
            ->assertJsonPath('data.type', 'kv')
            ->assertJsonPath('data.plan', 'shared')
            ->assertJsonPath('data.status', Binding::STATUS_READY)
            ->assertJsonPath('data.settings.memory_mb', 128)
            ->assertJsonPath('data.env_keys', ['REDIS_URL', 'KV_PREFIX'])
            ->assertJsonPath('data.details.mode', 'acl')
            ->assertJsonPath('data.region.name', 'fn');
        $this->assertArrayNotHasKey('credentials', $response->json('data'));

        $binding = Binding::firstOrFail();
        $this->assertMatchesRegularExpression('/^cache-[0-9a-z]{8}$/', $binding->resource_name);
        $this->assertSame('acl', $binding->credential('mode'));
        $this->assertSame($binding->resource_name, $binding->credential('username'));
        $this->assertSame(32, strlen($binding->credential('password')));
        $this->assertStringNotContainsString($binding->credential('password'), $response->getContent());
        $this->assertSame((string) $this->owner->id, $binding->created_by);
        $this->assertNotNull($this->opsTask('kv-provision'));
    }

    public function test_object_postgres_and_external_bindings_are_created(): void
    {
        $this->readyService($this->region, 'object');
        $this->readyService($this->region, 'postgres');
        $this->readyInstances = 2; // the dedicated cluster below runs two instances

        $this->create(['name' => 'assets', 'type' => 'object', 'knative_region_id' => $this->region->id, 'settings' => ['quota_gb' => 10]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.details.scoped_keys', true)
            ->assertJsonPath('data.settings.quota_gb', 10);

        $this->create(['name' => 'orders', 'type' => 'postgres', 'knative_region_id' => $this->region->id])
            ->assertCreated()
            ->assertJsonPath('data.plan', 'shared')
            ->assertJsonPath('data.details.cluster', 'runr-pg-shared')
            ->assertJsonPath('data.settings.connection_limit', 20)
            ->assertJsonPath('data.settings.statement_timeout', '30s');

        $this->create([
            'name' => 'ledger', 'type' => 'postgres', 'plan' => 'dedicated', 'knative_region_id' => $this->region->id,
            'settings' => ['instances' => 2, 'storage_gb' => 5],
        ])->assertCreated()
            ->assertJsonPath('data.plan', 'dedicated')
            ->assertJsonPath('data.settings.instances', 2)
            ->assertJsonPath('data.details.cluster', fn ($cluster) => str_starts_with((string) $cluster, 'pg-ledger-'));

        $this->create(['name' => 'stripe', 'type' => 'external', 'values' => ['API_KEY' => 'sk_test', 'API_URL' => 'https://api.example.com']])
            ->assertCreated()
            ->assertJsonPath('data.knative_region_id', null)
            ->assertJsonPath('data.plan', 'external')
            ->assertJsonPath('data.env_keys', ['API_KEY', 'API_URL']);

        $this->assertSame(4, Binding::where('status', 'ready')->count());
    }

    public function test_validation(): void
    {
        $this->readyService($this->region, 'kv');
        $region = $this->region->id;

        $this->create(['name' => 'Bad_Name', 'type' => 'kv', 'knative_region_id' => $region])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->create(['name' => str_repeat('a', 31), 'type' => 'kv', 'knative_region_id' => $region])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->create(['name' => 'cache', 'type' => 'nosql', 'knative_region_id' => $region])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->create(['name' => 'cache', 'type' => 'kv'])->assertUnprocessable()->assertJsonValidationErrors('knative_region_id');
        $this->create(['name' => 'cache', 'type' => 'kv', 'plan' => 'dedicated', 'knative_region_id' => $region])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->create(['name' => 'cache', 'type' => 'kv', 'knative_region_id' => $region, 'settings' => ['memory_mb' => 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.memory_mb');
        $this->create(['name' => 'db', 'type' => 'postgres', 'knative_region_id' => $region, 'settings' => ['statement_timeout' => '1h']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.statement_timeout');
        $this->create(['name' => 'ext', 'type' => 'external'])->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->create(['name' => 'ext', 'type' => 'external', 'values' => ['bad-key' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('values');

        $this->create(['name' => 'cache', 'type' => 'kv', 'knative_region_id' => $region])->assertCreated();
        $this->create(['name' => 'cache', 'type' => 'kv', 'knative_region_id' => $region])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame(1, Binding::count());
    }

    public function test_the_region_data_service_must_be_ready(): void
    {
        $this->create(['name' => 'orders', 'type' => 'postgres', 'knative_region_id' => $this->region->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.bindings.service_not_ready', ['service' => 'postgres', 'region' => 'fn']));

        $this->readyService($this->region, 'kv')->update(['status' => 'installing']);
        $this->create(['name' => 'cache', 'type' => 'kv', 'knative_region_id' => $this->region->id])->assertUnprocessable();

        $demo = $this->bindingRegion('demo', ['is_demo' => true]);
        $this->create(['name' => 'cache', 'type' => 'kv', 'knative_region_id' => $demo->id])->assertUnprocessable();

        $this->assertSame(0, Binding::count());
    }

    public function test_backups_of_a_dedicated_cluster_need_the_object_store(): void
    {
        $this->readyService($this->region, 'postgres');

        $this->create([
            'name' => 'ledger', 'type' => 'postgres', 'plan' => 'dedicated', 'knative_region_id' => $this->region->id,
            'settings' => ['backups' => true],
        ])->assertUnprocessable()->assertJsonPath('message', __('api.bindings.backups_need_object_store'));
    }

    public function test_quotas_are_enforced(): void
    {
        $this->readyService($this->region, 'kv');
        $this->readyService($this->region, 'postgres');

        config(['bindings.quotas.max_bindings' => 2]);
        $this->create(['name' => 'a', 'type' => 'external', 'values' => ['A' => '1']])->assertCreated();
        $this->create(['name' => 'b', 'type' => 'external', 'values' => ['B' => '1']])->assertCreated();
        $this->create(['name' => 'c', 'type' => 'external', 'values' => ['C' => '1']])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'max_bindings'));

        config(['bindings.quotas.max_bindings' => 0, 'bindings.quotas.kv_mb' => 100]);
        $this->create(['name' => 'kv-one', 'type' => 'kv', 'knative_region_id' => $this->region->id, 'settings' => ['memory_mb' => 64]])->assertCreated();
        $this->create(['name' => 'kv-two', 'type' => 'kv', 'knative_region_id' => $this->region->id, 'settings' => ['memory_mb' => 64]])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'kv_mb'));

        config(['bindings.quotas.dedicated_postgres' => 1, 'bindings.quotas.postgres_gb' => 0]);
        $dedicated = ['type' => 'postgres', 'plan' => 'dedicated', 'knative_region_id' => $this->region->id];
        $this->create(['name' => 'pg-one'] + $dedicated)->assertCreated();
        $this->create(['name' => 'pg-two'] + $dedicated)
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'dedicated_postgres'));

        $this->signedIn($this->owner)->getJson('/api/bindings/quota')
            ->assertOk()
            ->assertJsonPath('data.limits.kv_mb', 100)
            ->assertJsonPath('data.used.kv_mb', 64)
            ->assertJsonPath('data.used.dedicated_postgres', 1)
            ->assertJsonPath('data.used.max_bindings', 4);
    }

    // ── Read ────────────────────────────────────────────────────────────────────────────

    public function test_list_and_show_never_carry_credentials(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $binding->forceFill(['previous_credentials' => ['password' => 'older-pass']])->save();
        $project = $this->bindingProject($this->region);
        BindingAttachment::create(['binding_id' => $binding->id, 'project_id' => $project->id, 'env_prefix' => 'CACHE_']);

        $list = $this->signedIn($this->owner)->getJson('/api/bindings')->assertOk();
        $list->assertJsonPath('data.0.name', 'cache')
            ->assertJsonPath('data.0.attachments.0.project.slug', 'orders-api')
            ->assertJsonPath('data.0.attachments.0.env_names', ['CACHE_REDIS_URL', 'CACHE_KV_PREFIX'])
            ->assertJsonPath('abilities.manage', true)
            ->assertJsonPath('abilities.view_credentials', true);

        $show = $this->signedIn($this->owner)->getJson("/api/bindings/{$binding->id}")->assertOk();

        foreach ([$list, $show] as $response) {
            $this->assertStringNotContainsString('kv-pass-1', $response->getContent());
            $this->assertStringNotContainsString('older-pass', $response->getContent());
            $this->assertStringNotContainsString('"credentials"', $response->getContent());
            $this->assertStringNotContainsString('"previous_credentials"', $response->getContent());
        }

        $this->signedIn($this->owner)->getJson('/api/bindings?type=postgres')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_types_and_usage(): void
    {
        $this->signedIn($this->owner)->getJson('/api/bindings/types')
            ->assertOk()
            ->assertJsonPath('data.kv.env_keys', ['REDIS_URL', 'KV_PREFIX'])
            ->assertJsonPath('data.postgres.plans', ['shared', 'dedicated'])
            ->assertJsonPath('data.object.defaults.quota_gb', 5)
            ->assertJsonPath('retention_days', 7);

        $binding = $this->makeBinding('external', null, ['name' => 'stripe']);
        BindingUsageRecord::create([
            'binding_id' => $binding->id, 'binding_name' => 'stripe', 'type' => 'external', 'plan' => 'external',
            'record_date' => now()->toDateString(), 'storage_gb_hours' => 1.5, 'instance_hours' => 0, 'final_cost' => 0.25, 'currency' => 'EUR',
        ]);

        // Quantities only: a cost of an earlier release is not answered (the Billing module prices usage)
        $this->signedIn($this->owner)->getJson("/api/bindings/{$binding->id}/usage")
            ->assertOk()
            ->assertJsonPath('data.0.storage_gb_hours', 1.5)
            ->assertJsonMissingPath('data.0.final_cost')
            ->assertJsonMissingPath('data.0.currency');
    }

    public function test_credentials_are_revealed_only_with_the_permission_and_audited(): void
    {
        Event::fake([BindingAudited::class]);
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->signedIn($this->owner)->getJson("/api/bindings/{$binding->id}/credentials")
            ->assertOk()
            ->assertJsonPath('data.env.KV_PREFIX', $binding->resource_name.':')
            ->assertJsonPath('data.env.REDIS_URL', "redis://{$binding->resource_name}:kv-pass-1@runr-kv.runr-data.svc.cluster.local:6379");

        Event::assertDispatched(BindingAudited::class, fn (BindingAudited $e) => $e->action === 'binding.credentials_revealed'
            && $e->subject['binding_id'] === $binding->id
            && $e->actorId === (string) $this->owner->id);

        foreach (['developer', 'viewer'] as $role) {
            $this->signedIn($this->userWithRole($role))->getJson("/api/bindings/{$binding->id}/credentials")
                ->assertForbidden();
        }

        Event::assertDispatchedTimes(BindingAudited::class, 1);
    }

    // ── Change ──────────────────────────────────────────────────────────────────────────

    public function test_settings_are_updated(): void
    {
        $this->readyService($this->region, 'kv');
        $this->readyService($this->region, 'postgres');
        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->signedIn($this->owner)->patchJson("/api/bindings/{$kv->id}", ['settings' => ['memory_mb' => 256]])
            ->assertOk()
            ->assertJsonPath('data.settings.memory_mb', 256);
        $this->assertSame(256, $kv->fresh()->setting('memory_mb'));

        $dedicated = $this->makeBinding('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated', 'settings' => ['storage_gb' => 10]]);
        $this->signedIn($this->owner)->patchJson("/api/bindings/{$dedicated->id}", ['settings' => ['storage_gb' => 5]])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.bindings.storage_shrink'));

        $this->signedIn($this->owner)->patchJson("/api/bindings/{$kv->id}", ['values' => ['A' => 'b']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('values');

        $external = $this->makeBinding('external', null, ['name' => 'stripe']);
        $this->signedIn($this->owner)->patchJson("/api/bindings/{$external->id}", ['values' => ['API_KEY' => 'sk_new']])
            ->assertOk()
            ->assertJsonPath('data.env_keys', ['API_KEY']);
        $this->assertSame(['API_KEY' => 'sk_new'], $external->fresh()->credential('values'));
    }

    public function test_delete_needs_the_name_and_keeps_the_data_until_purge(): void
    {
        config(['bindings.retention_days' => 3]);
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $project = $this->bindingProject($this->region);
        BindingAttachment::create(['binding_id' => $binding->id, 'project_id' => $project->id]);

        $this->signedIn($this->owner)->deleteJson("/api/bindings/{$binding->id}")->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->signedIn($this->owner)->deleteJson("/api/bindings/{$binding->id}", ['confirm' => 'nope'])->assertUnprocessable();

        $this->signedIn($this->owner)->deleteJson("/api/bindings/{$binding->id}", ['confirm' => 'cache'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', Binding::STATUS_DELETING);

        $binding->refresh();
        $this->assertSame(Binding::STATUS_DELETING, $binding->status);
        $this->assertSame(now()->addDays(3)->toDateString(), $binding->purge_at->toDateString());
        $this->assertNotNull($binding->delete_requested_at);
        $this->assertSame(0, BindingAttachment::count(), 'detached from every project');
        $this->assertNotNull($this->opsTask('kv-suspend'));

        // A deleted binding cannot be attached, rotated or deleted again
        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id])->assertUnprocessable();
        $this->signedIn($this->owner)->postJson("/api/bindings/{$binding->id}/rotate")->assertUnprocessable();
        $this->signedIn($this->owner)->deleteJson("/api/bindings/{$binding->id}", ['confirm' => 'cache'])->assertUnprocessable();

        $this->signedIn($this->owner)->postJson("/api/bindings/{$binding->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', Binding::STATUS_READY)
            ->assertJsonPath('data.purge_at', null);
        $this->assertNotNull($this->opsTask('kv-resume'));

        $this->signedIn($this->owner)->postJson("/api/bindings/{$binding->id}/restore")->assertUnprocessable();
    }

    public function test_rotation_is_queued(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        Queue::fake([BindingOperationJob::class]);

        $this->signedIn($this->owner)->postJson("/api/bindings/{$binding->id}/rotate")
            ->assertStatus(202)
            ->assertJsonPath('data.status', Binding::STATUS_ROTATING);

        Queue::assertPushed(BindingOperationJob::class, fn ($job) => $job->operation === 'rotate' && $job->bindingId === $binding->id);

        // Not ready while rotating
        $this->signedIn($this->owner)->postJson("/api/bindings/{$binding->id}/rotate")->assertUnprocessable();
    }

    public function test_status_reports_the_health(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->signedIn($this->owner)->getJson("/api/bindings/{$binding->id}/status")
            ->assertOk()
            ->assertJsonPath('health.state', 'ready')
            ->assertJsonPath('health.details.mode', 'acl');
        $this->assertSame('ACL GETUSER '.$binding->resource_name."\n", $this->opsTask('kv-health')->files['commands.txt'], 'a read saves no ACL file');
    }

    // ── Permissions ─────────────────────────────────────────────────────────────────────

    public function test_viewers_list_and_developers_create(): void
    {
        $viewer = $this->userWithRole('viewer');
        $developer = $this->userWithRole('developer');

        $this->signedIn($viewer)->getJson('/api/bindings')->assertOk()->assertJsonPath('abilities.manage', false);
        $this->signedIn($viewer)->postJson('/api/bindings', ['name' => 'x', 'type' => 'external', 'values' => ['A' => 'b']])->assertForbidden();

        $this->signedIn($developer)->postJson('/api/bindings', ['name' => 'x', 'type' => 'external', 'values' => ['A' => 'b']])->assertCreated();
        $id = Binding::firstOrFail()->id;
        $this->signedIn($viewer)->patchJson("/api/bindings/{$id}", ['values' => ['A' => 'c']])->assertForbidden();
        $this->signedIn($viewer)->deleteJson("/api/bindings/{$id}", ['confirm' => 'x'])->assertForbidden();
        $this->signedIn($viewer)->getJson("/api/bindings/{$id}")->assertOk();
    }

    public function test_project_routes_need_env_edit_and_bindings_manage(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $project = $this->bindingProject($this->region);

        $this->customRole('env-editor', ['env.edit']);
        $this->customRole('reader', ['projects.view']);

        $this->signedIn($this->userWithRole('viewer'))->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id])
            ->assertForbidden();
        $this->signedIn($this->userWithRole('env-editor'))->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id])
            ->assertForbidden();

        $this->signedIn($this->userWithRole('developer'))->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id, 'env_prefix' => 'CACHE_'])
            ->assertCreated()
            ->assertJsonPath('data.env_prefix', 'CACHE_')
            ->assertJsonPath('data.env_names', ['CACHE_REDIS_URL', 'CACHE_KV_PREFIX'])
            ->assertJsonPath('data.binding.name', 'cache');

        // projects.view sees the attachments; the organization's other bindings need bindings.view
        $this->signedIn($this->userWithRole('reader'))->getJson("/api/projects/{$project->id}/bindings")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(0, 'available')
            ->assertJsonPath('secret', 'orders-api-bindings');

        $attachment = BindingAttachment::firstOrFail();
        $this->signedIn($this->userWithRole('viewer'))->deleteJson("/api/projects/{$project->id}/bindings/{$attachment->id}")->assertForbidden();
        $this->signedIn($this->owner)->patchJson("/api/projects/{$project->id}/bindings/{$attachment->id}", ['env_prefix' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('env_prefix');
        $this->signedIn($this->owner)->patchJson("/api/projects/{$project->id}/bindings/{$attachment->id}", ['env_prefix' => 'KV_'])
            ->assertOk()->assertJsonPath('data.env_names', ['KV_REDIS_URL', 'KV_KV_PREFIX']);
        $this->signedIn($this->owner)->deleteJson("/api/projects/{$project->id}/bindings/{$attachment->id}")->assertOk();
        $this->assertSame(0, BindingAttachment::count());
    }

    public function test_create_and_attach_in_one_request_uses_the_project_region(): void
    {
        $this->readyService($this->region, 'postgres');
        $project = $this->bindingProject($this->region);
        $other = $this->bindingRegion('other');

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", [
            'env_prefix' => 'ORDERS_',
            'binding' => ['name' => 'orders', 'type' => 'postgres', 'knative_region_id' => $other->id],
        ])->assertCreated()
            ->assertJsonPath('data.binding.knative_region_id', $this->region->id)
            ->assertJsonPath('data.binding.status', 'ready')
            ->assertJsonPath('data.env_names.0', 'ORDERS_DATABASE_URL');

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", ['binding' => ['name' => 'Bad Name', 'type' => 'kv']])
            ->assertUnprocessable()->assertJsonValidationErrors('binding.name');
    }
}
