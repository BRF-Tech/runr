<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Modules\Tenancy\Events\RegionShareAccepted;
use Modules\Tenancy\Events\RegionShareOffered;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Modules\Tenancy\Providers\TenancyServiceProvider;
use Modules\Tenancy\Support\TenantRegionShares;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * A platform region shared with a tenant waits for the tenant's acceptance while a module requires
 * it (billing on, docs/tenancy.md): the share is pending, the region is not copied into the
 * tenant's schema — its users cannot use it — and later saves of the region do not copy it either;
 * accepting makes it active and copies it. Without the requirement a share is active at once.
 */
class TenantRegionSharesTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        (fn () => $this->registerSyncEvents())->call(new TenancyServiceProvider($this->app));
        $this->app->bind(KnativeRegion::class, TenantAwareKnativeRegion::class);
        Http::fake(fn () => Http::response(['kind' => 'Status', 'items' => []], 200));
        Event::fake([RegionShareOffered::class, RegionShareAccepted::class]);
    }

    protected function tearDown(): void
    {
        TenantRegionShares::requireAcceptanceWhen(null);
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function region(string $name = 'eu-1'): KnativeRegion
    {
        return KnativeRegion::create(['name' => $name, 'project_namespace' => $name, 'is_active' => true, 'total_cpu_m' => 8000, 'total_memory_mb' => 16384]);
    }

    private function assign(Tenant $tenant, KnativeRegion $region): void
    {
        $request = Request::create("/api/tenants/{$tenant->id}/regions", 'POST', [
            'region_id' => $region->id, 'cpu_quota_m' => 1000, 'memory_quota_mb' => 2048,
        ]);

        app(TenantController::class)->assignRegion($request, (string) $tenant->id);
    }

    private function copied(Tenant $tenant, string $regionId): bool
    {
        return $tenant->run(fn () => DB::table('knative_regions')->where('id', $regionId)->exists());
    }

    public function test_without_the_requirement_a_shared_region_is_active_at_once(): void
    {
        $region = $this->region();
        $acme = $this->createTenantWithSchema('acme');

        $this->assign($acme, $region);

        $this->assertSame(TenantRegionShares::ACTIVE, TenantRegionShares::status((string) $acme->id, (string) $region->id));
        $this->assertTrue($this->copied($acme, (string) $region->id));
        Event::assertNotDispatched(RegionShareOffered::class);
    }

    public function test_a_share_waits_for_the_acceptance_and_is_copied_only_then(): void
    {
        TenantRegionShares::requireAcceptanceWhen(fn () => true);
        $region = $this->region();
        $acme = $this->createTenantWithSchema('acme');

        $this->assign($acme, $region);

        $row = TenantRegionShares::forTenant((string) $acme->id)->sole();
        $this->assertSame(TenantRegionShares::PENDING, $row->status);
        $this->assertNotNull($row->offered_at);
        $this->assertSame(1000, (int) $row->cpu_quota_m);
        $this->assertFalse($this->copied($acme, (string) $region->id), 'a pending share is not usable in the tenant');
        Event::assertDispatched(RegionShareOffered::class, fn ($e) => $e->tenant->is($acme) && $e->regionId === (string) $region->id);

        // Later saves of the platform region do not reach a pending share
        CentralSyncableRegion::query()->findOrFail($region->id)->triggerSyncEvent();
        $this->assertFalse($this->copied($acme, (string) $region->id));
        $this->assertSame([], CentralSyncableRegion::query()->findOrFail($region->id)->tenants()->pluck('tenants.id')->all());

        // The tenant's regions (platform administrator's view) carry the state
        $this->assertSame(TenantRegionShares::PENDING, $acme->regions()->firstOrFail()->pivot->status);

        TenantRegionShares::accept($acme, (string) $region->id, 'user-9');

        $row = TenantRegionShares::forTenant((string) $acme->id)->sole();
        $this->assertSame(TenantRegionShares::ACTIVE, $row->status);
        $this->assertSame('user-9', $row->accepted_by);
        $this->assertTrue($this->copied($acme, (string) $region->id));
        Event::assertDispatched(RegionShareAccepted::class, fn ($e) => $e->userId === 'user-9');

        // Accepting an active share again changes nothing
        TenantRegionShares::accept($acme, (string) $region->id, 'someone-else');
        $this->assertSame('user-9', TenantRegionShares::forTenant((string) $acme->id)->sole()->accepted_by);
        Event::assertDispatchedTimes(RegionShareAccepted::class, 1);
    }

    public function test_an_active_share_stays_active_when_it_is_assigned_again(): void
    {
        $region = $this->region();
        $acme = $this->createTenantWithSchema('acme');
        $this->assign($acme, $region);

        TenantRegionShares::requireAcceptanceWhen(fn () => true);
        $this->assign($acme, $region);

        $this->assertSame(TenantRegionShares::ACTIVE, TenantRegionShares::status((string) $acme->id, (string) $region->id));
        Event::assertNotDispatched(RegionShareOffered::class);
    }

    public function test_a_pending_share_becomes_active_when_acceptance_is_no_longer_required(): void
    {
        TenantRegionShares::requireAcceptanceWhen(fn () => true);
        $region = $this->region();
        $acme = $this->createTenantWithSchema('acme');
        $this->assign($acme, $region);

        TenantRegionShares::requireAcceptanceWhen(null);
        $this->assign($acme, $region);

        $this->assertSame(TenantRegionShares::ACTIVE, TenantRegionShares::status((string) $acme->id, (string) $region->id));
        $this->assertTrue($this->copied($acme, (string) $region->id));
        $this->assertNull(TenantRegionShares::status((string) $acme->id, 'not-shared'));
    }
}
