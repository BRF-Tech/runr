<?php

namespace Tests\Feature\Sandboxes;

use App\Jobs\Sandboxes\MeterSandboxUsageJob;
use App\Models\Sandbox;
use App\Models\SandboxUsageRecord;
use App\Services\Sandboxes\SandboxUsageAggregator;
use Illuminate\Support\Carbon;

/**
 * Usage metering: run time of every sandbox per organization × region × day (sandboxes that
 * span midnight split between the days, warm pods only from their claim), reserved resources ×
 * time, re-aggregation, cost without the Tenancy module, invoice lines and the usage endpoint.
 */
class SandboxUsageMeteringTest extends SandboxTestCase
{
    public function test_a_day_is_metered_per_organization_and_region(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));

        // 1 h this morning, 500m / 512 MiB
        $this->runningSandbox(['status' => Sandbox::STATUS_TERMINATED, 'started_at' => Carbon::parse('2026-10-15 08:00:00'), 'ended_at' => Carbon::parse('2026-10-15 09:00:00')]);
        // Since yesterday 23:00, still running, 1000m / 1024 MiB: 12 h today
        $this->runningSandbox(['cpu_m' => 1000, 'memory_mb' => 1024, 'started_at' => Carbon::parse('2026-10-14 23:00:00')]);
        // Another organization
        $this->runningSandbox(['tenant_id' => 'tenant-b', 'started_at' => Carbon::parse('2026-10-15 11:00:00')]);
        // A warm pod (never claimed): not metered
        $this->runningSandbox(['status' => Sandbox::STATUS_WARM, 'started_at' => null]);

        $written = app(SandboxUsageAggregator::class)->aggregateDay(Carbon::parse('2026-10-15'));
        $this->assertSame(2, $written);

        $mine = SandboxUsageRecord::whereNull('tenant_id')->sole();
        $this->assertSame('2026-10-15', $mine->record_date->toDateString());
        $this->assertSame($this->region->id, $mine->knative_region_id);
        $this->assertSame(2, $mine->sandbox_count);
        $this->assertSame(3600 + 12 * 3600, $mine->sandbox_seconds);
        $this->assertSame(500 * 3_600_000 + 1000 * 43_200_000, $mine->cpu_mc_ms);
        $this->assertSame(512 * 3_600_000 + 1024 * 43_200_000, $mine->memory_mib_ms);
        $this->assertSame('USD', $mine->currency);
        $this->assertSame(0.0, (float) $mine->final_cost);

        $other = SandboxUsageRecord::where('tenant_id', 'tenant-b')->sole();
        $this->assertSame(3600, $other->sandbox_seconds);

        // Yesterday got the hour before midnight
        app(SandboxUsageAggregator::class)->aggregateDay(Carbon::parse('2026-10-14'));
        $this->assertSame(3600, SandboxUsageRecord::whereNull('tenant_id')->whereDate('record_date', '2026-10-14')->sole()->sandbox_seconds);
    }

    public function test_re_aggregation_updates_the_same_record(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'UTC'));
        $this->runningSandbox(['started_at' => Carbon::parse('2026-10-15 09:00:00')]);

        app(SandboxUsageAggregator::class)->aggregateRecent();
        $this->travelTo(Carbon::parse('2026-10-15 11:00:00', 'UTC'));
        app(SandboxUsageAggregator::class)->aggregateRecent();

        $this->assertSame(1, SandboxUsageRecord::count());
        $this->assertSame(7200, SandboxUsageRecord::sole()->sandbox_seconds);
    }

    public function test_the_first_hour_of_a_day_also_finishes_yesterday(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 23:30:00', 'UTC'));
        $this->runningSandbox(['started_at' => Carbon::parse('2026-10-15 23:00:00')]);
        app(SandboxUsageAggregator::class)->aggregateRecent();

        $this->travelTo(Carbon::parse('2026-10-16 00:10:00', 'UTC'));
        (new MeterSandboxUsageJob)->handle(app(SandboxUsageAggregator::class));

        $this->assertSame(3600, SandboxUsageRecord::whereDate('record_date', '2026-10-15')->sole()->sandbox_seconds);
        $this->assertSame(600, SandboxUsageRecord::whereDate('record_date', '2026-10-16')->sole()->sandbox_seconds);
    }

    public function test_charge_items_currencies_and_cost_since(): void
    {
        SandboxUsageRecord::create(['tenant_id' => 't1', 'knative_region_id' => $this->region->id, 'record_date' => '2026-10-01', 'sandbox_count' => 3, 'sandbox_seconds' => 5400, 'final_cost' => 1.25, 'raw_cost' => 1.25, 'currency' => 'USD']);
        SandboxUsageRecord::create(['tenant_id' => 't1', 'knative_region_id' => $this->region->id, 'record_date' => '2026-10-02', 'sandbox_count' => 1, 'sandbox_seconds' => 1800, 'final_cost' => 0.75, 'raw_cost' => 0.75, 'currency' => 'USD']);
        SandboxUsageRecord::create(['tenant_id' => 't1', 'knative_region_id' => $this->region->id, 'record_date' => '2026-10-03', 'sandbox_seconds' => 100, 'final_cost' => 9, 'currency' => 'EUR']);
        SandboxUsageRecord::create(['tenant_id' => 't2', 'knative_region_id' => $this->region->id, 'record_date' => '2026-10-02', 'sandbox_seconds' => 100, 'final_cost' => 5, 'currency' => 'USD']);

        $items = SandboxUsageAggregator::chargeItems('t1', '2026-10-01', '2026-10-31', 'USD');

        $this->assertCount(1, $items);
        $this->assertSame('Sandboxes (eu-1)', $items[0]['description']);
        $this->assertSame(2.0, $items[0]['quantity']);
        $this->assertSame('hours', $items[0]['unit']);
        $this->assertSame(2.0, $items[0]['total']);
        $this->assertSame(4, $items[0]['metadata']['sandbox_count']);

        $this->assertSame('sandbox', $items[0]['metadata']['source']);

        $this->assertSame([], SandboxUsageAggregator::chargeItems('t1', '2026-09-01', '2026-09-30', 'USD'));
        $this->assertEqualsCanonicalizing(['USD', 'EUR'], SandboxUsageAggregator::currencies('t1', '2026-10-01', '2026-10-31'));
        $this->assertSame([], SandboxUsageAggregator::currencies('t1', '2026-11-01', '2026-11-30'));
        // Billing summary: every record of the organization since the date (0.75 USD + 9 EUR)
        $this->assertEqualsWithDelta(9.75, SandboxUsageAggregator::costSince('t1', '2026-10-02'), 0.0001);
    }

    public function test_usage_endpoint(): void
    {
        SandboxUsageRecord::create(['tenant_id' => null, 'knative_region_id' => $this->region->id, 'record_date' => now()->toDateString(), 'sandbox_count' => 2, 'sandbox_seconds' => 600, 'cpu_mc_ms' => 10, 'memory_mib_ms' => 20, 'currency' => 'USD']);
        SandboxUsageRecord::create(['tenant_id' => 'someone-else', 'knative_region_id' => $this->region->id, 'record_date' => now()->toDateString(), 'sandbox_seconds' => 999, 'currency' => 'USD']);

        $this->getJson('/api/sandbox-usage')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sandbox_seconds', 600)
            ->assertJsonPath('data.0.sandbox_count', 2)
            ->assertJsonPath('data.0.region_id', $this->region->id);
    }

    public function test_overlap(): void
    {
        $day = Carbon::parse('2026-10-15 00:00:00', 'UTC');
        $end = $day->copy()->addDay();

        $this->assertSame(3600, SandboxUsageAggregator::overlapSeconds(Carbon::parse('2026-10-14 23:00:00'), Carbon::parse('2026-10-15 01:00:00'), $day, $end));
        $this->assertSame(0, SandboxUsageAggregator::overlapSeconds(Carbon::parse('2026-10-13 10:00:00'), Carbon::parse('2026-10-13 11:00:00'), $day, $end));
        $this->assertSame(86400, SandboxUsageAggregator::overlapSeconds(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-30'), $day, $end));
    }
}
