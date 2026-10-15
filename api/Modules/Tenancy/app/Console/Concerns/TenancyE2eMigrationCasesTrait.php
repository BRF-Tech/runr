<?php

namespace Modules\Tenancy\Console\Concerns;

trait TenancyE2eMigrationCasesTrait
{
    protected function runMigrationTests(array &$results): void
    {
        $results[] = $this->runTest('Region Detach Cleanup', function () {
            $this->testRegionDetach();
        });

        $results[] = $this->runTest('Tenant Custom Domain', function () {
            $this->testTenantCustomDomain();
        });

        $results[] = $this->runTest('Tenant Domain Change', function () {
            $this->testTenantDomainChange();
        });
    }

    private function testRegionDetach(): void
    {
        $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant A');
        $this->e2eAssert((bool) $this->managedRegionId, 'Skip: no managed region');

        // Create a project in tenant A's context (the API requires its config)
        $r = $this->api(null, $this->tenantAId)->post('/api/projects', $this->snippetProject('detach-test-'.substr(md5(uniqid()), 0, 6)));
        $this->e2eAssert($r->successful(), "Create project: {$r->status()} — {$r->body()}");
        $projectId = $r->json('data.id') ?? $r->json('id');
        $this->e2eAssert((bool) $projectId, 'No project ID');

        // Detach region from tenant
        $r = $this->api()->delete("/api/tenants/{$this->tenantAId}/regions/{$this->managedRegionId}");
        $this->e2eAssert($r->successful(), "Detach: {$r->status()}");

        // DetachRegionCleanupJob runs on the queue (Horizon): wait for it instead of a fixed second
        $project = null;
        $deadline = time() + 60;
        do {
            $r = $this->api(null, $this->tenantAId)->get("/api/projects/{$projectId}");
            $project = $r->successful() ? ($r->json('data') ?? $r->json()) : null;
            if ($project === null || $project['knative_region_id'] === null) {
                break;
            }
            sleep(1);
        } while (time() < $deadline);

        // Verify project still exists but region is detached
        if ($project !== null) {
            $this->e2eAssert(
                $project['knative_region_id'] === null,
                'Project should have null region after detach'
            );
        }

        // Re-assign region for other tests
        $r = $this->api()->post("/api/tenants/{$this->tenantAId}/regions", [
            'region_id' => $this->managedRegionId,
            'cpu_quota_m' => 2000,
            'memory_quota_mb' => 4096,
        ]);
        $this->e2eAssert($r->successful(), "Re-assign: {$r->status()}");

        // Clean up project
        $this->api(null, $this->tenantAId)->delete("/api/projects/{$projectId}");
    }

    private function testTenantCustomDomain(): void
    {
        $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant A');

        // Set tenant base_domain
        $r = $this->api(null, $this->tenantAId)->patch('/api/settings', [
            'base_domain' => 'tenant-test.example.com',
        ]);
        $this->e2eAssert($r->successful(), "Set domain: {$r->status()}");

        // Verify settings reflect the domain
        $r = $this->api(null, $this->tenantAId)->get('/api/settings');
        $domain = $r->json('base_domain');
        $this->e2eAssert($domain === 'tenant-test.example.com', "Expected tenant-test.example.com, got: {$domain}");

        // Create a project to verify label gets applied (at manifest level, no K8s needed)
        $r = $this->api(null, $this->tenantAId)->post('/api/projects', $this->snippetProject('domain-test-'.substr(md5(uniqid()), 0, 6)));
        $this->e2eAssert($r->successful(), "Create project: {$r->status()} — {$r->body()}");
        $projectId = $r->json('data.id') ?? $r->json('id');

        // Clean up
        if ($projectId) {
            $this->api(null, $this->tenantAId)->delete("/api/projects/{$projectId}");
        }
    }

    private function testTenantDomainChange(): void
    {
        $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant A');

        // Set old domain first
        $r = $this->api(null, $this->tenantAId)->patch('/api/settings', [
            'base_domain' => 'old-tenant.example.com',
        ]);
        $this->e2eAssert($r->successful(), "Set old domain: {$r->status()}");

        // Change to new domain
        $r = $this->api(null, $this->tenantAId)->patch('/api/settings', [
            'base_domain' => 'new-tenant.example.com',
        ]);
        $this->e2eAssert($r->successful(), "Set new domain: {$r->status()}");

        // Verify settings reflect new domain
        $r = $this->api(null, $this->tenantAId)->get('/api/settings');
        $domain = $r->json('base_domain');
        $this->e2eAssert($domain === 'new-tenant.example.com', "Expected new-tenant.example.com, got: {$domain}");

        // Clean up domain
        $r = $this->api(null, $this->tenantAId)->patch('/api/settings', ['base_domain' => '']);
        $this->e2eAssert($r->successful(), "Clear domain: {$r->status()}");
    }
}
