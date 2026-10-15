<?php

namespace Modules\Tenancy\Console\Concerns;

use App\Models\User;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantInvitation;

trait TenancyE2eTenantCasesTrait
{
    protected function runTenantTests(array &$results): void
    {
        $results[] = $this->runTest('Tenant CRUD', function () {
            $r = $this->api()->post('/api/tenants', [
                'name' => 'Alpha', 'slug' => 'alpha',
            ]);
            $this->e2eAssert($r->status() === 201, "Create A: {$r->status()} — {$r->body()}");
            $this->tenantAId = $r->json('id');

            $r = $this->api()->post('/api/tenants', [
                'name' => 'Beta', 'slug' => 'beta',
            ]);
            $this->e2eAssert($r->status() === 201, "Create B: {$r->status()}");
            $this->tenantBId = $r->json('id');

            $r = $this->api()->patch("/api/tenants/{$this->tenantAId}", ['name' => 'Alpha Updated']);
            $this->e2eAssert($r->successful() && $r->json('name') === 'Alpha Updated', "Update: {$r->status()}");

            $r = $this->api()->get('/api/tenants');
            $ids = collect($r->json())->pluck('id');
            $this->e2eAssert($ids->contains($this->tenantAId) && $ids->contains($this->tenantBId), 'Missing in list');
        });

        $results[] = $this->runTest('Access Denied (non-member)', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant');
            $r = $this->api($this->userToken, $this->tenantAId)->get('/api/settings');
            $this->e2eAssert($r->status() === 403, "Expected 403, got {$r->status()}");
        });

        $results[] = $this->runTest('User Assignment', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant');

            $r = $this->api()->post("/api/tenants/{$this->tenantAId}/users", [
                'email' => 'test@example.com', 'role' => 'developer',
            ]);
            $this->e2eAssert($r->status() === 201, "Add to A: {$r->status()} — {$r->body()}");

            $r = $this->api()->post("/api/tenants/{$this->tenantBId}/users", [
                'email' => 'test@example.com', 'role' => 'viewer',
            ]);
            $this->e2eAssert($r->status() === 201, "Add to B: {$r->status()}");

            $r = $this->api()->get("/api/tenants/{$this->tenantAId}/users");
            $users = collect($r->json('users') ?? []);
            $testUser = $users->firstWhere('email', 'test@example.com');
            $this->e2eAssert((bool) $testUser, 'User not in A');
            $this->e2eAssert($testUser['role'] === 'developer', "Role: {$testUser['role']}");
            $this->testUserId = $testUser['id'];
        });

        $results[] = $this->runTest('User Sync to Schema', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant');

            $central = User::on('central')->where('email', 'test@example.com')->first();
            $this->e2eAssert((bool) $central, 'Test user not in central');

            foreach (['A' => $this->tenantAId, 'B' => $this->tenantBId] as $label => $id) {
                $tenant = Tenant::find($id);
                $synced = false;
                $tenant->run(function () use ($central, &$synced) {
                    $synced = \DB::table('users')->where('id', $central->id)->exists();
                });
                $this->e2eAssert($synced, "User not synced to tenant {$label}");
            }
        });

        $results[] = $this->runTest('Access Granted (member)', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant');
            $r = $this->api($this->userToken, $this->tenantAId)->get('/api/settings');
            $this->e2eAssert($r->successful(), "A: {$r->status()}");
            $r = $this->api($this->userToken, $this->tenantBId)->get('/api/settings');
            $this->e2eAssert($r->successful(), "B: {$r->status()}");
        });

        $results[] = $this->runTest('Invitation Flow', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant');

            // Send invitation to non-existent email
            $r = $this->api()->post("/api/tenants/{$this->tenantAId}/users", [
                'email' => 'invite@e2e.test', 'role' => 'developer',
            ]);
            $this->e2eAssert($r->status() === 201 && $r->json('action') === 'invited', "Send: {$r->status()}");

            // Verify invitation in user list
            $r = $this->api()->get("/api/tenants/{$this->tenantAId}/users");
            $invitations = collect($r->json('invitations') ?? []);
            $inv = $invitations->firstWhere('email', 'invite@e2e.test');
            $this->e2eAssert((bool) $inv, 'Invitation not in list');

            // Only the token's hash is stored: give the e2e invitation a token it knows
            $stored = TenantInvitation::where('email', 'invite@e2e.test')->value('token');
            $this->e2eAssert((bool) preg_match('/^[0-9a-f]{64}$/', (string) $stored), 'Invitation token is not stored hashed');
            [$token, $hash] = TenantInvitation::newToken();
            TenantInvitation::where('email', 'invite@e2e.test')->update(['token' => $hash]);

            // Preview (public endpoint — no auth)
            $r = $this->publicApi()->get("/api/invitations/{$token}");
            $this->e2eAssert($r->successful(), "Preview: {$r->status()}");
            $r = $this->publicApi()->get("/api/invitations/{$hash}");
            $this->e2eAssert($r->status() === 404, "The stored hash is no token: {$r->status()}");

            // Resend
            $r = $this->api()->post("/api/tenants/{$this->tenantAId}/invitations/{$inv['id']}/resend");
            $this->e2eAssert($r->successful(), "Resend: {$r->status()}");

            // Cancel
            $r = $this->api()->delete("/api/tenants/{$this->tenantAId}/invitations/{$inv['id']}");
            $this->e2eAssert($r->successful(), "Cancel: {$r->status()}");
        });

        $results[] = $this->runTest('Central User Management', function () {
            $r = $this->api()->get('/api/users');
            $this->e2eAssert($r->successful(), "List: {$r->status()}");

            $testUser = User::on('central')->where('email', 'test@example.com')->first();
            $this->e2eAssert((bool) $testUser, 'Test user not found');
            $this->e2eAssert(! $testUser->is_admin, 'User already admin');

            // Toggle on
            $r = $this->api()->post("/api/users/{$testUser->id}/toggle-admin");
            $this->e2eAssert($r->successful(), "On: {$r->status()}");
            $testUser->refresh();
            $this->e2eAssert((bool) $testUser->is_admin, 'Not toggled on');

            // Toggle off (restore)
            $r = $this->api()->post("/api/users/{$testUser->id}/toggle-admin");
            $this->e2eAssert($r->successful(), "Off: {$r->status()}");
            $testUser->refresh();
            $this->e2eAssert(! $testUser->is_admin, 'Not toggled off');
        });

        $results[] = $this->runTest('Tenant Switch', function () {
            $this->e2eAssert((bool) $this->tenantAId, 'Skip: no tenant');
            $r = $this->api($this->userToken)
                ->post('/api/tenant/switch', ['tenant_id' => $this->tenantAId]);
            $this->e2eAssert($r->successful(), "Switch: {$r->status()}");

            $r = $this->api($this->userToken, $this->tenantAId)->get('/api/tenant/current');
            $this->e2eAssert($r->json('tenant.id') === $this->tenantAId, 'Current mismatch');
        });
    }
}
