<?php

namespace Tests\Feature\Bindings;

use App\Jobs\Bindings\BindingsMaintenanceJob;
use App\Models\Binding;
use App\Models\BindingUsageRecord;
use App\Models\KnativeRegion;
use App\Services\Bindings\BindingQuotas;
use App\Services\Bindings\BindingTenancy;
use App\Services\Bindings\BindingUsageMeter;
use App\Services\Bindings\Kv\RedisScripts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenancy\Models\CostConfig;
use Modules\Tenancy\Services\Billing\ChargeLineItemBuilder;
use Modules\Tenancy\Services\Bindings\BindingUsagePricing;
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * Usage of bindings: hourly measurement into GB-hours / instance hours, size limits enforced
 * from the measurement, organization quotas, pricing with the CostConfig and charge lines.
 */
class BindingUsageTest extends TestCase
{
    use FakesBindingOps, RefreshDatabase;

    private const GIB = 1024 ** 3;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBindingFakes();

        $this->region = $this->bindingRegion();
        foreach (['kv', 'object', 'postgres'] as $service) {
            $this->readyService($this->region, $service);
        }
    }

    protected function tearDown(): void
    {
        BindingTenancy::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function measure(): void
    {
        app()->call([new BindingsMaintenanceJob('measure'), 'handle']);
    }

    // ── Measurement → usage records ─────────────────────────────────────────────────────

    public function test_gb_hours_are_booked_per_day_with_a_two_hour_cap(): void
    {
        $meter = app(BindingUsageMeter::class);
        $binding = $this->makeBinding('object', $this->region, ['name' => 'assets']);
        $binding->forceFill(['usage_measured_at' => Carbon::parse('2026-10-11 07:00:00')])->save();

        $meter->record($binding, 2 * self::GIB, 0, Carbon::parse('2026-10-11 08:00:00'));
        $binding->save();

        $record = BindingUsageRecord::firstOrFail();
        $this->assertSame('2026-10-11', $record->record_date->toDateString());
        $this->assertEquals(2.0, (float) $record->storage_gb_hours);
        $this->assertSame(2 * self::GIB, (int) $record->peak_bytes);
        $this->assertSame('assets', $record->binding_name);
        $this->assertSame('object', $record->type);
        $this->assertSame($this->region->id, $record->region_id);
        $this->assertSame(2 * self::GIB, $binding->fresh()->usage_bytes);

        // Five hours later: at most two hours are booked (a gap is not billed backwards)
        $meter->record($binding, 1 * self::GIB, 0, Carbon::parse('2026-10-11 13:00:00'));
        $binding->save();

        $this->assertSame(1, BindingUsageRecord::count());
        $this->assertEquals(4.0, (float) BindingUsageRecord::firstOrFail()->storage_gb_hours);
        $this->assertSame(2 * self::GIB, (int) BindingUsageRecord::firstOrFail()->peak_bytes, 'peak kept');

        // Next day: a new record
        $meter->record($binding, self::GIB, 0, Carbon::parse('2026-10-12 00:30:00'));
        $binding->save();
        $this->assertSame(2, BindingUsageRecord::count());
    }

    public function test_the_first_measurement_counts_from_the_creation(): void
    {
        $binding = $this->makeBinding('object', $this->region, ['name' => 'assets']);
        $binding->forceFill(['created_at' => Carbon::parse('2026-10-11 07:30:00'), 'usage_measured_at' => null])->save();

        app(BindingUsageMeter::class)->record($binding->fresh(), 4 * self::GIB, 0, Carbon::parse('2026-10-11 08:00:00'));

        $this->assertEquals(2.0, (float) BindingUsageRecord::firstOrFail()->storage_gb_hours);
    }

    public function test_the_measure_job_records_each_type(): void
    {
        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $object = $this->makeBinding('object', $this->region, ['name' => 'assets']);
        $shared = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $dedicated = $this->makeBinding('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated']);
        $this->makeBinding('external', null, ['name' => 'stripe']);
        foreach ([$kv, $object, $shared, $dedicated] as $binding) {
            $binding->forceFill(['usage_measured_at' => now()->subHour()])->save();
        }
        $this->readyInstances = 2;

        $this->usageBytes = [
            $kv->id => 8 * 1024 * 1024,
            'bucket-'.$object->resource_name => 3 * self::GIB,
            $shared->pgName() => self::GIB,
            $dedicated->pgName() => 2 * self::GIB,
        ];

        $this->measure();

        $this->assertSame(8 * 1024 * 1024, $kv->fresh()->usage_bytes);
        $this->assertSame(3 * self::GIB, $object->fresh()->usage_bytes);
        $this->assertSame(self::GIB, $shared->fresh()->usage_bytes);
        $this->assertSame(2 * self::GIB, $dedicated->fresh()->usage_bytes);

        $records = BindingUsageRecord::all()->keyBy('binding_id');
        $this->assertCount(4, $records, 'external bindings are not measured');
        $this->assertEqualsWithDelta(3.0, (float) $records[$object->id]->storage_gb_hours, 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $records[$dedicated->id]->instance_hours, 0.01, 'two running instances for an hour');
        $this->assertEquals(0.0, (float) $records[$shared->id]->instance_hours);

        // One round-trip per region and type
        $this->assertCount(1, $this->opsTasks('kv-usage'));
        $this->assertStringContainsString("{$kv->id} 0 {$kv->resource_name}:*", $this->opsTask('kv-usage')->files['targets']);
        $this->assertCount(2, $this->opsTasks('postgres-usage'), 'shared cluster + the dedicated one');
        $this->assertSame('/v2/GetBucketInfo', $this->garageCallTo('/v2/GetBucketInfo')['path']);
    }

    public function test_a_failing_measurement_of_one_type_does_not_stop_the_others(): void
    {
        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $shared = $this->makeBinding('postgres', $this->region, ['name' => 'orders']);
        $this->opsResponses['kv-usage'] = fn () => throw new \RuntimeException('region unreachable');
        $this->usageBytes[$shared->pgName()] = 1234;

        $this->measure();

        $this->assertSame(0, $kv->fresh()->usage_bytes);
        $this->assertSame(1234, $shared->fresh()->usage_bytes);
    }

    public function test_kv_over_its_memory_limit_is_read_only_until_it_is_below_again(): void
    {
        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache', 'settings' => ['memory_mb' => 1]]);

        $this->usageBytes[$kv->id] = 2 * 1024 * 1024;
        $this->measure();

        $kv->refresh();
        $this->assertTrue($kv->setting('over_quota'));
        $this->assertSame(__('api.bindings.over_quota'), $kv->status_message);
        $rule = explode("\n", $this->opsTask('kv-quota')->files['commands.txt'])[0];
        $this->assertStringStartsWith("ACL SETUSER {$kv->resource_name} reset on >kv-pass-1", $rule);
        $this->assertStringEndsWith(RedisScripts::READ_ONLY, $rule);

        // Still over: no further ACL change
        $this->measure();
        $this->assertCount(1, $this->opsTasks('kv-quota'));

        $this->usageBytes[$kv->id] = 1000;
        $this->measure();

        $kv->refresh();
        $this->assertFalse($kv->setting('over_quota'));
        $this->assertNull($kv->status_message);
        $this->assertCount(2, $this->opsTasks('kv-quota'));
        $this->assertStringNotContainsString('-@write', $this->opsTask('kv-quota')->files['commands.txt']);
    }

    public function test_shared_postgres_over_its_storage_limit_gets_read_only_sessions(): void
    {
        $shared = $this->makeBinding('postgres', $this->region, ['name' => 'orders', 'settings' => ['storage_gb' => 1]]);

        $this->usageBytes[$shared->pgName()] = 2 * self::GIB;
        $this->measure();

        $this->assertTrue($shared->fresh()->setting('over_quota'));
        $this->assertSame('ALTER DATABASE "'.$shared->pgName()."\" SET default_transaction_read_only = on;\n", $this->opsTask('postgres-quota')->files['script.sql']);

        $this->usageBytes[$shared->pgName()] = 100;
        $this->measure();

        $this->assertFalse($shared->fresh()->setting('over_quota'));
        $this->assertSame('ALTER DATABASE "'.$shared->pgName()."\" RESET default_transaction_read_only;\n", $this->opsTask('postgres-quota')->files['script.sql']);
    }

    public function test_a_dedicated_cluster_being_deleted_has_no_instance_hours(): void
    {
        $dedicated = $this->makeBinding('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated', 'status' => Binding::STATUS_DELETING]);
        $dedicated->forceFill(['usage_measured_at' => now()->subHour()])->save();

        $this->measure();

        $this->assertEquals(0.0, (float) BindingUsageRecord::firstOrFail()->instance_hours);
        $this->assertSame([], $this->opsTasks('postgres-usage'), 'hibernated: nothing to query');
    }

    // ── Quotas ──────────────────────────────────────────────────────────────────────────

    public function test_quota_claims_limits_and_overrides(): void
    {
        $this->assertSame(['max_bindings' => 1, 'kv_mb' => 64], BindingQuotas::claim('kv', 'shared', []));
        $this->assertSame(['max_bindings' => 1, 'object_gb' => 9], BindingQuotas::claim('object', 'shared', ['quota_gb' => 9]));
        $this->assertSame(['max_bindings' => 1, 'postgres_gb' => 1], BindingQuotas::claim('postgres', 'shared', []));
        $this->assertSame(
            ['max_bindings' => 1, 'postgres_gb' => 30, 'dedicated_postgres' => 1],
            BindingQuotas::claim('postgres', 'dedicated', ['instances' => 3, 'storage_gb' => 10]),
        );
        $this->assertSame(['max_bindings' => 1], BindingQuotas::claim('external', 'external', []));

        config(['bindings.quotas' => ['max_bindings' => 20, 'kv_mb' => 1024, 'object_gb' => 100, 'postgres_gb' => 50, 'dedicated_postgres' => 2]]);
        BindingTenancy::setQuotaResolver(fn () => ['kv_mb' => 100, 'object_gb' => null, 'dedicated_postgres' => 0]);

        $quotas = app(BindingQuotas::class);
        $this->assertSame(['max_bindings' => 20, 'kv_mb' => 100, 'object_gb' => 100, 'postgres_gb' => 50, 'dedicated_postgres' => 0], $quotas->limits());

        $kv = $this->makeBinding('kv', $this->region, ['name' => 'cache', 'settings' => ['memory_mb' => 80]]);
        $this->makeBinding('postgres', $this->region, ['name' => 'ledger', 'plan' => 'dedicated', 'settings' => ['instances' => 2, 'storage_gb' => 5]]);
        $this->makeBinding('kv', $this->region, ['name' => 'gone', 'status' => Binding::STATUS_PURGING]);

        $used = $quotas->used();
        $this->assertSame(2, $used['max_bindings'], 'purging bindings no longer count');
        $this->assertSame(80, $used['kv_mb']);
        $this->assertSame(10, $used['postgres_gb']);
        $this->assertSame(1, $used['dedicated_postgres']);
        $this->assertSame(0, $quotas->used($kv->id)['kv_mb']);

        $this->assertSame(['key' => 'kv_mb', 'limit' => 100, 'used' => 80, 'requested' => 64], $quotas->exceeded('kv', 'shared', []));
        $this->assertNull($quotas->exceeded('kv', 'shared', ['memory_mb' => 100], $kv->id), 'resizing itself');
        $this->assertNull($quotas->exceeded('postgres', 'dedicated', []), '0 = unlimited');

        $kv->forceFill(['usage_bytes' => 5000])->save();
        $summary = $quotas->summary();
        $this->assertSame(5000, $summary['measured_bytes']['kv']);
        $this->assertSame(100, $summary['limits']['kv_mb']);
    }

    // ── Pricing / charges (Tenancy) ─────────────────────────────────────────────────────

    private function costTables(): void
    {
        config(['database.connections.central' => config('database.connections.'.config('database.default'))]);
        DB::connection('central')->setPdo(DB::connection()->getPdo());

        foreach ([
            'Modules/Tenancy/Database/Migrations/2026_03_14_000001_create_cost_configs_table.php',
            'Modules/Tenancy/Database/Migrations/2026_03_18_000001_add_monthly_prices_to_cost_configs.php',
            'Modules/Tenancy/Database/Migrations/2026_10_10_500002_add_binding_prices_to_cost_configs.php',
        ] as $migration) {
            (require base_path($migration))->up();
        }
    }

    private function usageRecord(array $attributes): BindingUsageRecord
    {
        return BindingUsageRecord::create(array_merge([
            'binding_id' => (string) Str::ulid(), 'binding_name' => 'x', 'type' => 'kv', 'plan' => 'shared',
            'region_id' => $this->region->id, 'record_date' => now()->toDateString(),
        ], $attributes));
    }

    public function test_usage_is_priced_with_the_cost_config(): void
    {
        $this->costTables();
        CostConfig::create([
            'region_id' => null, 'currency' => 'EUR', 'is_active' => true,
            'kv_price_per_gb_hour' => 0.1, 'object_price_per_gb_hour' => 0.002,
            'postgres_price_per_gb_hour' => 0.01, 'postgres_instance_price_per_hour' => 0.5,
        ]);
        $other = $this->bindingRegion('other');
        CostConfig::create(['region_id' => $other->id, 'currency' => 'TRY', 'is_active' => true, 'kv_price_per_gb_hour' => 2]);

        $ledger = $this->usageRecord(['binding_name' => 'ledger', 'type' => 'postgres', 'plan' => 'dedicated', 'storage_gb_hours' => 10, 'instance_hours' => 2]);
        $assets = $this->usageRecord(['type' => 'object', 'storage_gb_hours' => 5, 'record_date' => now()->subDay()->toDateString()]);
        $cache = $this->usageRecord(['type' => 'kv', 'storage_gb_hours' => 1.5, 'region_id' => $other->id]);
        $old = $this->usageRecord(['type' => 'kv', 'storage_gb_hours' => 1, 'record_date' => now()->subDays(3)->toDateString()]);

        $priced = app(BindingUsagePricing::class)->priceDate(now()->toDateString());

        $this->assertSame(3, $priced);
        $this->assertEqualsWithDelta(1.1, (float) $ledger->fresh()->final_cost, 0.000001);
        $this->assertEqualsWithDelta(1.1, (float) $ledger->fresh()->raw_cost, 0.000001);
        $this->assertSame('EUR', $ledger->fresh()->currency);
        $this->assertEqualsWithDelta(0.01, (float) $assets->fresh()->final_cost, 0.000001, 'yesterday is priced too');
        $this->assertEqualsWithDelta(3.0, (float) $cache->fresh()->final_cost, 0.000001);
        $this->assertSame('TRY', $cache->fresh()->currency, 'the region config wins');
        $this->assertEquals(0, (float) $old->fresh()->final_cost);
    }

    public function test_usage_charges_get_one_line_per_binding(): void
    {
        $usage = collect([
            new BindingUsageRecord(['binding_id' => 'b1', 'binding_name' => 'ledger', 'type' => 'postgres', 'plan' => 'dedicated', 'region_id' => 'r1', 'storage_gb_hours' => 10, 'instance_hours' => 2, 'final_cost' => 1.1, 'currency' => 'EUR']),
            new BindingUsageRecord(['binding_id' => 'b1', 'binding_name' => 'ledger', 'type' => 'postgres', 'plan' => 'dedicated', 'region_id' => 'r1', 'storage_gb_hours' => 4, 'instance_hours' => 1, 'final_cost' => 0.54, 'currency' => 'EUR']),
            new BindingUsageRecord(['binding_id' => 'b2', 'binding_name' => 'cache', 'type' => 'kv', 'plan' => 'shared', 'region_id' => 'r1', 'storage_gb_hours' => 0.01, 'final_cost' => 0.001, 'currency' => 'EUR']),
            new BindingUsageRecord(['binding_id' => 'b3', 'binding_name' => 'pg-only', 'type' => 'postgres', 'plan' => 'dedicated', 'region_id' => 'r1', 'storage_gb_hours' => 0, 'instance_hours' => 24, 'final_cost' => 12, 'currency' => 'EUR']),
            new BindingUsageRecord(['binding_id' => 'b4', 'binding_name' => 'foreign', 'type' => 'object', 'plan' => 'shared', 'region_id' => 'r1', 'storage_gb_hours' => 100, 'final_cost' => 5, 'currency' => 'USD']),
        ]);

        $items = (new ChargeLineItemBuilder)->usageItems('EUR', collect(), [], $usage);

        $this->assertSame(['Binding: ledger (Postgres, dedicated)', 'Binding: pg-only (Postgres, dedicated)'], array_column($items, 'description'));

        $ledger = $items[0];
        $this->assertEquals(14.0, $ledger['quantity']);
        $this->assertSame('GB-hours', $ledger['unit']);
        $this->assertEquals(1.64, $ledger['total']);
        $this->assertSame(['source' => 'binding', 'binding_id' => 'b1', 'type' => 'postgres', 'plan' => 'dedicated', 'region_id' => 'r1', 'storage_gb_hours' => 14.0, 'instance_hours' => 3.0], $ledger['metadata']);

        $this->assertSame('instance-hours', $items[1]['unit']);
        $this->assertEquals(24.0, $items[1]['quantity']);
        $this->assertEquals(0.5, $items[1]['unit_price']);
    }
}
