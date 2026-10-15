<?php

namespace Tests\Feature\Sandboxes;

use App\Models\Sandbox;
use App\Models\SandboxPool;
use App\Models\SandboxQuota;
use App\Models\SandboxUsageRecord;
use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\SandboxQuotaService;
use App\Services\Sandboxes\SandboxReaper;
use Illuminate\Support\Carbon;

/**
 * Organization quotas: concurrency, CPU, memory, sandbox seconds per month, pool size; the
 * defaults row and organization rows over config; month accounting (metered days + today's run
 * time); the reaper stopping running sandboxes once the month's budget is used.
 */
class SandboxQuotaTest extends SandboxTestCase
{
    public function test_concurrency_limit(): void
    {
        SandboxQuota::create(['tenant_id' => null, 'max_concurrent' => 2]);

        $this->createSandbox();
        $this->createSandbox();
        $this->postJson('/api/sandboxes', ['region' => $this->region->id])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'quota_concurrent')
            ->assertJsonPath('limits.max_concurrent', 2)
            ->assertJsonPath('usage.concurrent', 2);

        // Ended sandboxes free their slot
        $first = Sandbox::orderBy('created_at')->first();
        $this->deleteJson("/api/sandboxes/{$first->id}")->assertOk();
        $this->createSandbox();
    }

    public function test_cpu_and_memory_limits(): void
    {
        SandboxQuota::create(['tenant_id' => null, 'max_cpu_m' => 1500, 'max_memory_mb' => 1024]);

        $this->createSandbox(['cpu' => '1']);
        $this->postJson('/api/sandboxes', ['region' => $this->region->id, 'cpu' => '1'])
            ->assertStatus(422)->assertJsonPath('reason', 'quota_cpu');
        $this->postJson('/api/sandboxes', ['region' => $this->region->id, 'cpu' => '500m', 'memory' => 1024])
            ->assertStatus(422)->assertJsonPath('reason', 'quota_memory');
        $this->createSandbox(['cpu' => '500m', 'memory' => 512]);
    }

    public function test_monthly_sandbox_seconds(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        SandboxQuota::create(['tenant_id' => null, 'max_sandbox_seconds_month' => 3600]);

        $usage = fn (string $date, int $seconds) => SandboxUsageRecord::create([
            'tenant_id' => null, 'knative_region_id' => $this->region->id, 'record_date' => $date, 'sandbox_seconds' => $seconds,
        ]);
        $usage('2026-10-01', 1000);
        $usage('2026-10-14', 1000);
        $usage('2026-09-30', 50000); // last month
        $usage('2026-10-15', 99999); // today comes from the sandboxes themselves

        $quotas = app(SandboxQuotaService::class);
        $this->assertSame(2000, $quotas->secondsThisMonth(null));

        // A sandbox running since 20 minutes, one that ran 10 minutes this morning
        $this->runningSandbox(['started_at' => now()->subMinutes(20)]);
        $this->runningSandbox(['started_at' => now()->subHours(3), 'ended_at' => now()->subHours(3)->addMinutes(10), 'status' => Sandbox::STATUS_TERMINATED]);
        $this->assertSame(2000 + 1200 + 600, $quotas->secondsThisMonth(null));

        $this->postJson('/api/sandboxes', ['region' => $this->region->id])
            ->assertStatus(422)->assertJsonPath('reason', 'quota_seconds');
    }

    public function test_the_reaper_stops_running_sandboxes_when_the_month_is_used_up(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        SandboxQuota::create(['tenant_id' => null, 'max_sandbox_seconds_month' => 600]);
        $row = $this->runningSandbox(['started_at' => now()->subMinutes(30), 'last_activity_at' => now()]);

        app(SandboxReaper::class)->quota();

        $row->refresh();
        $this->assertSame('terminated', $row->status);
        $this->assertSame('quota', $row->end_reason);
        $this->assertNull($this->podOf($row->id));
    }

    public function test_limits_fall_back_from_organization_to_defaults_to_config(): void
    {
        config(['sandbox.quota.max_concurrent' => 7, 'sandbox.quota.max_cpu_m' => 9000]);
        $quotas = app(SandboxQuotaService::class);

        $this->assertSame(7, $quotas->limits('t1')['max_concurrent']);
        $this->assertNull($quotas->limits('t1')['allowed_classes']);

        SandboxQuota::create(['tenant_id' => null, 'max_concurrent' => 5, 'allowed_classes' => ['gvisor']]);
        $this->assertSame(5, $quotas->limits('t1')['max_concurrent']);
        $this->assertSame(9000, $quotas->limits('t1')['max_cpu_m']);
        $this->assertSame(['gvisor'], $quotas->limits('t1')['allowed_classes']);

        SandboxQuota::create(['tenant_id' => 't1', 'max_concurrent' => 50, 'allowed_classes' => ['kata']]);
        $this->assertSame(50, $quotas->limits('t1')['max_concurrent']);
        $this->assertSame(9000, $quotas->limits('t1')['max_cpu_m']);
        $this->assertSame(['kata'], $quotas->limits('t1')['allowed_classes']);
        $this->assertSame(5, $quotas->limits('t2')['max_concurrent']);
        $this->assertSame(5, $quotas->limits(null)['max_concurrent']);
    }

    public function test_zero_means_unlimited(): void
    {
        SandboxQuota::create(['tenant_id' => null, 'max_concurrent' => 0, 'max_cpu_m' => 0, 'max_memory_mb' => 0]);

        for ($i = 0; $i < 3; $i++) {
            $this->createSandbox(['cpu' => '4', 'memory' => 8192]);
        }
        $this->assertSame(3, Sandbox::count());
    }

    public function test_organization_allowed_classes_apply_to_new_sandboxes(): void
    {
        SandboxQuota::create(['tenant_id' => null, 'allowed_classes' => ['gvisor']]);

        $this->assertSame('gvisor', $this->createSandbox()['sandbox_class']);
        $this->postJson('/api/sandboxes', ['region' => $this->region->id, 'sandbox_class' => 'kata'])
            ->assertStatus(422)->assertJsonPath('reason', 'class_unavailable');
    }

    public function test_pool_size_quota(): void
    {
        SandboxQuota::create(['tenant_id' => null, 'max_pool_size' => 3]);
        SandboxPool::create(['tenant_id' => null, 'knative_region_id' => $this->region->id, 'runtime' => 'python', 'runtime_version' => '3.14', 'cpu_m' => 500, 'memory_mb' => 512, 'disk_mb' => 1024, 'size' => 2, 'is_enabled' => false]);

        $quotas = app(SandboxQuotaService::class);
        $quotas->checkPoolSize(null, 3);

        $this->expectException(SandboxException::class);
        $quotas->checkPoolSize(null, 4);
    }

    public function test_quota_endpoint_shows_limits_and_usage(): void
    {
        SandboxQuota::create(['tenant_id' => null, 'max_concurrent' => 4]);
        $this->createSandbox(['cpu' => '250m', 'memory' => 256]);

        $this->getJson('/api/sandbox-quotas')->assertOk()
            ->assertJsonPath('data.tenant_id', null)
            ->assertJsonPath('data.limits.max_concurrent', 4)
            ->assertJsonPath('data.usage.concurrent', 1)
            ->assertJsonPath('data.usage.cpu_m', 250)
            ->assertJsonPath('data.usage.memory_mb', 256);
    }

    public function test_region_reservation_includes_kata_overhead_and_warm_pods(): void
    {
        $this->runningSandbox(['sandbox_class' => 'kata-clh', 'cpu_m' => 500, 'memory_mb' => 512]);
        $this->runningSandbox(['sandbox_class' => 'gvisor', 'cpu_m' => 250, 'memory_mb' => 256, 'status' => Sandbox::STATUS_WARM]);
        $this->runningSandbox(['sandbox_class' => 'gvisor', 'cpu_m' => 1000, 'memory_mb' => 1024, 'status' => Sandbox::STATUS_TERMINATED]);

        $this->assertSame(
            ['cpu_m' => 500 + 250 + 250, 'memory_mb' => 512 + 256 + 256],
            app(SandboxQuotaService::class)->regionReservation(null, $this->region->id),
        );
    }
}
