<?php

namespace Tests\Feature\Metering;

use App\Models\BindingUsageRecord;
use App\Models\KnativeRegion;
use App\Models\UsageRecord;
use App\Services\Metering\UsageAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * A tenant's usage in platform regions is copied into the central tables with the tenant's id
 * (UsageAggregator::mirror): what the platform bills the tenant for stays readable centrally
 * (docs/billing.md). Usage in the tenant's own regions stays in its schema. Runs on SQLite and on
 * PostgreSQL (schema per tenant).
 */
class UsageMirrorTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private Tenant $tenant;

    private string $platformRegionId;

    private string $ownRegionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        $platform = KnativeRegion::create(['name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true]);
        $this->platformRegionId = (string) $platform->id;
        $this->ownRegionId = (string) Str::ulid();

        $this->tenant = $this->createTenantWithSchema('mirror'.Str::lower(Str::random(4)), [
            ...self::TENANT_REGION_MIGRATIONS,
            'Modules/Tenancy/Database/TenantMigrations/0035_create_bindings_tables.php',
            // binding_usage_records.created_by (0059 ran before the bindings tables existed)
            'Modules/Tenancy/Database/TenantMigrations/0059_add_created_by_to_usage_records.php',
        ]);

        $this->tenant->run(function () {
            $copy = new KnativeRegion(['name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true]);
            $copy->forceFill(['id' => $this->platformRegionId, 'is_synced' => true])->saveQuietly();
            $own = new KnativeRegion(['name' => 'own', 'project_namespace' => 'own', 'is_active' => true]);
            $own->forceFill(['id' => $this->ownRegionId, 'is_synced' => false])->saveQuietly();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_usage_in_platform_regions_is_copied_centrally_with_the_tenant_id(): void
    {
        [$shared, $own] = $this->tenant->run(function () {
            $shared = $this->usage($this->platformRegionId, 3_600_000_000);
            $own = $this->usage($this->ownRegionId, 1_000);
            BindingUsageRecord::create([
                'binding_id' => (string) Str::ulid(), 'binding_name' => 'cache', 'type' => 'kv', 'plan' => 'shared',
                'region_id' => $this->platformRegionId, 'record_date' => '2026-10-11', 'storage_gb_hours' => 2.5, 'created_by' => 'user-1',
            ]);

            $this->assertSame(2, app(UsageAggregator::class)->mirror('2026-10-11'));

            return [$shared->id, $own->id];
        });

        $central = DB::connection('central')->table('usage_records')->where('id', $shared)->first();
        $this->assertNotNull($central, 'the platform region usage is readable centrally');
        $this->assertSame((string) $this->tenant->id, $central->tenant_id);
        $this->assertSame(3_600_000_000, (int) $central->total_cpu_mc_ms);
        $this->assertSame('2026-10-11', substr((string) $central->record_date, 0, 10));
        $this->assertFalse(DB::connection('central')->table('usage_records')->where('id', $own)->exists(), "the tenant's own region stays in its schema");

        $binding = DB::connection('central')->table('binding_usage_records')->where('tenant_id', (string) $this->tenant->id)->first();
        $this->assertNotNull($binding);
        $this->assertSame('user-1', $binding->created_by);

        // Every run copies again: the central row follows the tenant's record, no duplicate
        $this->tenant->run(function () use ($shared) {
            UsageRecord::query()->whereKey($shared)->update(['total_cpu_mc_ms' => 7_200_000_000]);
            app(UsageAggregator::class)->mirror('2026-10-11');
        });
        $this->assertSame(1, DB::connection('central')->table('usage_records')->where('id', $shared)->count());
        $this->assertSame(7_200_000_000, (int) DB::connection('central')->table('usage_records')->where('id', $shared)->value('total_cpu_mc_ms'));
    }

    public function test_copies_of_records_the_tenant_no_longer_has_are_removed(): void
    {
        [$kept, $replaced, $earlier] = $this->tenant->run(function () {
            $kept = $this->usage($this->platformRegionId, 1_000);
            $replaced = $this->usage($this->platformRegionId, 9_000);
            $earlier = $this->usage($this->platformRegionId, 5_000, '2026-09-30');
            app(UsageAggregator::class)->mirror('2026-09-01', '2026-10-11');

            return [$kept->id, $replaced->id, $earlier->id];
        });
        $this->assertSame(3, DB::connection('central')->table('usage_records')->where('tenant_id', (string) $this->tenant->id)->count());

        // A recalculated day replaced a record in the tenant; the next run mirrors October only
        $this->tenant->run(function () use ($replaced) {
            UsageRecord::query()->whereKey($replaced)->delete();
            app(UsageAggregator::class)->mirror('2026-10-01', '2026-10-11');
        });

        $central = DB::connection('central')->table('usage_records')->where('tenant_id', (string) $this->tenant->id)->pluck('id')->all();
        sort($central);
        $expected = [$kept, $earlier];
        sort($expected);
        $this->assertSame($expected, $central, 'the vanished copy is gone; the month outside the run is left alone');
    }

    private function usage(string $regionId, int $cpuMcMs, string $date = '2026-10-11'): UsageRecord
    {
        return UsageRecord::create([
            'project_id' => (string) Str::ulid(), 'deployment_id' => (string) Str::ulid(), 'region_id' => $regionId,
            'record_date' => $date, 'scale_mode' => 'scale_to_zero', 'entry_point' => 'http',
            'session_count' => 3, 'total_cpu_mc_ms' => $cpuMcMs, 'total_memory_mib_ms' => 1_000, 'currency' => 'USD',
        ]);
    }
}
