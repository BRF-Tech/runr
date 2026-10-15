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
 * Moving a region installs its internal registry (Zot) on the target cluster and points the
 * region at it — unless a Zot already serves there (the region's own cluster, or a region sharing
 * it), which stays as it is: upgrading it with the region's dedicated-worker placement would leave
 * its pod Pending when its volume is bound to another node.
 */
class RegionMigrateRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function service(ZotRegistryService $zot): RegionMigrateService
    {
        $installer = Mockery::mock(KnativeInstallService::class)->shouldIgnoreMissing();
        $installer->shouldReceive('runningKnative')->andReturn(['operator' => true, 'serving' => true]);

        return new RegionMigrateService(
            $installer,
            Mockery::mock(KnativeDeployService::class),
            $zot,
            Mockery::mock(KnativeRedisService::class),
            Mockery::mock(SandboxInstallService::class),
        );
    }

    private function region(string $mode = 'internal'): KnativeRegion
    {
        return KnativeRegion::create([
            'name' => 'fn-registry',
            'kubeconfig' => 'apiVersion: v1',
            'is_active' => true,
            'registry_mode' => $mode,
            'registry_address' => '10.96.1.1:5000',
            'registry_namespace' => 'runr-registry',
            'registry_storage_type' => 'pvc',
            'registry_storage_config' => ['storageClass' => 'standard', 'size' => '1Gi'],
            'selected_workers' => ['worker2'],
        ]);
    }

    public function test_a_new_cluster_gets_a_zot_and_the_region_its_address(): void
    {
        $region = $this->region();

        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('detectExisting')->once()->with('apiVersion: v1', 'runr-registry')->andReturn(null);
        $zot->shouldReceive('install')->once()
            ->withArgs(fn ($kubeconfig, $config) => $config['namespace'] === 'runr-registry' && $config['storage_type'] === 'pvc'
                && $config['storage_config'] === ['storageClass' => 'standard', 'size' => '1Gi'] && $config['workers_only'] === true)
            ->andReturn(['address' => '10.96.2.2:5000']);

        $this->service($zot)->installInfrastructure($region);

        $this->assertSame('10.96.2.2:5000', $region->fresh()->registry_address);
    }

    public function test_a_zot_running_on_the_target_is_kept(): void
    {
        $region = $this->region();

        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldReceive('detectExisting')->once()->andReturn(['address' => '10.96.3.3:5000']);
        $zot->shouldNotReceive('install');

        $this->service($zot)->installInfrastructure($region);

        $this->assertSame('10.96.3.3:5000', $region->fresh()->registry_address);
    }

    public function test_an_external_registry_has_nothing_to_install(): void
    {
        $region = $this->region('external');

        $zot = Mockery::mock(ZotRegistryService::class);
        $zot->shouldNotReceive('detectExisting');
        $zot->shouldNotReceive('install');

        $this->service($zot)->installInfrastructure($region);

        $this->assertSame('10.96.1.1:5000', $region->fresh()->registry_address);
    }
}
