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
use Tests\Concerns\FakesBindingOps;
use Tests\TestCase;

/**
 * Usage of bindings: hourly measurement into GB-hours / instance hours, size limits enforced
 * from the measurement, organization quotas and the creator the usage is billed to.
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

    public function test_the_usage_record_names_the_creator_who_is_billed(): void
    {
        // A binding belongs to no project: the Billing module bills its usage to its creator (docs/billing.md)
        $binding = $this->makeBinding('kv', $this->region, ['name' => 'cache']);
        $binding->forceFill(['created_by' => 'user-7', 'usage_measured_at' => Carbon::parse('2026-10-11 07:00:00')])->save();

        app(BindingUsageMeter::class)->record($binding, self::GIB, 0, Carbon::parse('2026-10-11 08:00:00'));

        $record = BindingUsageRecord::firstOrFail();
        $this->assertSame('user-7', $record->created_by);
        $this->assertEquals(0, (float) $record->final_cost, 'quantities only — the Billing module prices them');
    }
}
