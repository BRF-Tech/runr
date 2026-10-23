<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use App\Models\ResourcePeak;
use App\Models\RightsizingRecommendation;
use App\Services\Rightsizing\RightsizingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\RegionSetting;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\Feature\Rightsizing\RightsizingFixtures;
use Tests\TestCase;

/**
 * What a rightsizing recommendation saves per month (docs/billing.md#rightsizing), priced by the
 * Billing module: an always-on workload in a pay-as-you-go region at its reservation (size
 * difference × pods × 730 hours × the hourly prices); a scale-to-zero one is billed by use, not by
 * size (0); in a fixed-price region the allocation its worst case could be lowered by, at the
 * monthly prices.
 */
class RightsizingSavingsTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase, RightsizingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 03:00:00');
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 512 MiB / 500m that used 140 MiB / 80m at most: 256 MiB / 125m recommended. */
    private function oversized(string $name, string $type = 'payg', bool $alwaysOn = true): Project
    {
        $region = $this->realRegion($name.'-region');
        if ($type !== 'payg') {
            RegionSetting::query()->create(['region_id' => $region->id, 'billing_type' => $type]);
        }
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_hour' => 0.04, 'memory_gb_hour' => 0.01, 'cpu_vcpu_month' => 25, 'memory_gb_month' => 3]);

        $project = $this->sizedProject($name, $region, alwaysOn: $alwaysOn);
        $this->peaks($project, 14);

        return $project;
    }

    private function recommendationOf(Project $project): RightsizingRecommendation
    {
        app(RightsizingService::class)->refresh();

        return RightsizingRecommendation::query()->where('project_id', $project->id)->sole();
    }

    public function test_an_always_on_workload_saves_its_reservation(): void
    {
        $project = $this->oversized('api');
        $project->faasConfig->update(['min_scale' => 2]);
        $project->activeDeployment->update(['sizing' => array_merge($project->activeDeployment->sizing, ['min_scale' => 2])]);

        $recommendation = $this->recommendationOf($project->fresh());

        // (375m × 0.04 per vCPU-hour + 256 MiB × 0.01 per GB-hour) × 2 pods × 730 hours
        $this->assertSame(['basis' => 'reserved', 'monthly' => 25.55, 'currency' => 'USD'], $recommendation->savings);
        $this->assertSame(25.55, $recommendation->monthly_savings);
        $this->assertSame('USD', $recommendation->currency);
        $this->assertSame(['cpu_m' => 750, 'memory_mb' => 512, 'pods' => 2, 'always_on' => true], $recommendation->evidence['freed']);

        $owner = $this->userWithRole('owner');
        $this->signedIn($owner)->getJson('/api/rightsizing')->assertOk()
            ->assertJsonPath('data.0.savings.basis', 'reserved')
            ->assertJsonPath('data.0.savings.monthly', 25.55)
            ->assertJsonPath('summary.monthly_savings', 25.55)
            ->assertJsonPath('summary.currency', 'USD');
    }

    public function test_a_scale_to_zero_function_is_billed_by_use_not_by_size(): void
    {
        $project = $this->oversized('fn', alwaysOn: false);

        $recommendation = $this->recommendationOf($project);

        // stored as JSON: 0.0 reads back as 0
        $this->assertEquals(['basis' => 'usage', 'monthly' => 0.0, 'currency' => 'USD'], $recommendation->savings);
        $this->assertSame(256, $recommendation->evidence['freed']['memory_mb'], 'the resources are still freed');
    }

    public function test_a_fixed_project_could_lower_its_allocation(): void
    {
        $project = $this->oversized('fixed', 'fixed');
        $allocation = $this->allocation($project, 4000, 8192, '2026-10-01');

        $recommendation = $this->recommendationOf($project);

        // worst case at max_scale 3: 3 × 375m and 3 × 256 MiB less — 1.125 vCPU × 25 + 0.75 GB × 3 a month
        $this->assertSame('fixed', $recommendation->savings['basis']);
        $this->assertSame(30.38, $recommendation->savings['monthly']);
        $this->assertSame(['cpu_m' => 1125, 'memory_mb' => 768], $recommendation->savings['freed_allocation']);
        $this->assertSame(['cpu_m' => $allocation->cpu_m, 'memory_mb' => $allocation->memory_mb], $recommendation->savings['allocation']);
        $this->assertNull($recommendation->blocked, 'a smaller size always fits the allocation');
    }

    public function test_a_larger_size_costs_more_and_is_held_to_the_allocation(): void
    {
        $project = $this->oversized('grow', 'fixed');
        $this->allocation($project, 1000, 2048, '2026-10-01');
        // OOM kills at 512 MiB: 1024 recommended, which three pods cannot fit into 2048 MiB
        ResourcePeak::query()->update(['memory_max_mb' => 500.0, 'oom_kills' => 1, 'cpu_p95_m' => 400]);

        $recommendation = $this->recommendationOf($project);

        $this->assertSame('up', $recommendation->direction);
        $this->assertSame(1024, $recommendation->recommended_memory_mb);
        $this->assertLessThan(0, $recommendation->monthly_savings, 'negative: it costs more');
        $this->assertSame('fixed_allocation_exceeded', $recommendation->blocked['code']);
    }
}
