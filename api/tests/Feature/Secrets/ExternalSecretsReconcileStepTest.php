<?php

namespace Tests\Feature\Secrets;

use App\Models\RegionSecretSetting;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\ExternalSecretsStep;
use App\Services\Secrets\External\ExternalSecretsInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * region:reconcile keeps External Secrets Operator on the chart this release pins where a region
 * has it; a region without it is left alone (an optional feature).
 */
class ExternalSecretsReconcileStepTest extends TestCase
{
    use FakesRegionSecrets, RefreshDatabase;

    private function group(): ClusterGroup
    {
        $region = $this->regionWithCluster('fn');

        return new ClusterGroup('uid:test', [new ReconcileTarget($region)], new KubernetesClient((string) $region->kubeconfig));
    }

    public function test_a_region_without_the_operator_is_left_alone(): void
    {
        $service = Mockery::mock(ExternalSecretsInstallService::class);
        $service->shouldNotReceive('deployedVersion', 'install');
        $this->app->instance(ExternalSecretsInstallService::class, $service);

        $this->assertSame([], (new ExternalSecretsStep)->run($this->group(), false));
    }

    public function test_the_pinned_chart_is_left_as_it_is(): void
    {
        $group = $this->group();
        RegionSecretSetting::forRegion($group->primary()->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $service = Mockery::mock(ExternalSecretsInstallService::class);
        $service->shouldReceive('deployedVersion')->andReturn(config('secrets.external.chart_version'));
        $service->shouldNotReceive('install');
        $this->app->instance(ExternalSecretsInstallService::class, $service);

        $this->assertSame([], (new ExternalSecretsStep)->run($group, false));
    }

    public function test_another_chart_version_is_brought_to_the_pin(): void
    {
        $group = $this->group();
        RegionSecretSetting::forRegion($group->primary()->region)->fill(['eso_status' => RegionSecretSetting::ESO_READY])->save();
        $service = Mockery::mock(ExternalSecretsInstallService::class);
        $service->shouldReceive('deployedVersion')->andReturn('0.9.0');
        $this->app->instance(ExternalSecretsInstallService::class, $service);

        // Dry run: says so, changes nothing
        $service->shouldNotReceive('install');
        $lines = (new ExternalSecretsStep)->run($group, true);
        $this->assertStringStartsWith('would install External Secrets Operator to chart '.config('secrets.external.chart_version'), $lines[0]);
        $this->assertStringContainsString('runs 0.9.0', $lines[0]);

        $service = Mockery::mock(ExternalSecretsInstallService::class);
        $service->shouldReceive('deployedVersion')->andReturn('0.9.0');
        $service->shouldReceive('install')->once()->andReturn(RegionSecretSetting::forRegion($group->primary()->region));
        $this->app->instance(ExternalSecretsInstallService::class, $service);

        $lines = (new ExternalSecretsStep)->run($group, false);
        $this->assertStringStartsWith('installed External Secrets Operator', $lines[0]);
    }

    public function test_the_step_is_part_of_region_reconcile(): void
    {
        $this->assertContains('external-secrets', array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps()));
    }
}
