<?php

namespace Tests\Feature;

use App\Models\BindingRegionService;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Bindings\DataServiceManager;
use App\Services\KnativeDeployService;
use App\Services\KnativeFunctionTlsService;
use App\Services\KnativeInstallService;
use App\Services\KnativeNodeService;
use App\Services\KnativeRedisService;
use App\Services\RegionMigrateService;
use App\Services\RegionStatusService;
use App\Services\SandboxInstallService;
use App\Services\SupplyChain\AdmissionPolicyService;
use App\Services\Wasm\WasmInstallService;
use App\Services\ZotRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * Moving a region to another cluster installs there what the region had on its old one, not only
 * Knative, Redis, the registry and the sandbox runtimes: its dedicated workers (the selected nodes
 * that exist on the new cluster), WebAssembly and GPU support, the data services RunR runs, the
 * admission policy, function TLS and the status block of a region that is not active.
 */
class RegionMigrateReapplyTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase;

    private function service(): RegionMigrateService
    {
        $installer = Mockery::mock(KnativeInstallService::class);
        $installer->shouldReceive('runningKnative')->andReturn(['operator' => true, 'serving' => true]);

        return new RegionMigrateService(
            $installer,
            Mockery::mock(KnativeDeployService::class),
            Mockery::mock(ZotRegistryService::class),
            Mockery::mock(KnativeRedisService::class),
            Mockery::mock(SandboxInstallService::class),
        );
    }

    /** Mock bound in the container */
    private function bound(string $class): Mockery\MockInterface
    {
        $mock = Mockery::mock($class);
        $this->app->instance($class, $mock);

        return $mock;
    }

    private function region(array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create(array_merge([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'kubeconfig' => $this->fakeKubeconfig(),
            'knative_serving_status' => 'ready', 'migration_old_kubeconfig' => 'old-cluster',
        ], $attributes));
    }

    public function test_everything_the_region_had_is_installed_on_the_new_cluster(): void
    {
        $this->fakeCluster([
            'GET /api/v1/nodes' => ['items' => [['metadata' => ['name' => 'w2']], ['metadata' => ['name' => 'w9']]]],
        ]);
        $region = $this->region([
            'selected_workers' => ['w1', 'w2'],
            'sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin'], 'sandbox_wasm_nodes' => ['w1', 'w2'],
            'gpu_mode' => 'device-plugin', 'gpu_settings' => ['time_slicing_replicas' => 4, 'gpu_nodes' => ['w1', 'w9']],
            'function_tls_mode' => 'custom',
        ]);
        BindingRegionService::create(['knative_region_id' => $region->id, 'service' => 'kv', 'mode' => 'managed', 'status' => 'ready']);
        BindingRegionService::create(['knative_region_id' => $region->id, 'service' => 'object', 'mode' => 'external', 'status' => 'ready']);
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $settings->forceFill(['admission_status' => 'ready'])->save();

        $this->bound(KnativeNodeService::class)->shouldReceive('applyWorkerSelection')->once()->with(Mockery::any(), ['w2']);
        $this->bound(WasmInstallService::class)->shouldReceive('install')->once()->with(Mockery::any(), ['spin'], ['w2']);
        $this->bound(GpuInstallService::class)->shouldReceive('install')->once()
            ->with(Mockery::any(), 'device-plugin', Mockery::on(fn ($o) => $o['gpu_nodes'] === ['w9'] && $o['time_slicing_replicas'] === 4));
        $this->bound(DataServiceManager::class)->shouldReceive('install')->once()->with(Mockery::any(), 'kv', ['mode' => 'managed']);
        $this->bound(AdmissionPolicyService::class)->shouldReceive('install')->once();
        $this->bound(KnativeFunctionTlsService::class)->shouldReceive('reapply')->once()->with(Mockery::any(), 'old-cluster')->andReturn('function TLS: copied');
        $this->bound(RegionStatusService::class)->shouldNotReceive('syncBlock');

        $this->service()->installInfrastructure($region);
        $this->addToAssertionCount(1);
    }

    public function test_an_inactive_region_gets_its_status_block_and_a_failing_step_does_not_stop_the_move(): void
    {
        $this->fakeCluster();
        $region = $this->region(['is_active' => false, 'sandbox_wasm_status' => 'ready', 'sandbox_wasm_shims' => ['spin']]);

        $this->bound(WasmInstallService::class)->shouldReceive('install')->once()->andThrow(new \RuntimeException('no node runs containerd'));
        $this->bound(RegionStatusService::class)->shouldReceive('syncBlock')->once()->andReturn([]);

        $this->service()->installInfrastructure($region);
        $this->addToAssertionCount(1);
    }

    public function test_without_selected_workers_nothing_is_tainted(): void
    {
        $this->fakeCluster();
        $region = $this->region();
        $this->bound(KnativeNodeService::class)->shouldNotReceive('applyWorkerSelection');

        $this->service()->installInfrastructure($region);
        $this->addToAssertionCount(1);
    }
}
