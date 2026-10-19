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
 * time, re-aggregation, one record per creator (quantities only, billed to the creator) and the
 * usage endpoint.
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

    public function test_each_creator_gets_a_record_of_their_own(): void
    {
        // Sandboxes belong to no project: the Billing module bills them to their creator (docs/billing.md)
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        $this->runningSandbox(['started_at' => Carbon::parse('2026-10-15 10:00:00')]);
        $this->runningSandbox(['created_by' => 'someone-else', 'started_at' => Carbon::parse('2026-10-15 11:00:00')]);

        $this->assertSame(2, app(SandboxUsageAggregator::class)->aggregateDay(Carbon::parse('2026-10-15')));

        $this->assertSame(7200, SandboxUsageRecord::where('created_by', (string) $this->user->id)->sole()->sandbox_seconds);
        $this->assertSame(3600, SandboxUsageRecord::where('created_by', 'someone-else')->sole()->sandbox_seconds);
    }

    public function test_the_day_is_replaced_including_rows_of_earlier_releases(): void
    {
        // A row of 0.5.7 (per organization and region, without creator) for the day being metered
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        SandboxUsageRecord::create(['tenant_id' => null, 'knative_region_id' => $this->region->id, 'record_date' => '2026-10-15', 'sandbox_seconds' => 999, 'final_cost' => 1.5, 'currency' => 'USD']);
        $this->runningSandbox(['started_at' => Carbon::parse('2026-10-15 11:00:00')]);

        app(SandboxUsageAggregator::class)->aggregateDay(Carbon::parse('2026-10-15'));

        $row = SandboxUsageRecord::whereDate('record_date', '2026-10-15')->sole();
        $this->assertSame(3600, $row->sandbox_seconds);
        $this->assertSame((string) $this->user->id, $row->created_by);
        $this->assertSame(0.0, (float) $row->final_cost, 'quantities only — the Billing module prices them');
    }

    public function test_usage_endpoint(): void
    {
        SandboxUsageRecord::create(['tenant_id' => null, 'knative_region_id' => $this->region->id, 'record_date' => now()->toDateString(), 'sandbox_count' => 2, 'sandbox_seconds' => 600, 'cpu_mc_ms' => 10, 'memory_mib_ms' => 20, 'final_cost' => 1.5, 'currency' => 'USD']);
        SandboxUsageRecord::create(['tenant_id' => 'someone-else', 'knative_region_id' => $this->region->id, 'record_date' => now()->toDateString(), 'sandbox_seconds' => 999, 'currency' => 'USD']);

        $this->getJson('/api/sandbox-usage')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sandbox_seconds', 600)
            ->assertJsonPath('data.0.sandbox_count', 2)
            ->assertJsonPath('data.0.region_id', $this->region->id)
            ->assertJsonPath('data.0.cpu_mc_ms', 10)
            ->assertJsonPath('data.0.memory_mib_ms', 20)
            // Quantities only: a cost of an earlier release is not answered (the Billing module prices usage)
            ->assertJsonMissingPath('data.0.final_cost')
            ->assertJsonMissingPath('data.0.raw_cost')
            ->assertJsonMissingPath('data.0.currency');
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
