<?php

namespace Tests\Feature\Bindings;

use App\Models\AuditEvent;
use App\Models\Binding;
use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Models\User;
use App\Services\Audit\AuditCatalog;
use App\Services\Bindings\BindingManager;
use App\Support\Secrets\SecretColumns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * Bindings under the security conventions (docs/security.md): every binding route is in the
 * audit catalog, the credential reveal is audited and never cached, the purge is recorded as a
 * system action, and credentials are secret columns (App\Casts\Encrypted, SecretColumns).
 */
class BindingAuditTest extends TestCase
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

    public function test_every_binding_route_has_an_audit_action(): void
    {
        $routes = AuditCatalog::routes();

        foreach ([
            'POST api/bindings' => 'binding.create',
            'PATCH api/bindings/{binding}' => 'binding.update',
            'DELETE api/bindings/{binding}' => 'binding.delete',
            'POST api/bindings/{binding}/restore' => 'binding.restore',
            'POST api/bindings/{binding}/rotate' => 'binding.credentials.rotate',
            'GET api/bindings/{binding}/credentials' => 'binding.credentials.reveal',
            'POST api/projects/{project}/bindings' => 'binding.attach',
            'PATCH api/projects/{project}/bindings/{attachment}' => 'binding.attachment.update',
            'DELETE api/projects/{project}/bindings/{attachment}' => 'binding.detach',
            'POST api/knative-regions/{knativeRegion}/data-services/{service}' => 'region.data_service.install',
            'DELETE api/knative-regions/{knativeRegion}/data-services/{service}' => 'region.data_service.uninstall',
            'PUT api/tenants/{tenant}/binding-quotas' => 'tenant.binding_quota.update',
        ] as $key => $action) {
            $this->assertArrayHasKey($key, $routes, "{$key} is audited");
            $this->assertSame($action, $routes[$key]->action, $key);
        }

        $this->assertContains('binding.purged', AuditCatalog::actions());
    }

    public function test_the_reveal_is_audited_without_values_and_never_cached(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);

        $this->signedIn($this->userWithRole('viewer'))->getJson("/api/bindings/{$binding->id}/credentials")->assertForbidden();
        $this->app['auth']->forgetGuards();

        $response = $this->signedIn($this->owner)->getJson("/api/bindings/{$binding->id}/credentials")->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertSame(1, AuditEvent::where('action', 'binding.credentials.reveal')->where('result', 'success')->count());
        $this->assertSame(1, AuditEvent::where('action', 'binding.credentials.reveal')->where('result', 'denied')->count());

        $password = (string) $binding->credential('password');
        $this->assertNotSame('', $password);
        $this->assertStringNotContainsString($password, json_encode(AuditEvent::all()->map->toPublicArray()));
    }

    public function test_create_attach_and_delete_are_audited(): void
    {
        $this->readyService($this->region, 'kv');
        $project = $this->bindingProject($this->region);

        $id = $this->signedIn($this->owner)->postJson('/api/bindings', [
            'name' => 'cache', 'type' => 'kv', 'knative_region_id' => $this->region->id,
        ])->assertCreated()->json('data.id');

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $id, 'env_prefix' => 'CACHE_'])->assertCreated();
        $this->signedIn($this->owner)->deleteJson("/api/bindings/{$id}", ['confirm' => 'cache'])->assertStatus(202);

        $create = AuditEvent::where('action', 'binding.create')->firstOrFail();
        $this->assertSame('binding', $create->target_type);
        $this->assertSame($id, $create->target_id);

        $attach = AuditEvent::where('action', 'binding.attach')->firstOrFail();
        $this->assertSame('project', $attach->target_type);
        $this->assertSame((string) $project->id, (string) $attach->target_id);

        $this->assertSame(1, AuditEvent::where('action', 'binding.delete')->count());
    }

    public function test_the_purge_is_a_system_audit_event(): void
    {
        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $binding->forceFill(['status' => Binding::STATUS_DELETING, 'purge_at' => now()->subMinute()])->save();

        $this->assertSame(1, app(BindingManager::class)->purgeDue());

        $event = AuditEvent::where('action', 'binding.purged')->firstOrFail();
        $this->assertSame('system', $event->actor_type);
        $this->assertSame($binding->id, $event->target_id);
        $this->assertSame('cache', $event->target_name);
    }

    public function test_credentials_are_secret_columns_encrypted_at_rest(): void
    {
        $this->assertSame(SecretColumns::JSON, SecretColumns::central()['bindings']['credentials']);
        $this->assertSame(SecretColumns::JSON, SecretColumns::tenant()['bindings']['previous_credentials']);
        $this->assertSame(SecretColumns::JSON, SecretColumns::tenant()['binding_region_services']['secrets']);

        $this->readyService($this->region, 'kv');
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $binding->forceFill(['previous_credentials' => ['password' => 'older-pass']])->save();

        $raw = DB::table('bindings')->where('id', $binding->id)->first();
        $this->assertStringNotContainsString((string) $binding->credential('password'), (string) $raw->credentials);
        $this->assertStringNotContainsString('older-pass', (string) $raw->previous_credentials);
        $this->assertSame('older-pass', Binding::find($binding->id)->previous_credentials['password']);

        $service = DB::table('binding_region_services')->where('knative_region_id', $this->region->id)->where('service', 'kv')->first();
        $this->assertStringNotContainsString('kv-admin-pw', (string) $service->secrets);
        $this->assertSame('kv-admin-pw', BindingRegionService::forRegion($this->region, 'kv')->secret('password'));
    }
}
