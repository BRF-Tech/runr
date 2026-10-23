<?php

namespace Tests\Feature;

use App\Jobs\ApplyInternalNetworkJob;
use App\Models\KnativeRegion;
use App\Services\InternalNetwork\InternalNetwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * The two switches of the internal network (docs/internal-network.md): a region's internal_auth
 * (enforce the allow lists — also unset — or audit them) and the platform's cross-organization
 * grants. Each reaches the region's authz at once (ApplyInternalNetworkJob).
 */
class InternalNetworkSettingsTest extends TestCase
{
    use ActsWithRoles;
    use RefreshDatabase;

    private function region(string $name = 'eu', array $settings = []): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => $name,
            'project_namespace' => "fn-{$name}",
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1',
                'kind' => 'Config',
                'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$name}.test:6443", 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'knative_serving_status' => 'ready',
            'knative_serving_namespace' => 'knative-serving',
            'knative_settings' => array_merge(['knative_domain' => "{$name}.runr.test"], $settings),
        ]);
    }

    public function test_every_region_enforces_and_audit_is_an_explicit_choice(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake([ApplyInternalNetworkJob::class]);
        // a region of a release before the internal network has no internal_auth: it enforces
        $region = $this->region();
        $this->assertSame(InternalNetwork::MODE_ENFORCE, InternalNetwork::modeOf($region));

        $this->putJson("/api/knative-regions/{$region->id}/settings", ['internal_auth' => 'enforce'])->assertOk();
        Bus::assertNotDispatched(ApplyInternalNetworkJob::class);

        // an operator who wants to look first
        $this->putJson("/api/knative-regions/{$region->id}/settings", ['internal_auth' => 'audit'])->assertOk();
        $this->assertSame(InternalNetwork::MODE_AUDIT, InternalNetwork::modeOf($region->fresh()));
        Bus::assertDispatched(ApplyInternalNetworkJob::class, fn ($job) => $job->region->is($region));

        // unchanged: nothing to apply
        $this->putJson("/api/knative-regions/{$region->id}/settings", ['internal_auth' => 'audit'])->assertOk();
        Bus::assertDispatchedTimes(ApplyInternalNetworkJob::class, 1);

        $this->putJson("/api/knative-regions/{$region->id}/settings", ['internal_auth' => 'enforce'])->assertOk();
        $this->assertSame(InternalNetwork::MODE_ENFORCE, InternalNetwork::modeOf($region->fresh()));
        Bus::assertDispatchedTimes(ApplyInternalNetworkJob::class, 2);

        $this->putJson("/api/knative-regions/{$region->id}/settings", ['internal_auth' => 'off'])->assertUnprocessable()->assertJsonValidationErrors('internal_auth');
    }

    public function test_the_platform_switch_reaches_every_region(): void
    {
        Bus::fake([ApplyInternalNetworkJob::class]);
        $this->region('eu');
        $this->region('us');

        $this->signedIn($this->platformAdmin());
        $this->patchJson('/api/settings', ['internal_cross_tenant_grants' => false])->assertOk();

        $this->assertFalse(InternalNetwork::crossTenantGrantsEnabled());
        Bus::assertDispatchedTimes(ApplyInternalNetworkJob::class, 2);

        $this->patchJson('/api/settings', ['internal_cross_tenant_grants' => true])->assertOk();
        $this->assertTrue(InternalNetwork::crossTenantGrantsEnabled());
    }
}
