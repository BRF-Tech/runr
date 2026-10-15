<?php

namespace Tests\Feature\Sandboxes;

use App\Models\KnativeRegion;
use App\Models\Sandbox;
use App\Models\SandboxPool;
use App\Models\TenantSecurityPolicy;
use App\Services\Residency\RegionPolicy;
use App\Services\Sandboxes\SandboxPoolService;

/**
 * Data residency (docs/supply-chain.md) for the Sandbox API: sandboxes and warm pools only in the
 * organization's allowed regions, the default region the first allowed one, pools in a region the
 * policy no longer allows drained.
 */
class SandboxResidencyTest extends SandboxTestCase
{
    private KnativeRegion $outside;

    protected function setUp(): void
    {
        parent::setUp();

        // Sorted first by name, but outside the organization's allowed regions
        $this->outside = $this->sandboxRegion(['name' => 'ap-1']);

        RegionPolicy::resolveTenantUsing(fn () => 'acme');
        TenantSecurityPolicy::create([
            'tenant_id' => 'acme',
            'residency_enforced' => true,
            'allowed_region_ids' => [$this->region->id],
        ]);
    }

    protected function tearDown(): void
    {
        RegionPolicy::resolveTenantUsing(null);
        parent::tearDown();
    }

    public function test_sandboxes_only_in_allowed_regions(): void
    {
        $this->postJson('/api/sandboxes', ['region' => 'ap-1'])->assertStatus(422);
        $this->assertSame(0, Sandbox::count());

        $data = $this->createSandbox();
        $this->assertSame('eu-1', $data['region']['name']);
    }

    public function test_the_default_region_is_the_first_allowed_one(): void
    {
        $data = $this->postJson('/api/sandboxes', [])->assertCreated()->json('data');

        $this->assertSame($this->region->id, $data['region']['id']);
    }

    public function test_pools_follow_the_residency_policy(): void
    {
        $this->postJson('/api/sandbox-pools', ['region' => 'ap-1', 'runtime' => 'python', 'size' => 1])->assertStatus(422);

        // A pool created before the policy covered its region is drained by the refill
        $pool = SandboxPool::create(['tenant_id' => null, 'knative_region_id' => $this->outside->id, 'runtime' => 'python', 'runtime_version' => '3.14', 'cpu_m' => 500, 'memory_mb' => 512, 'disk_mb' => 1024, 'size' => 1, 'is_enabled' => true]);
        $this->assertSame(0, app(SandboxPoolService::class)->refill($pool->id));
        $this->assertSame(0, Sandbox::where('pool_id', $pool->id)->count());
    }
}
