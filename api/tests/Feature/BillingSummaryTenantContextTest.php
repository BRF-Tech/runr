<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\XaasConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Http\Controllers\TenantBillingController;
use Modules\Tenancy\Services\TenantBillingService;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * A platform administrator's billing summary of an organization (GET /tenants/{id}/billing/summary,
 * the only caller of TenantBillingService::getSummary()) counts that organization's usage in its
 * own schema, whichever organization the administrator works in: the controller runs the summary
 * in the target tenant ($tenant->run()) and the administrator's context is back afterwards.
 */
class BillingSummaryTenantContextTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /** An XaaS project of 250m / 256 MiB on the region: + 100m / 100 MiB queue-proxy per instance. */
    private function project(string $name, string $cpu, int $minScale, string $regionId): void
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $regionId])->saveQuietly();
        XaasConfig::create([
            'project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler',
            'cpu' => $cpu, 'memory_mb' => 256, 'trigger_type' => 'http', 'min_scale' => $minScale, 'max_scale' => 5,
        ]);
    }

    public function test_an_administrator_working_in_another_organization_gets_the_opened_ones_usage(): void
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true, 'total_cpu_m' => 16000, 'total_memory_mb' => 32768]);
        $acme = $this->createTenantWithSchema('acme');
        $ops = $this->createTenantWithSchema('ops');
        $acme->regions()->syncWithoutDetaching([$region->id => ['cpu_quota_m' => 1000, 'memory_quota_mb' => 2048]]);
        $ops->regions()->syncWithoutDetaching([$region->id => ['cpu_quota_m' => 8000, 'memory_quota_mb' => 8192]]);

        $acme->run(function () use ($region) {
            $this->project('api', '250m', 1, $region->id);     // 350m / 356 MiB
            $this->project('worker', '250m', 0, $region->id);  // 350m / 356 MiB
        });
        // other projects on the same region: of the organization the administrator works in, and central
        $ops->run(fn () => $this->project('ops-big', '2000m', 2, $region->id)); // 4200m
        $this->project('central-big', '4000m', 1, $region->id);                // 4100m

        // a multi-tenant install: platform-wide tables (sandbox usage) on the central connection
        config(['tenancy.enabled' => true]);
        tenancy()->initialize($ops);
        $summary = app(TenantBillingController::class)->summary($acme->id)->getData(true);
        $this->assertSame($ops->getTenantKey(), tenancy()->tenant?->getTenantKey());

        $this->assertSame($acme->id, $summary['tenant_id']);
        $this->assertSame(700, $summary['cpu_m']);
        $this->assertSame(712, $summary['memory_mb']);
        $this->assertSame([700, 712, 1000, 2048], [
            $summary['regions'][0]['used_cpu_m'], $summary['regions'][0]['used_memory_mb'],
            $summary['regions'][0]['cpu_quota_m'], $summary['regions'][0]['memory_quota_mb'],
        ]);

        // control: the service counts in whatever schema it is called from — the controller's
        // $tenant->run() is what puts it in acme's
        $inOps = app(TenantBillingService::class)->getSummary($acme);
        $this->assertSame(4200, $inOps['cpu_m']);
    }
}
