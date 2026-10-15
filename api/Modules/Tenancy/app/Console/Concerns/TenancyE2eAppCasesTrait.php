<?php

namespace Modules\Tenancy\Console\Concerns;

use Modules\Tenancy\Models\Tenant;

trait TenancyE2eAppCasesTrait
{
    protected function runAppTests(array &$results): void
    {
        $results[] = $this->runTest('Setting Fallback', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: prereq');
            $r = $this->api(null, $this->tenantAId)->get('/api/settings');
            $this->e2eAssert($r->successful(), "Get: {$r->status()}");
            $bd = $r->json('base_domain');
            $this->e2eAssert($bd === 'runr.sh', "Fallback: expected 'runr.sh', got '{$bd}'");
        });

        $results[] = $this->runTest('Setting Override + Isolation', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: prereq');

            // Override in A
            $r = $this->api(null, $this->tenantAId)
                ->patch('/api/settings', ['base_domain' => 'alpha.custom.com']);
            $this->e2eAssert($r->successful(), "Override: {$r->status()}");

            $r = $this->api(null, $this->tenantAId)->get('/api/settings');
            $bd = $r->json('base_domain');
            $this->e2eAssert($bd === 'alpha.custom.com', "A: expected 'alpha.custom.com', got '{$bd}'");

            // B should still see central value
            $r = $this->api(null, $this->tenantBId)->get('/api/settings');
            $bd = $r->json('base_domain');
            $this->e2eAssert($bd === 'runr.sh', "B isolation: expected 'runr.sh', got '{$bd}'");
        });

        $results[] = $this->runTest('Project CRUD (tenant)', function () {
            $this->e2eAssert((bool) $this->tenantAId && (bool) $this->managedRegionId, 'Skip: prereq');

            $r = $this->api(null, $this->tenantAId)->post('/api/projects', [
                'name' => 'E2E Test Project',
                'service_type' => 'xaas',
                'runtime' => 'python',
                'runtime_version' => '3.12',
                'knative_region_id' => $this->managedRegionId,
                'config' => [
                    'timeout_seconds' => 30,
                    'cpu' => '250m',
                    'memory_mb' => 128,
                ],
            ]);
            $this->e2eAssert($r->status() === 201, "Create: {$r->status()} — {$r->body()}");
            $this->projectId = $r->json('id');

            // List projects in A
            $r = $this->api(null, $this->tenantAId)->get('/api/projects');
            $projects = collect($r->json('data') ?? $r->json());
            $this->e2eAssert($projects->contains('id', $this->projectId), 'Project not in A list');
        });

        $results[] = $this->runTest('Project Isolation', function () {
            $this->e2eAssert((bool) $this->tenantBId && (bool) $this->projectId, 'Skip: prereq');

            // B should NOT see A's project
            $r = $this->api(null, $this->tenantBId)->get('/api/projects');
            $projects = collect($r->json('data') ?? $r->json());
            $this->e2eAssert(! $projects->contains('id', $this->projectId), 'Project leaked to B');
        });

        $results[] = $this->runTest('Code Save + Version', function () {
            $this->e2eAssert((bool) $this->projectId, 'Skip: no project');

            $code = "def handler(event, context):\n    return {\"statusCode\": 200, \"body\": \"ok\"}";
            $r = $this->api(null, $this->tenantAId)
                ->post("/api/projects/{$this->projectId}/code", ['code' => $code]);
            $this->e2eAssert($r->successful(), "Save: {$r->status()}");

            $r = $this->api(null, $this->tenantAId)->get("/api/projects/{$this->projectId}/versions");
            $versions = $r->json('data') ?? $r->json();
            $this->e2eAssert(count($versions) >= 1, 'No versions created');
        });

        $results[] = $this->runTest('Notification Endpoints', function () {
            // Unread count
            $r = $this->api(null, $this->tenantAId)->get('/api/notifications/unread-count');
            $this->e2eAssert($r->successful(), "Count: {$r->status()}");

            // List
            $r = $this->api(null, $this->tenantAId)->get('/api/notifications');
            $this->e2eAssert($r->successful(), "List: {$r->status()}");

            // Read all (works even if empty)
            $r = $this->api(null, $this->tenantAId)->post('/api/notifications/read-all');
            $this->e2eAssert($r->successful(), "ReadAll: {$r->status()}");
        });

        $results[] = $this->runTest('Auth Endpoints', function () {
            // me endpoint
            $r = $this->api(null, $this->tenantAId)->get('/api/auth/me');
            $this->e2eAssert($r->successful(), "Me: {$r->status()}");
            $this->e2eAssert((bool) $r->json('name'), 'No user name in response');

            // Login returns token
            $r = $this->publicApi()->post('/api/auth/login', [
                'login' => 'test', 'password' => 'test123',
            ]);
            $this->e2eAssert($r->successful() && (bool) $r->json('token'), "Login: {$r->status()}");
        });
    }

    protected function runCleanupTests(array &$results): void
    {
        $results[] = $this->runTest('User Removal + Schema Cleanup', function () {
            $this->e2eAssert((bool) $this->tenantAId && (bool) $this->testUserId, 'Skip: prereq');

            $r = $this->api()->delete("/api/tenants/{$this->tenantAId}/users/{$this->testUserId}");
            $this->e2eAssert($r->successful(), "Remove A: {$r->status()}");

            // Verify user removed from schema
            $tenant = Tenant::find($this->tenantAId);
            $exists = true;
            $tenant->run(function () use (&$exists) {
                $exists = \DB::table('users')->where('id', $this->testUserId)->exists();
            });
            $this->e2eAssert(! $exists, 'User still in A schema');
        });

        $results[] = $this->runTest('Region Removal', function () {
            $this->e2eAssert((bool) $this->tenantBId && (bool) $this->managedRegionId, 'Skip: prereq');

            $r = $this->api()->delete("/api/tenants/{$this->tenantBId}/regions/{$this->managedRegionId}");
            $this->e2eAssert($r->successful(), "Remove: {$r->status()}");

            $r = $this->api()->get("/api/tenants/{$this->tenantBId}/regions");
            $this->e2eAssert(count($r->json()) === 0, 'Region still assigned to B');
        });

        $results[] = $this->runTest('Tenant Delete', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: prereq');

            $ids = [$this->tenantAId, $this->tenantBId];
            foreach (['B' => $this->tenantBId, 'A' => $this->tenantAId] as $label => $id) {
                $r = $this->api()->delete("/api/tenants/{$id}");
                $this->e2eAssert($r->successful(), "Delete {$label}: {$r->status()}");
            }
            $tenants = $this->waitForTenantDeletions($ids);

            // The test regions carry a dummy kubeconfig: without a cluster to confirm the
            // teardown, deletion keeps the tenant (delete_failed, with the reason) instead of
            // leaving its resources behind; a forced deletion removes it anyway
            $kept = $tenants->whereIn('id', $ids);
            foreach ($kept as $tenant) {
                $this->e2eAssert($tenant['status'] === 'delete_failed', "{$tenant['slug']} kept as {$tenant['status']}");
                $this->e2eAssert(trim((string) ($tenant['status_message'] ?? '')) !== '', "{$tenant['slug']}: no reason for keeping it");
                $r = $this->api()->delete("/api/tenants/{$tenant['id']}?force=1");
                $this->e2eAssert($r->successful(), "Force delete {$tenant['slug']}: {$r->status()}");
            }
            if ($kept->isNotEmpty()) {
                $tenants = $this->waitForTenantDeletions($ids);
            }

            $remaining = $tenants->pluck('id');
            $this->e2eAssert(! $remaining->contains($this->tenantAId), 'A still in list');
            $this->e2eAssert(! $remaining->contains($this->tenantBId), 'B still in list');
        });
    }
}
