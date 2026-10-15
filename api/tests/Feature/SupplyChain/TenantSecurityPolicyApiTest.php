<?php

namespace Tests\Feature\SupplyChain;

use App\Events\SupplyChainAuditEvent;
use App\Models\AuditEvent;
use App\Models\ImageSecurityReport;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Tenant admin → Residency: GET / PUT /api/tenants/{tenant}/security-policy (platform admins),
 * validation of the default region, separate abilities for residency and the tenant gate, and
 * the tenant copy of the report tables.
 */
class TenantSecurityPolicyApiTest extends TestCase
{
    use FakesSupplyChainCluster, RefreshDatabase, WiresTenancy;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);
        $this->fakeSupplyChainCluster();
        Event::fake([SupplyChainAuditEvent::class]);

        // Platform administrator (users.is_admin comes with the Tenancy migrations WiresTenancy ran)
        $this->admin = User::factory()->create(['username' => 'root']);
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        return Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    }

    public function test_show_and_update_residency(): void
    {
        $tenant = $this->tenant();
        $tr = $this->scRegion(['name' => 'istanbul']);
        $de = $this->scRegion(['name' => 'frankfurt']);
        $this->scSettings($de, ['data_location' => 'EU-DE']);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/tenants/{$tenant->id}/security-policy")->assertOk()
            ->assertJsonPath('data.restricted', false)
            ->assertJsonPath('data.residency_enforced', true)
            ->assertJsonPath('data.locations', ['EU-DE'])
            ->assertJsonPath('data.abilities.residency', true);

        $res = $this->actingAs($this->admin, 'sanctum')->putJson("/api/tenants/{$tenant->id}/security-policy", [
            'residency_enforced' => true,
            'allowed_region_ids' => [$tr->id, $tr->id],
            'allowed_locations' => ['TR '],
            'default_region_id' => $tr->id,
        ])->assertOk();

        $res->assertJsonPath('data.restricted', true);
        $res->assertJsonPath('data.allowed_region_ids', [$tr->id]);
        $res->assertJsonPath('data.allowed_locations', ['TR']);
        $res->assertJsonPath('data.updated_by', 'root');
        $this->assertSame($tr->id, TenantSecurityPolicy::find($tenant->id)->default_region_id);

        $event = AuditEvent::query()->where('action', 'tenant.residency.update')->sole();
        $this->assertSame('tenant', $event->target_type);
        $this->assertSame((string) $tenant->id, $event->target_id);
        $this->assertSame([$tr->id, $tr->id], $event->metadata['allowed_region_ids'], 'the request as sent');
        $this->assertNotNull(collect($event->metadata['changes'] ?? [])->firstWhere('model', 'tenant_security_policy'));
    }

    public function test_the_default_region_must_be_allowed(): void
    {
        $tenant = $this->tenant();
        $tr = $this->scRegion(['name' => 'istanbul']);
        $de = $this->scRegion(['name' => 'frankfurt']);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/tenants/{$tenant->id}/security-policy", [
            'allowed_region_ids' => [$tr->id],
            'default_region_id' => $de->id,
        ])->assertStatus(422)->assertJsonValidationErrors('default_region_id');

        $this->assertNull(TenantSecurityPolicy::find($tenant->id));
    }

    public function test_tenant_gate_fields(): void
    {
        $tenant = $this->tenant();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/tenants/{$tenant->id}/security-policy", [
            'gate_mode' => 'enforce', 'gate_max_critical' => 0, 'gate_require_scan' => true,
        ])->assertOk()
            ->assertJsonPath('data.gate_mode', 'enforce')
            ->assertJsonPath('data.gate_max_critical', 0)
            ->assertJsonPath('data.gate_require_scan', true);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/tenants/{$tenant->id}/security-policy", ['gate_mode' => 'block'])
            ->assertStatus(422)->assertJsonValidationErrors('gate_mode');
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/tenants/{$tenant->id}/security-policy", [])->assertStatus(422);
    }

    public function test_members_cannot_read_or_change_a_tenant_policy(): void
    {
        $tenant = $this->tenant();
        $member = User::factory()->create(['username' => 'member']);

        $this->actingAs($member, 'sanctum')->getJson("/api/tenants/{$tenant->id}/security-policy")->assertForbidden();
        $this->actingAs($member, 'sanctum')->putJson("/api/tenants/{$tenant->id}/security-policy", ['residency_enforced' => false])->assertForbidden();
        $this->actingAs($member, 'sanctum')->putJson("/api/tenants/{$tenant->id}/security-policy", ['gate_mode' => 'off'])->assertForbidden();
    }

    public function test_unknown_tenant_and_single_tenant_installs(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/tenants/nope/security-policy')->assertNotFound();

        $tenant = $this->tenant();
        config(['tenancy.enabled' => false]);
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/tenants/{$tenant->id}/security-policy")->assertNotFound();
    }

    public function test_reports_of_tenant_projects_live_in_the_tenant_schema(): void
    {
        $tenant = $this->createTenantWithSchema('globex', [
            'Modules/Tenancy/Database/TenantMigrations/0035_create_supply_chain_report_tables.php',
        ]);

        $tenant->run(function () {
            ImageSecurityReport::create([
                'subject_type' => 'version', 'subject_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'knative_region_id' => '01HYYYYYYYYYYYYYYYYYYYYYYY',
                'image_kind' => 'built', 'image_ref' => 'r/x:v1',
            ]);
            $this->assertSame(1, ImageSecurityReport::count());
            $this->assertTrue(DB::getSchemaBuilder()->hasTable('image_sbom_documents'));
            $this->assertTrue(DB::getSchemaBuilder()->hasTable('security_policy_overrides'));
        });

        $this->assertSame(0, ImageSecurityReport::count(), 'nothing in the central schema');
    }
}
