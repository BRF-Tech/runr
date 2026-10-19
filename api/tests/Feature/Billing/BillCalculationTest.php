<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Services\BillService;
use Modules\Billing\Support\Period;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * How a bill is calculated (docs/billing.md#how-a-bill-is-calculated): pay-as-you-go usage at the
 * prices of its day (multipliers, a price change splits a line), fixed allocations prorated by
 * day, usage covered by an allocation, organization-level usage on account bills.
 */
class BillCalculationTest extends TestCase
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

    private function calculator(): BillCalculator
    {
        return app(BillCalculator::class);
    }

    public function test_pay_as_you_go_usage_is_priced_per_day_with_multipliers_and_split_by_price_versions(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion();
        $project = $this->billedProject('api', $region, $owner);
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_hour' => 0.10, 'memory_gb_hour' => 0.01, 'multiplier_http' => 2, 'multiplier_queue' => 1]);
        $this->priceVersion($region->id, '2026-10-10', ['cpu_vcpu_hour' => 0.20, 'memory_gb_hour' => 0.01, 'multiplier_http' => 2, 'multiplier_queue' => 1]);

        $this->usageDay($project, '2026-10-05', 1, 2);            // (0.10 + 2 × 0.01) × 2
        $this->usageDay($project, '2026-10-12', 1, 0);            // 0.20 × 2
        $this->usageDay($project, '2026-10-12', 3, 0, 0, 'queue'); // 3 × 0.20 × 1

        $result = $this->calculator()->forProject((string) $project->id, Period::fromKey('2026-10'));
        $lines = collect($result['line_items']);

        $this->assertSame([], $result['metered_lines']);
        $this->assertSame('payg', $result['billing_type']);
        $this->assertEqualsWithDelta(0.20 + 0.04 + 0.40 + 0.60, $result['total'], 0.001);
        $this->assertSame($result['total'], $result['usage_total']);
        $this->assertSame($result['total'], $result['metered_cost']);

        // vCPU hours over HTTP: one line per price version
        $http = $lines->where('metric', 'vcpu_hours')->where('entry_point', 'http')->values();
        $this->assertCount(2, $http);
        $this->assertSame('2026-10-05', $http->firstWhere('total', 0.2)['from']);
        $this->assertEqualsWithDelta(2.0, $http->firstWhere('total', 0.4)['multiplier'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $lines->where('entry_point', 'queue')->first()['quantity'], 1e-9);
    }

    public function test_fixed_allocations_are_prorated_by_day_from_their_approval(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner);

        // 1 vCPU / 1 GB (28 a month) from Oct 1 to Oct 15, then 2 vCPU / 4 GB (62 a month) from Oct 16
        $this->allocation($project, 1000, 1024, '2026-10-01', '2026-10-15', FixedRequest::SUPERSEDED);
        $this->allocation($project, 2000, 4096, '2026-10-16');

        $result = $this->calculator()->forProject((string) $project->id, Period::fromKey('2026-10'));
        $fixed = collect($result['line_items'])->where('type', 'fixed')->values();

        $this->assertCount(2, $fixed);
        $this->assertSame(15, $fixed[0]['quantity']);
        $this->assertEqualsWithDelta(28 * 15 / 31, $fixed[0]['total'], 0.006);
        $this->assertSame(16, $fixed[1]['quantity']);
        $this->assertEqualsWithDelta(32.0, $fixed[1]['total'], 0.001);
        $this->assertSame('fixed', $result['billing_type']);
        $this->assertEqualsWithDelta(round(28 * 15 / 31, 2) + 32.0, $result['total'], 0.001);

        // The first month of an allocation approved on the 10th: 22 of 31 days
        $other = $this->billedProject('web', $this->billingRegion('fx2', 'fixed'), $owner);
        $this->allocation($other, 2000, 4096, '2026-10-10');
        $first = $this->calculator()->forProject((string) $other->id, Period::fromKey('2026-10'));
        $this->assertEqualsWithDelta(44.0, $first['fixed_total'], 0.001);
        // The next month in full
        $this->assertEqualsWithDelta(62.0, $this->calculator()->forProject((string) $other->id, Period::fromKey('2026-11'))['fixed_total'], 0.001);
    }

    public function test_usage_covered_by_an_allocation_is_metered_but_not_billed(): void
    {
        $this->userWithRole('owner');
        $owner = $this->userWithRole('developer');
        $staff = $this->userWithRole('billing');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $owner);
        $this->allocation($project, 2000, 4096, '2026-10-16');

        $this->usageDay($project, '2026-10-05', 10, 0); // before the allocation: billed
        $this->usageDay($project, '2026-10-20', 100, 0); // covered

        $result = $this->calculator()->forProject((string) $project->id, Period::fromKey('2026-10'));
        $this->assertSame('mixed', $result['billing_type']);
        $this->assertEqualsWithDelta(0.40, $result['usage_total'], 0.001);   // 10 × 0.04
        $this->assertEqualsWithDelta(32.0, $result['fixed_total'], 0.001);
        $this->assertCount(1, $result['metered_lines']);
        $this->assertEqualsWithDelta(0.40 + 4.0, $result['metered_cost'], 0.001);

        // The owner sees the bill; billing staff also the metered cost and the difference
        $bill = app(BillService::class)->projectBill($project, Period::fromKey('2026-10'));
        $this->actingAs($owner)->getJson("/api/projects/{$project->id}/billing/bills/{$bill->id}")->assertOk()
            ->assertJsonPath('data.total', 32.4)
            ->assertJsonMissingPath('data.metered_cost');
        $this->actingAs($staff)->getJson("/api/billing/bills/{$bill->id}")->assertOk()
            ->assertJsonPath('data.metered_cost', 4.4)
            ->assertJsonPath('data.difference', 28)
            ->assertJsonCount(1, 'data.metered_lines');
    }

    public function test_organization_level_usage_is_billed_to_its_creator_or_unassigned(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion();
        $this->priceVersion($region->id, '2026-01-01', ['kv_gb_hour' => 0.001, 'postgres_instance_hour' => 0.5, 'cpu_vcpu_hour' => 0.1, 'memory_gb_hour' => 0.01]);

        DB::table('binding_usage_records')->insert([
            ['id' => (string) Str::ulid(), 'binding_id' => (string) Str::ulid(), 'binding_name' => 'cache', 'type' => 'kv', 'plan' => 'shared',
                'region_id' => $region->id, 'record_date' => '2026-10-03', 'storage_gb_hours' => 1000, 'instance_hours' => 0, 'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'binding_id' => (string) Str::ulid(), 'binding_name' => 'ledger', 'type' => 'postgres', 'plan' => 'dedicated',
                'region_id' => $region->id, 'record_date' => '2026-10-04', 'storage_gb_hours' => 0, 'instance_hours' => 10, 'created_by' => null,
                'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('sandbox_usage_records')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => null, 'knative_region_id' => $region->id, 'record_date' => '2026-10-05',
            'sandbox_count' => 3, 'sandbox_seconds' => 7200, 'cpu_mc_ms' => 7_200_000_000, 'memory_mib_ms' => 0, 'created_by' => $owner->id,
            'raw_cost' => 0, 'multiplier' => 1, 'final_cost' => 0, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->usageDay($this->billedProject('svc', $region, $owner), '2026-10-06', 5, 0, 0, 'http', ['source' => 'model', 'created_by' => $owner->id]);

        $mine = $this->calculator()->forAccount((string) $owner->id, Period::fromKey('2026-10'));
        // KV 1000 GB-h × 0.001 + sandboxes 2 vCPU-h × 0.1 + model service 5 vCPU-h × 0.1
        $this->assertEqualsWithDelta(1.0 + 0.2 + 0.5, $mine['total'], 0.001);
        $this->assertEqualsWithDelta(1.0, collect($mine['line_items'])->firstWhere('metric', 'binding_storage_gb_hours')['total'], 0.001);
        $this->assertNotNull(collect($mine['line_items'])->firstWhere('metric', 'sandbox_vcpu_hours'));

        $unassigned = $this->calculator()->forAccount(null, Period::fromKey('2026-10'));
        $this->assertEqualsWithDelta(5.0, $unassigned['total'], 0.001);
        $this->assertSame('binding_instance_hours', $unassigned['line_items'][0]['metric']);
    }
}
