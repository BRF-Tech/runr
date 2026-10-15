<?php

namespace Tests\Feature\Sandboxes;

use App\Models\SandboxQuota;
use App\Models\SandboxRegionSetting;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\WiresTenancy;

/**
 * Settings endpoints of the Sandbox API: region settings (classes, runc opt-in, TTL and size
 * ceilings), organization quotas (defaults row on a single-tenant install), Prometheus metrics,
 * and the interim abilities on single- and multi-tenant installs.
 */
class SandboxAdminApiTest extends SandboxTestCase
{
    use ActsWithRoles, WiresTenancy;

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_regions_list_their_sandbox_classes(): void
    {
        $data = $this->getJson('/api/sandbox-regions')->assertOk()->json('data.0');

        $this->assertSame(['id' => $this->region->id, 'name' => 'eu-1'], $data['region']);
        $this->assertTrue($data['enabled']);
        $this->assertSame(['kata-fc', 'kata-clh', 'gvisor'], $data['installed_classes']);
        $this->assertSame(['kata-fc', 'kata-clh', 'gvisor'], $data['effective_classes']);
        $this->assertNull($data['allowed_classes']);
        $this->assertSame('kata-fc', $data['effective_default_class']);
        $this->assertFalse($data['allow_runc']);
        $this->assertSame(900, $data['default_ttl_seconds']);
        $this->assertSame(86400, $data['max_ttl_seconds']);
        $this->assertTrue($data['can_manage']);
    }

    public function test_regions_without_a_cluster_are_not_listed(): void
    {
        $this->sandboxRegion(['name' => 'no-cluster', 'kubeconfig' => null]);
        $this->sandboxRegion(['name' => 'off', 'is_active' => false]);

        $this->assertSame(['eu-1'], collect($this->getJson('/api/sandbox-regions')->json('data'))->pluck('region.name')->all());
    }

    public function test_update_region_settings(): void
    {
        $data = $this->putJson("/api/sandbox-regions/{$this->region->id}", [
            'allowed_classes' => ['gvisor', 'kata-clh'],
            'default_class' => 'gvisor',
            'allow_runc' => true,
            'default_ttl_seconds' => 300,
            'max_ttl_seconds' => 7200,
            'max_cpu_m' => 2000,
        ])->assertOk()->json('data');

        $this->assertSame(['gvisor', 'kata-clh'], $data['allowed_classes']);
        $this->assertSame(['kata-clh', 'gvisor', 'runc'], $data['effective_classes']);
        $this->assertSame('gvisor', $data['effective_default_class']);
        $this->assertSame(300, $data['default_ttl_seconds']);
        $this->assertSame(7200, $data['max_ttl_seconds']);
        $this->assertSame(2000, $data['max_cpu_m']);

        // New sandboxes follow the settings
        $sandbox = $this->createSandbox();
        $this->assertSame('gvisor', $sandbox['sandbox_class']);
        $this->assertSame(300, $sandbox['ttl_seconds']);
        $this->postJson('/api/sandboxes', ['region' => $this->region->id, 'cpu' => '3'])->assertStatus(422)->assertJsonValidationErrors('cpu');
        $this->postJson('/api/sandboxes', ['region' => $this->region->id, 'sandbox_class' => 'kata-fc'])->assertStatus(422)->assertJsonPath('reason', 'class_unavailable');
    }

    public function test_region_settings_validation(): void
    {
        $this->putJson("/api/sandbox-regions/{$this->region->id}", ['allowed_classes' => ['docker']])->assertStatus(422);
        $this->putJson("/api/sandbox-regions/{$this->region->id}", ['default_class' => 'runc'])->assertStatus(422)->assertJsonValidationErrors('default_class');
        $this->putJson("/api/sandbox-regions/{$this->region->id}", ['max_ttl_seconds' => 999999])->assertStatus(422);
        $this->putJson('/api/sandbox-regions/01JB0000000000000000000000', ['enabled' => false])->assertNotFound();
        $this->assertNull(SandboxRegionSetting::find($this->region->id));
    }

    public function test_quota_admin_on_a_single_tenant_install(): void
    {
        $rows = $this->getJson('/api/sandbox-quotas/tenants')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['tenant']);
        $this->assertFalse($rows[0]['custom']);

        $this->putJson('/api/sandbox-quotas/default', ['max_concurrent' => 3, 'allowed_classes' => ['gvisor']])->assertOk()
            ->assertJsonPath('data.limits.max_concurrent', 3)
            ->assertJsonPath('data.limits.allowed_classes', ['gvisor']);
        $this->assertSame(3, SandboxQuota::whereNull('tenant_id')->sole()->max_concurrent);
        $this->getJson('/api/sandbox-quotas/tenants')->assertJsonPath('data.0.custom', true);

        $this->putJson('/api/sandbox-quotas/default', ['max_concurrent' => -1])->assertStatus(422);
        // Organizations exist only with tenancy
        $this->putJson('/api/sandbox-quotas/some-tenant', ['max_concurrent' => 1])->assertNotFound();

        $this->deleteJson('/api/sandbox-quotas/default')->assertNoContent();
        $this->assertSame(0, SandboxQuota::count());
    }

    public function test_prometheus_metrics(): void
    {
        $this->createSandbox();

        $response = $this->get('/api/sandbox-metrics/prometheus')->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('# TYPE runr_sandbox_start_seconds histogram', $response->getContent());
        $this->assertStringContainsString('runr_sandboxes{region="'.$this->region->id.'",status="running"} 1', $response->getContent());
    }

    public function test_metrics_window_validation(): void
    {
        $this->getJson('/api/sandbox-metrics?window=1y')->assertStatus(422);
        $this->getJson('/api/sandbox-metrics?window=7d')->assertOk()->assertJsonPath('data.window', '7d');
    }

    public function test_single_tenant_users_hold_every_sandbox_ability(): void
    {
        foreach (['sandboxes.view', 'sandboxes.create', 'sandboxes.exec', 'sandboxes.manage'] as $ability) {
            $this->assertTrue(Gate::forUser($this->user)->allows($ability), $ability);
        }
    }

    public function test_multi_tenant_abilities(): void
    {
        config(['tenancy.enabled' => true]);
        $member = $this->sandboxUser();
        $admin = $this->sandboxUser(true);

        // Outside an organization a member holds nothing; a platform admin everything
        foreach (['sandboxes.view', 'sandboxes.create', 'sandboxes.exec', 'sandboxes.manage'] as $ability) {
            $this->assertFalse(Gate::forUser($member)->allows($ability), $ability);
            $this->assertTrue(Gate::forUser($admin)->allows($ability), $ability);
        }

        $this->actingAs($member, 'sanctum');
        $this->getJson('/api/sandboxes')->assertForbidden();
        $this->postJson('/api/sandboxes', [])->assertForbidden();

        // Platform-level quota settings: platform admins only
        $this->actingAs($admin, 'sanctum');
        $this->getJson('/api/sandboxes')->assertOk();
    }

    public function test_platform_settings_need_a_platform_admin_with_tenancy(): void
    {
        $this->setUpTenancy(); // the platform quota view lists the organizations (central tenants table)
        config(['tenancy.enabled' => true]);
        $admin = $this->sandboxUser(true);
        $this->actingAs($admin, 'sanctum');

        $this->get('/api/sandbox-metrics/prometheus')->assertOk();
        $this->getJson('/api/sandbox-quotas/tenants')->assertOk();

        $this->actingAs($this->sandboxUser(), 'sanctum');
        $this->getJson('/api/sandbox-metrics/prometheus')->assertForbidden();
        $this->putJson('/api/sandbox-quotas/default', ['max_concurrent' => 1])->assertForbidden();
    }

    public function test_viewers_see_sandboxes_but_cannot_create_or_exec(): void
    {
        $row = $this->runningSandbox();
        $this->signedIn($this->userWithRole('viewer'));

        $this->getJson('/api/sandboxes')->assertOk();
        $this->getJson("/api/sandboxes/{$row->id}")->assertOk();
        $this->postJson('/api/sandboxes', [])->assertForbidden()->assertJsonPath('permissions', ['sandboxes.create']);
        $this->postJson("/api/sandboxes/{$row->id}/exec", ['cmd' => 'id'])->assertForbidden();
        $this->putJson("/api/sandbox-regions/{$this->region->id}", ['enabled' => false])->assertForbidden();
    }

    public function test_developers_create_and_exec_but_do_not_manage(): void
    {
        $this->signedIn($this->userWithRole('developer'));

        $data = $this->createSandbox();
        $this->postJson("/api/sandboxes/{$data['id']}/exec", ['cmd' => 'id'])->assertOk();
        $this->postJson('/api/sandbox-pools', ['region' => $this->region->id, 'runtime' => 'python', 'size' => 1])->assertForbidden();
    }

    public function test_scoped_api_tokens_carry_only_their_sandbox_permissions(): void
    {
        $token = $this->user->createToken('agent', ['sandboxes.view'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/sandboxes')->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/sandboxes', [])->assertForbidden();
    }
}
