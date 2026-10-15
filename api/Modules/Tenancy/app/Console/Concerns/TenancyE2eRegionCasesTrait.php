<?php

namespace Modules\Tenancy\Console\Concerns;

trait TenancyE2eRegionCasesTrait
{
    protected function runRegionTests(array &$results): void
    {
        $results[] = $this->runTest('Region Setup', function () {
            $this->managedRegionId = $this->seedTestRegion('E2E Managed', 'managed', 4000, 8192);
            $this->e2eAssert((bool) $this->managedRegionId, 'Managed region not created');

            $this->infiniteRegionId = $this->seedTestRegion('E2E Infinite', 'infinite', 0, 0);
            $this->e2eAssert((bool) $this->infiniteRegionId, 'Infinite region not created');

            // Verify in admin settings → assignable_regions
            $r = $this->api()->get('/api/settings');
            $assignable = collect($r->json('assignable_regions') ?? []);
            $this->e2eAssert($assignable->contains('id', $this->managedRegionId), 'Managed not in assignable');
            $this->e2eAssert($assignable->contains('id', $this->infiniteRegionId), 'Infinite not in assignable');
        });

        $results[] = $this->runTest('Region Assignment', function () {
            $this->e2eAssert((bool) $this->tenantAId && (bool) $this->managedRegionId, 'Skip: prereq');

            // Assign managed region to A (2000 CPU, 4096 MB)
            $r = $this->api()->post("/api/tenants/{$this->tenantAId}/regions", [
                'region_id' => $this->managedRegionId, 'cpu_quota_m' => 2000, 'memory_quota_mb' => 4096,
            ]);
            $this->e2eAssert($r->successful(), "A managed: {$r->status()} — {$r->body()}");

            // Assign managed region to B (1000 CPU, 2048 MB)
            $r = $this->api()->post("/api/tenants/{$this->tenantBId}/regions", [
                'region_id' => $this->managedRegionId, 'cpu_quota_m' => 1000, 'memory_quota_mb' => 2048,
            ]);
            $this->e2eAssert($r->successful(), "B managed: {$r->status()}");

            // Assign infinite region to A (quota = 0 for infinite)
            $r = $this->api()->post("/api/tenants/{$this->tenantAId}/regions", [
                'region_id' => $this->infiniteRegionId, 'cpu_quota_m' => 0, 'memory_quota_mb' => 0,
            ]);
            $this->e2eAssert($r->successful(), "A infinite: {$r->status()}");

            // Verify A has 2 regions
            $r = $this->api()->get("/api/tenants/{$this->tenantAId}/regions");
            $this->e2eAssert(count($r->json()) === 2, 'A: expected 2 regions, got '.count($r->json()));

            // Verify B has 1 region
            $r = $this->api()->get("/api/tenants/{$this->tenantBId}/regions");
            $this->e2eAssert(count($r->json()) === 1, 'B: expected 1 region');
        });

        $results[] = $this->runTest('Region Visibility (tenant)', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: prereq');

            // Tenant A should see both regions in settings
            $r = $this->api(null, $this->tenantAId)->get('/api/settings');
            $regions = collect($r->json('regions') ?? []);

            $this->e2eAssert($regions->contains('id', $this->managedRegionId), 'A: managed not visible');
            $this->e2eAssert($regions->contains('id', $this->infiniteRegionId), 'A: infinite not visible');

            // Infinite region should be marked unlimited
            $inf = $regions->firstWhere('id', $this->infiniteRegionId);
            $this->e2eAssert(($inf['unlimited'] ?? false) === true, 'Infinite not marked unlimited');

            // Tenant B should see only managed region
            $r = $this->api(null, $this->tenantBId)->get('/api/settings');
            $regions = collect($r->json('regions') ?? []);
            $this->e2eAssert($regions->contains('id', $this->managedRegionId), 'B: managed not visible');
            $this->e2eAssert(! $regions->contains('id', $this->infiniteRegionId), 'B: should not see infinite');
        });

        $results[] = $this->runTest('Quota Update + Capacity', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: prereq');

            // Update A's quota on managed region
            $r = $this->api()->patch("/api/tenants/{$this->tenantAId}/regions/{$this->managedRegionId}", [
                'cpu_quota_m' => 2500, 'memory_quota_mb' => 5120,
            ]);
            $this->e2eAssert($r->successful(), "Update: {$r->status()}");

            $r = $this->api()->get("/api/tenants/{$this->tenantAId}/regions");
            $region = collect($r->json())->firstWhere('id', $this->managedRegionId);
            $this->e2eAssert((int) $region['cpu_quota_m'] === 2500, "CPU: {$region['cpu_quota_m']}");

            // Exceed capacity: A(2500) + B(1000) = 3500, total = 4000, try to add 600 more to B
            $r = $this->api()->patch("/api/tenants/{$this->tenantBId}/regions/{$this->managedRegionId}", [
                'cpu_quota_m' => 1600, 'memory_quota_mb' => 2048,
            ]);
            $this->e2eAssert($r->status() === 422, "Exceed: expected 422, got {$r->status()}");
        });

        $results[] = $this->runTest('Resource Usage', function () {
            $r = $this->api()->get('/api/resource-usage');
            $this->e2eAssert($r->successful(), "Usage: {$r->status()}");

            $data = $r->json();
            $this->e2eAssert(is_array($data), 'Response not array');
            // Resource usage returns region-level summaries
        });
    }
}
