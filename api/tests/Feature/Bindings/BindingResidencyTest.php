<?php

namespace Tests\Feature\Bindings;

use App\Models\AuditEvent;
use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Services\Residency\RegionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * Data residency of bindings (RegionPolicy::BINDING_CREATE, docs/supply-chain.md): a tenant
 * pinned to regions / data locations creates, restores and attaches bindings only there; a
 * refusal is a 422 on the field and a residency.denied audit row. External bindings live in no
 * region and are not restricted.
 */
class BindingResidencyTest extends TestCase
{
    use ActsWithRoles, FakesBindingOps, RefreshDatabase;

    private User $owner;

    private KnativeRegion $istanbul;

    private KnativeRegion $ankara;

    private KnativeRegion $frankfurt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();

        $this->owner = $this->userWithRole('owner');
        $this->istanbul = $this->bindingRegion('istanbul');
        $this->ankara = $this->bindingRegion('ankara');
        $this->frankfurt = $this->bindingRegion('frankfurt');

        foreach ([[$this->ankara, 'TR'], [$this->frankfurt, 'EU-DE']] as [$region, $location]) {
            RegionSupplyChainSetting::firstOrCreateFor($region)->forceFill(['data_location' => $location])->save();
        }
        foreach ([$this->istanbul, $this->ankara, $this->frankfurt] as $region) {
            $this->readyService($region, 'kv');
        }

        RegionPolicy::resolveTenantUsing(fn () => 'acme');
        TenantSecurityPolicy::create([
            'tenant_id' => 'acme',
            'residency_enforced' => true,
            'allowed_region_ids' => [$this->istanbul->id],
            'allowed_locations' => ['tr'],
            'default_region_id' => $this->istanbul->id,
        ]);
    }

    protected function tearDown(): void
    {
        RegionPolicy::resolveTenantUsing(null);
        parent::tearDown();
    }

    private function deniedFor(KnativeRegion $region): ?AuditEvent
    {
        return AuditEvent::query()
            ->where('action', 'residency.denied')
            ->where('target_id', $region->id)
            ->latest('occurred_at')
            ->first();
    }

    public function test_bindings_are_created_in_allowed_regions_and_locations(): void
    {
        foreach (['istanbul' => $this->istanbul, 'ankara' => $this->ankara] as $name => $region) {
            $this->signedIn($this->owner)->postJson('/api/bindings', [
                'name' => "cache-{$name}", 'type' => 'kv', 'knative_region_id' => $region->id,
            ])->assertCreated();
        }

        $this->assertSame(2, Binding::count());
        $this->assertSame(0, AuditEvent::where('action', 'residency.denied')->count());
    }

    public function test_a_region_outside_the_policy_is_refused_and_audited(): void
    {
        $this->signedIn($this->owner)->postJson('/api/bindings', [
            'name' => 'cache', 'type' => 'kv', 'knative_region_id' => $this->frankfurt->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed')
            ->assertJsonPath('action', RegionPolicy::BINDING_CREATE)
            ->assertJsonValidationErrors('knative_region_id');

        $this->assertSame(0, Binding::count());
        $this->assertNull($this->opsTask('kv-provision'), 'nothing is provisioned in the region');

        $denied = $this->deniedFor($this->frankfurt);
        $this->assertNotNull($denied);
        $this->assertSame(AuditEvent::RESULT_DENIED, $denied->result);
        $this->assertSame('region', $denied->target_type);
        $this->assertSame(RegionPolicy::BINDING_CREATE, $denied->metadata['action']);
        $this->assertSame('acme', $denied->metadata['tenant_id']);
        $this->assertSame('EU-DE', $denied->metadata['location']);
    }

    public function test_create_and_attach_through_a_project_follows_the_projects_region(): void
    {
        $project = $this->bindingProject($this->frankfurt, 'orders-api');

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", [
            'env_prefix' => 'CACHE_',
            'binding' => ['name' => 'cache', 'type' => 'kv'],
        ])->assertStatus(422)->assertJsonPath('code', 'region_not_allowed');

        $this->assertSame(0, Binding::count());
        $this->assertSame(0, BindingAttachment::count());
        $this->assertNotNull($this->deniedFor($this->frankfurt));
    }

    public function test_external_bindings_are_not_restricted(): void
    {
        $this->signedIn($this->owner)->postJson('/api/bindings', [
            'name' => 'payments', 'type' => 'external', 'values' => ['PAYMENTS_URL' => 'https://pay.example.com'],
        ])->assertCreated();

        $project = $this->bindingProject($this->frankfurt, 'orders-api');
        $binding = Binding::firstOrFail();

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id])->assertCreated();
        $this->assertSame(0, AuditEvent::where('action', 'residency.denied')->count());
    }

    public function test_attaching_a_binding_of_a_region_the_tenant_left_is_refused(): void
    {
        // Created before the tenant was pinned to Türkiye
        $binding = $this->makeBinding('kv', $this->frankfurt, ['name' => 'legacy-cache']);
        $project = $this->bindingProject($this->frankfurt, 'orders-api');

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/bindings", ['binding_id' => $binding->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed')
            ->assertJsonValidationErrors('binding_id');

        $this->assertSame(0, BindingAttachment::count());
        $this->assertNotNull($this->deniedFor($this->frankfurt));

        // A binding in an allowed region attaches
        $allowed = $this->makeBinding('kv', $this->istanbul, ['name' => 'cache']);
        $local = $this->bindingProject($this->istanbul, 'reports');
        $this->signedIn($this->owner)->postJson("/api/projects/{$local->id}/bindings", ['binding_id' => $allowed->id])->assertCreated();
    }

    public function test_restore_needs_the_region_to_be_allowed(): void
    {
        $outside = $this->makeBinding('kv', $this->frankfurt, ['name' => 'legacy-cache']);
        $inside = $this->makeBinding('kv', $this->ankara, ['name' => 'cache']);
        foreach ([$outside, $inside] as $binding) {
            $binding->forceFill(['status' => Binding::STATUS_DELETING, 'purge_at' => now()->addDays(3)])->save();
        }

        $this->signedIn($this->owner)->postJson("/api/bindings/{$outside->id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('code', 'region_not_allowed');
        $this->assertSame(Binding::STATUS_DELETING, $outside->fresh()->status, 'stays scheduled for deletion');
        $this->assertNotNull($this->deniedFor($this->frankfurt));

        $this->signedIn($this->owner)->postJson("/api/bindings/{$inside->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', Binding::STATUS_READY);
    }

    public function test_nothing_is_restricted_outside_tenant_context(): void
    {
        RegionPolicy::resolveTenantUsing(fn () => null);

        $this->signedIn($this->owner)->postJson('/api/bindings', [
            'name' => 'cache', 'type' => 'kv', 'knative_region_id' => $this->frankfurt->id,
        ])->assertCreated();

        $this->assertSame(0, AuditEvent::where('action', 'residency.denied')->count());
    }
}
