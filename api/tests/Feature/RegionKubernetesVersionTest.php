<?php

namespace Tests\Feature;

use App\Jobs\InstallRegistryAndCreateRegionJob;
use App\Models\KnativeRegion;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KnativeNodeService;
use App\Services\KnativeRedisService;
use App\Services\KnativeStatusService;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * A region records its cluster's Kubernetes version (knative_regions.kubernetes_version): at the
 * kubeconfig check of its creation (POST /knative-regions, and the setup wizard's
 * POST /registry-setup/start whose job creates it), and from region:reconcile, which reads the
 * version of every cluster it reaches. It picks the kubectl minor of `kubectl exec` (KubectlBinary).
 */
class RegionKubernetesVersionTest extends TestCase
{
    use ActsWithRoles, BuildsAiFixtures, RefreshDatabase;

    private function region(string $name, ?string $version): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => $name, 'project_namespace' => $name, 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_operator_status' => 'ready', 'knative_serving_status' => 'ready', 'kubernetes_version' => $version,
        ]);
    }

    /** region:reconcile's reconciler without its steps: only what it does before them */
    private function reconciler(): RegionReconciler
    {
        return new class(app(RunrClusterIdentity::class)) extends RegionReconciler
        {
            public function steps(): array
            {
                return [];
            }
        };
    }

    public function test_a_new_region_records_the_version_its_cluster_answered(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake();
        $this->fakeCluster(['GET /version' => ['major' => '1', 'minor' => '34', 'gitVersion' => 'v1.34.2']]);

        $this->postJson('/api/knative-regions', ['name' => 'eu-2', 'kubeconfig' => $this->fakeKubeconfig(), 'nodes' => []])->assertCreated();

        $this->assertSame('v1.34.2', KnativeRegion::query()->where('name', 'eu-2')->sole()->kubernetes_version);
    }

    public function test_a_region_of_the_setup_wizard_records_the_version_its_cluster_answered(): void
    {
        $this->signedIn($this->userWithRole('owner'));
        Bus::fake();
        $this->fakeCluster(['GET /version' => ['major' => '1', 'minor' => '37', 'gitVersion' => 'v1.37.0']]);

        // POST /registry-setup/start: the kubeconfig check reads the version, the job creates the region
        $this->postJson('/api/registry-setup/start', [
            'name' => 'eu-2', 'kubeconfig' => $this->fakeKubeconfig(), 'nodes' => [],
            'registry_mode' => 'external', 'external_address' => 'registry.example.test',
        ])->assertStatus(202);

        $job = null;
        Bus::assertDispatched(InstallRegistryAndCreateRegionJob::class, function (InstallRegistryAndCreateRegionJob $dispatched) use (&$job) {
            $job = $dispatched;

            return true;
        });
        $config = (fn () => $this->config)->call($job);
        $this->assertSame('v1.37.0', $config['kubernetes_version']);

        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('testExternalRegistry')->andReturn(['success' => true, 'message' => 'ok']);
        $status = Mockery::mock(KnativeStatusService::class);
        $status->shouldReceive('createProjectNamespace')->andReturnNull();
        $redis = Mockery::mock(KnativeRedisService::class);
        $redis->shouldReceive('queueInitialInstall')->andReturnNull();
        $this->app->instance(KnativeRedisService::class, $redis);
        $job->handle($zot, $status, Mockery::mock(KnativeNodeService::class));

        $this->assertSame('v1.37.0', KnativeRegion::query()->where('name', 'eu-2')->sole()->kubernetes_version);
    }

    public function test_region_reconcile_records_the_version_of_an_upgraded_cluster_on_each_of_its_regions(): void
    {
        $this->fakeCluster(['GET /version' => ['major' => '1', 'minor' => '35', 'gitVersion' => 'v1.35.4']]);
        $eu1 = $this->region('eu-1', 'v1.34.2');
        $eu2 = $this->region('eu-2', null);
        $current = $this->region('eu-3', 'v1.35.4');

        $lines = [];
        $out = function (string $level, string $message) use (&$lines) {
            $lines[] = "{$level} {$message}";
        };

        $this->reconciler()->reconcile(null, true, $out);
        $this->assertSame('v1.34.2', $eu1->fresh()->kubernetes_version, 'a dry run writes nothing');
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_contains($l, '[kubernetes-version] eu-1') && str_contains($l, 'would record Kubernetes v1.34.2 → v1.35.4')));

        $lines = [];
        $this->reconciler()->reconcile(null, false, $out);

        $this->assertSame('v1.35.4', $eu1->fresh()->kubernetes_version);
        $this->assertSame('v1.35.4', $eu2->fresh()->kubernetes_version, 'a region of an earlier release gets its first record');
        $this->assertSame('v1.35.4', $current->fresh()->kubernetes_version);
        $recorded = array_values(array_filter($lines, fn ($l) => str_contains($l, '[kubernetes-version]')));
        $this->assertCount(2, $recorded, implode("\n", $lines));
        $this->assertNotEmpty(array_filter($recorded, fn ($l) => str_contains($l, 'eu-2') && str_contains($l, 'Kubernetes not recorded → v1.35.4')));
    }

    public function test_an_unreachable_cluster_keeps_the_recorded_version(): void
    {
        $this->fakeCluster(['GET /version' => [['kind' => 'Status'], 503]]);
        $region = $this->region('eu-1', 'v1.34.2');

        $outcome = $this->reconciler()->reconcile(null, false, fn () => null);

        $this->assertNotEmpty($outcome->unreachable);
        $this->assertSame('v1.34.2', $region->fresh()->kubernetes_version);
    }
}
