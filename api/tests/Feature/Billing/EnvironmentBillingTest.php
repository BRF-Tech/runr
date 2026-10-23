<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Support\Period;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Bills say which environment their usage came from (docs/billing.md#environments): a project's
 * usage lines split per environment — production, each environment by name, a deleted one by the
 * name recorded with its usage — and the totals stay what the usage costs.
 */
class EnvironmentBillingTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Project, ProjectEnvironment} */
    private function projectWithStaging(): array
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion();
        $project = $this->billedProject('shop', $region, $owner);
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_hour' => 1.00, 'memory_gb_hour' => 0.10, 'multiplier_http' => 1, 'multiplier_queue' => 1]);
        $staging = ProjectEnvironment::query()->create(['project_id' => $project->id, 'name' => 'staging', 'workload_name' => 'shop-staging']);

        return [$project, $staging];
    }

    public function test_usage_lines_split_per_environment_and_the_totals_stay_the_same(): void
    {
        [$project, $staging] = $this->projectWithStaging();

        $this->usageDay($project, '2026-10-05', 2, 1);                                          // production: 2 + 0.1
        $this->usageDay($project, '2026-10-05', 1, 0, attributes: ['environment_id' => $staging->id]); // staging (name read from the environment)
        $this->usageDay($project, '2026-10-06', 1, 0, attributes: ['environment_id' => $staging->id, 'environment_name' => 'staging']);
        // An environment deleted since, its name recorded with its usage
        $this->usageDay($project, '2026-10-07', 3, 0, attributes: ['environment_id' => (string) Str::ulid(), 'environment_name' => 'qa']);
        // One deleted before its name was recorded
        $this->usageDay($project, '2026-10-08', 0.5, 0, attributes: ['environment_id' => (string) Str::ulid()]);

        $result = app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10'));
        $vcpu = collect($result['line_items'])->where('metric', 'vcpu_hours')->values();

        $this->assertSame(['production', 'deleted environment', 'qa', 'staging'], $vcpu->pluck('environment_name')->all(), 'production first, then each environment');
        $byName = $vcpu->keyBy('environment_name');
        $this->assertNull($byName['production']['environment_id']);
        $this->assertSame($staging->id, $byName['staging']['environment_id']);
        $this->assertEqualsWithDelta(2.0, $byName['staging']['quantity'], 1e-9, 'both staging days on one line');
        $this->assertEqualsWithDelta(3.0, $byName['qa']['quantity'], 1e-9);
        $this->assertStringContainsString('(staging, ', $byName['staging']['description']);
        $this->assertStringContainsString('(production, ', $byName['production']['description']);

        // Totals: what the usage costs, whatever the split
        $this->assertEqualsWithDelta(2 + 0.1 + 1 + 1 + 3 + 0.5, $result['total'], 0.001);
        $this->assertSame($result['total'], $result['usage_total']);
        $this->assertSame($result['total'], $result['metered_cost']);
    }

    public function test_a_project_without_environments_keeps_its_lines_as_they_were(): void
    {
        [$project] = $this->projectWithStaging();
        $this->usageDay($project, '2026-10-05', 2, 1);

        $result = app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10'));
        $line = collect($result['line_items'])->firstWhere('metric', 'vcpu_hours');

        $this->assertSame('production', $line['environment_name']);
        $this->assertStringNotContainsString('production', $line['description'], 'no environment named where only production ran');
    }

    public function test_a_queue_consumer_of_an_environment_and_previews_are_named_accordingly(): void
    {
        [$project, $staging] = $this->projectWithStaging();
        RegionSetting::query()->updateOrCreate(['region_id' => $project->knative_region_id], ['billing_type' => 'payg', 'charge_queue_consumers' => true]);
        $this->usageDay($project, '2026-10-05', 1, 0, entryPoint: 'queue', attributes: ['environment_id' => $staging->id, 'source' => 'queue_consumer']);
        $this->usageDay($project, '2026-10-05', 1, 0, attributes: ['source' => 'preview']);

        $lines = collect(app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10'))['line_items'])->where('metric', 'vcpu_hours');

        $consumer = $lines->firstWhere('component', 'queue_consumer');
        $this->assertSame('staging', $consumer['environment_name']);
        $preview = $lines->firstWhere('component', 'preview');
        $this->assertNull($preview['environment_name'], 'previews are not environments');
        $this->assertNull($preview['environment_id']);
    }

    public function test_the_usage_api_breaks_a_project_down_per_environment(): void
    {
        [$project, $staging] = $this->projectWithStaging();
        $admin = $this->userWithRole('admin');
        $this->usageDay($project, '2026-10-05', 2, 0);
        $this->usageDay($project, '2026-10-05', 1, 0, attributes: ['environment_id' => $staging->id]);

        $data = $this->actingAs($admin)->getJson('/api/billing/usage?period=2026-10&project='.$project->id)
            ->assertOk()->json('data.0');

        $this->assertEqualsWithDelta(3.0, $data['quantities']['vcpu_hours'], 1e-9);
        $this->assertSame(['production', 'staging'], array_column($data['environments'], 'name'));
        $this->assertNull($data['environments'][0]['id']);
        $this->assertSame($staging->id, $data['environments'][1]['id']);
        $this->assertEqualsWithDelta(1.0, $data['environments'][1]['quantities']['vcpu_hours'], 1e-9);
        $this->assertEqualsWithDelta($data['cost'], $data['environments'][0]['cost'] + $data['environments'][1]['cost'], 0.001);
    }
}
