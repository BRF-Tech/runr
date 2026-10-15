<?php

namespace Tests\Feature\Sandboxes;

use App\Models\Sandbox;
use App\Models\SandboxPool;
use App\Services\Sandboxes\SandboxPoolService;
use Illuminate\Support\Facades\Queue;

/**
 * Sandboxes and warm pool pods hold the region's always-on capacity budget (RegionCapacity,
 * docs/sandbox-api.md → Quotas and usage): size + the pod overhead of their class, no overcommit.
 * The test region runs kata-fc (250m / 320 MiB overhead).
 */
class SandboxRegionCapacityTest extends SandboxTestCase
{
    private function pool(int $size): SandboxPool
    {
        return SandboxPool::create([
            'tenant_id' => null, 'knative_region_id' => $this->region->id, 'runtime' => 'python', 'runtime_version' => '3.14',
            'cpu_m' => 500, 'memory_mb' => 512, 'disk_mb' => 1024, 'size' => $size, 'is_enabled' => true,
        ]);
    }

    public function test_a_new_sandbox_needs_room_on_the_always_on_budget(): void
    {
        $this->region->update(['total_cpu_m' => 2000]);

        $this->createSandbox(['cpu' => '1']); // 1000m + 250m Kata overhead

        $this->postJson('/api/sandboxes', ['region' => $this->region->id, 'cpu' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'quota_capacity')
            ->assertJsonPath('errors.cpu.0', 'CPU limit exceeded on the always-on budget (no overcommit): this needs 1250m, 750m of the region capacity 2000m are free. Always-on workloads, Kata pod overhead, queue consumers, sandboxes and model services reserve real capacity.');
        $this->assertSame(1, Sandbox::count());

        // 500m + 250m fits exactly; an ended sandbox frees its share
        $second = $this->createSandbox(['cpu' => '500m']);
        $this->postJson('/api/sandboxes', ['region' => $this->region->id, 'cpu' => '100m'])->assertStatus(422);
        $this->deleteJson("/api/sandboxes/{$second['id']}")->assertOk();
        $this->createSandbox(['cpu' => '100m']);
    }

    public function test_a_pool_stops_starting_warm_pods_at_the_region_capacity(): void
    {
        $this->region->update(['total_cpu_m' => 2000]);
        $pool = $this->pool(3);

        // 500m + 250m each: two fit in 2000m, the third would need 2250m
        $this->assertSame(2, app(SandboxPoolService::class)->refill());
        $this->assertSame(2, Sandbox::where('pool_id', $pool->id)->count());
        $this->assertStringContainsString('always-on budget', (string) $pool->fresh()->last_error);
    }

    public function test_a_claimed_warm_pod_is_not_admitted_twice(): void
    {
        Queue::fake();
        $this->region->update(['total_cpu_m' => 1000]);
        $pool = $this->pool(1);
        app(SandboxPoolService::class)->refill();
        app(SandboxPoolService::class)->refill();
        $warm = Sandbox::where('pool_id', $pool->id)->sole();

        // the warm pod holds 750m of 1000m: a new pod would not fit, the claim does
        $data = $this->createSandbox(['runtime' => 'python']);
        $this->assertSame($warm->id, $data['id']);
        $this->assertTrue($data['from_pool']);
    }
}
