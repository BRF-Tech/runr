<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Services\KnativeDeployService;
use App\Services\KnativeInstallService;
use App\Services\KnativeRedisService;
use App\Services\RegionMigrateService;
use App\Services\SandboxInstallService;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Moving a region installs Knative on the target cluster — unless the target already runs it
 * (another region on that cluster, or the region's own cluster): installOperator() starts by
 * removing the operator and the Serving CRDs, which would delete every Knative Service there.
 */
class RegionMigrateKnativeTest extends TestCase
{
    use RefreshDatabase;

    private function service(KnativeInstallService $installer): RegionMigrateService
    {
        return new RegionMigrateService(
            $installer,
            Mockery::mock(KnativeDeployService::class),
            Mockery::mock(ZotRegistryService::class),
            Mockery::mock(KnativeRedisService::class),
            Mockery::mock(SandboxInstallService::class),
        );
    }

    private function region(): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'fn-move',
            'kubeconfig' => 'apiVersion: v1',
            'is_active' => true,
            'knative_operator_namespace' => 'knative-operator',
            'knative_serving_namespace' => 'knative-serving',
            'knative_serving_version' => '1.20.0',
        ]);
    }

    public function test_a_cluster_without_knative_gets_operator_and_serving(): void
    {
        $region = $this->region();

        $installer = Mockery::mock(KnativeInstallService::class);
        $installer->shouldReceive('runningKnative')->once()->andReturn(['operator' => false, 'serving' => false]);
        $installer->shouldReceive('installOperator')->once()->with(Mockery::on(fn ($r) => $r->is($region)), 'knative-operator');
        $installer->shouldReceive('installServing')->once()->with(Mockery::on(fn ($r) => $r->is($region)), 'knative-serving', '1.20.0');

        $this->service($installer)->installInfrastructure($region);
    }

    public function test_a_cluster_that_runs_knative_keeps_it(): void
    {
        $installer = Mockery::mock(KnativeInstallService::class);
        $installer->shouldReceive('runningKnative')->once()->andReturn(['operator' => true, 'serving' => true]);
        $installer->shouldNotReceive('installOperator');
        $installer->shouldNotReceive('installServing');

        $this->service($installer)->installInfrastructure($this->region());
        $this->addToAssertionCount(1);
    }

    public function test_a_running_operator_without_serving_gets_serving_only(): void
    {
        $installer = Mockery::mock(KnativeInstallService::class);
        $installer->shouldReceive('runningKnative')->once()->andReturn(['operator' => true, 'serving' => false]);
        $installer->shouldNotReceive('installOperator');
        $installer->shouldReceive('installServing')->once();

        $this->service($installer)->installInfrastructure($this->region());
        $this->addToAssertionCount(1);
    }

    public function test_deployments_count_as_available_only_with_all_replicas(): void
    {
        $this->assertFalse(KnativeInstallService::deploymentsAvailable(null));
        $this->assertFalse(KnativeInstallService::deploymentsAvailable(['items' => []]));
        $this->assertTrue(KnativeInstallService::deploymentsAvailable(['items' => [
            ['spec' => ['replicas' => 1], 'status' => ['availableReplicas' => 1]],
            ['spec' => ['replicas' => 2], 'status' => ['availableReplicas' => 2]],
        ]]));
        $this->assertFalse(KnativeInstallService::deploymentsAvailable(['items' => [
            ['spec' => ['replicas' => 1], 'status' => ['availableReplicas' => 1]],
            ['spec' => ['replicas' => 2], 'status' => ['availableReplicas' => 1]],
        ]]));
        $this->assertFalse(KnativeInstallService::deploymentsAvailable(['items' => [['spec' => ['replicas' => 1], 'status' => []]]]));
    }
}
