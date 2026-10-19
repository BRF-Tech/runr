<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Billing\Jobs\CloseBillsJob;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\BillingEvent;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\BillService;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Monthly bills (docs/billing.md#bills): one per project and month, closed by the daily job after
 * the month ended and never recalculated; the invoicing system sets the payment link and the paid
 * status of closed bills; bills roll up per owner.
 */
class BillLifecycleTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->setUpBilling();
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_project_has_an_open_bill_and_the_close_job_closes_ended_months(): void
    {
        $admin = $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $region = $this->billingRegion();
        $api = $this->billedProject('api', $region, $dev);
        $idle = $this->billedProject('idle', $region, $dev);
        $this->usageDay($api, '2026-10-05', 25, 0); // 25 × 0.04 = 1.00

        $list = $this->actingAs($admin)->getJson('/api/billing/bills?period=2026-10')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([true, true], array_column($list->json('data'), 'estimate'));
        $this->assertEqualsWithDelta(1.0, collect($list->json('data'))->firstWhere('project.id', $api->id)['total'], 0.001);

        // November: October closes (the idle project gets its bill of 0 too)
        Carbon::setTestNow('2026-11-01 02:30:00');
        dispatch_sync(new CloseBillsJob);

        $october = Bill::query()->where('period', '2026-10')->get()->keyBy('project_id');
        $this->assertSame(Bill::CLOSED, $october[$api->id]->status);
        $this->assertEqualsWithDelta(1.0, $october[$api->id]->total, 0.001);
        $this->assertSame(Bill::CLOSED, $october[$idle->id]->status);
        $this->assertSame(0.0, $october[$idle->id]->total);
        $this->assertSame($dev->name, $october[$api->id]->owner_name);
        $this->assertSame(2, BillingEvent::query()->where('type', 'bill.closed')->count());
        // November's open bills exist
        $this->assertSame(2, Bill::query()->where('period', '2026-11')->where('status', Bill::OPEN)->count());

        // Closed bills are left alone: later price changes and late usage do not touch them
        Price::query()->update(['cpu_vcpu_hour' => 9]);
        $this->usageDay($api, '2026-10-30', 100, 0);
        dispatch_sync(new CloseBillsJob);
        $this->assertEqualsWithDelta(1.0, $october[$api->id]->fresh()->total, 0.001);
        $this->assertSame(2, BillingEvent::query()->where('type', 'bill.closed')->count());
    }

    public function test_the_payment_link_and_paid_status_of_closed_bills(): void
    {
        $admin = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion(), $admin);
        $bill = Bill::query()->create([
            'kind' => Bill::PROJECT, 'subject_key' => Bill::projectKey($project->id), 'project_id' => $project->id, 'project_name' => $project->name,
            'owner_id' => (string) $admin->id, 'period' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'currency' => 'USD', 'status' => Bill::CLOSED, 'line_items' => [], 'metered_lines' => [], 'fixed_total' => 0, 'usage_total' => 10,
            'total' => 10, 'metered_cost' => 10, 'closed_at' => now(),
        ]);
        $open = app(BillService::class)->projectBill($project);

        $this->actingAs($admin)->putJson("/api/billing/bills/{$open->id}/payment-url", ['payment_url' => 'https://pay.example.com/1'])
            ->assertStatus(409)->assertJsonPath('code', 'bill_open');
        $this->actingAs($admin)->putJson("/api/billing/bills/{$bill->id}/payment-url", ['payment_url' => 'not a url'])->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/billing/bills/{$bill->id}/payment-url", ['payment_url' => 'https://pay.example.com/1'])
            ->assertOk()->assertJsonPath('data.payment_url', 'https://pay.example.com/1');
        $this->actingAs($admin)->putJson("/api/billing/bills/{$bill->id}/paid", ['paid' => true, 'payment_reference' => 'INV-7'])
            ->assertOk()->assertJsonPath('data.paid', true)->assertJsonPath('data.payment_reference', 'INV-7');
        $this->assertNotNull($bill->fresh()->paid_at);
        $this->actingAs($admin)->putJson("/api/billing/bills/{$bill->id}/paid", ['paid' => false])
            ->assertOk()->assertJsonPath('data.paid', false)->assertJsonPath('data.paid_at', null);

        $types = BillingEvent::query()->orderBy('id')->pluck('type')->all();
        $this->assertSame(['bill.payment_url_set', 'bill.paid', 'bill.unpaid'], $types);

        $this->actingAs($admin)->getJson('/api/billing/bills?paid=false&status=closed')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_tokens_of_an_invoicing_system_are_scoped_to_billing_view_or_manage(): void
    {
        $admin = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion(), $admin);
        $bill = Bill::query()->create([
            'kind' => Bill::PROJECT, 'subject_key' => Bill::projectKey($project->id), 'project_id' => $project->id,
            'period' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'USD',
            'status' => Bill::CLOSED, 'total' => 5, 'closed_at' => now(),
        ]);

        $this->bearer($admin, ['billing.view'])->getJson('/api/billing/bills')->assertOk();
        $this->bearer($admin, ['billing.view'])->putJson("/api/billing/bills/{$bill->id}/paid", ['paid' => true])
            ->assertForbidden()->assertJsonPath('permissions', ['billing.manage']);
        $this->bearer($admin, ['billing.manage'])->putJson("/api/billing/bills/{$bill->id}/paid", ['paid' => true])->assertOk();
        $this->bearer($admin, ['projects.view'])->getJson('/api/billing/bills')->assertForbidden();
    }

    public function test_bills_roll_up_per_owner_and_every_user_sees_their_own(): void
    {
        $admin = $this->userWithRole('owner');
        $alice = $this->userWithRole('developer');
        $bob = $this->userWithRole('developer');
        $region = $this->billingRegion();
        $a1 = $this->billedProject('a1', $region, $alice);
        $a2 = $this->billedProject('a2', $region, $alice);
        $b1 = $this->billedProject('b1', $region, $bob);
        $this->usageDay($a1, '2026-10-02', 25, 0);  // 1.00
        $this->usageDay($a2, '2026-10-03', 50, 0);  // 2.00
        $this->usageDay($b1, '2026-10-04', 100, 0); // 4.00
        // Alice's binding: her account bill (2000 GB-hours × 0.0002 = 0.40)
        DB::table('binding_usage_records')->insert([
            'id' => (string) Str::ulid(), 'binding_id' => (string) Str::ulid(), 'binding_name' => 'cache', 'type' => 'kv', 'plan' => 'shared',
            'region_id' => $region->id, 'record_date' => '2026-10-05', 'storage_gb_hours' => 2000, 'instance_hours' => 0,
            'created_by' => (string) $alice->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rollup = collect($this->actingAs($admin)->getJson('/api/billing/rollup')->assertOk()->json('data'))->keyBy('owner.id');
        $this->assertSame(2, $rollup[(string) $alice->id]['projects']);
        $this->assertSame(3, $rollup[(string) $alice->id]['bills']);
        $this->assertEqualsWithDelta(3.4, $rollup[(string) $alice->id]['total'], 0.001);
        $this->assertEqualsWithDelta(4.0, $rollup[(string) $bob->id]['unpaid_total'], 0.001);

        $mine = $this->actingAs($alice)->getJson('/api/billing/me')->assertOk();
        $this->assertCount(3, $mine->json('data'));
        $this->assertEqualsWithDelta(3.4, $mine->json('total'), 0.001);
        // Every bill with its lines, the account bill too
        foreach ($mine->json('data') as $bill) {
            $this->assertNotEmpty($bill['line_items'], $bill['kind']);
        }
        $account = collect($mine->json('data'))->firstWhere('kind', 'account');
        $this->assertSame('binding_storage_gb_hours', $account['line_items'][0]['metric']);

        // The rollup itself is for billing staff
        $this->actingAs($alice)->getJson('/api/billing/rollup')->assertForbidden();
    }

    public function test_a_transferred_project_is_billed_to_its_new_owner(): void
    {
        $this->userWithRole('owner');
        $alice = $this->userWithRole('developer');
        $bob = $this->userWithRole('developer');
        $project = $this->billedProject('a1', $this->billingRegion(), $alice);

        $bills = app(BillService::class);
        $this->assertSame((string) $alice->id, $bills->projectBill($project)->owner_id);

        $project->forceFill(['owner_id' => $bob->id])->save();
        $this->assertSame((string) $bob->id, $bills->projectBill($project->fresh())->owner_id);
    }

    public function test_one_close_run_at_a_time_and_a_bill_closes_once(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion(), $owner);
        $this->usageDay($project, '2026-10-05', 25);
        $bills = app(BillService::class);
        $stale = $bills->projectBill($project);

        // Another worker holds the lock: this run does nothing
        Carbon::setTestNow('2026-11-01 02:30:00');
        $held = Cache::lock(CloseBillsJob::LOCK, 60);
        $this->assertTrue($held->get());
        dispatch_sync(new CloseBillsJob);
        $this->assertSame(Bill::OPEN, $stale->fresh()->status);
        $held->release();

        dispatch_sync(new CloseBillsJob);
        $closed = $stale->fresh();
        $this->assertSame(Bill::CLOSED, $closed->status);
        $this->assertTrue(Cache::lock(CloseBillsJob::LOCK, 60)->get(), 'the run released its lock');

        // A second closer working from a model read before: nothing is recalculated or announced twice
        Carbon::setTestNow('2026-11-01 05:00:00');
        $this->usageDay($project, '2026-10-06', 100);
        $again = $bills->close($stale);
        $this->assertSame(Bill::CLOSED, $again->status);
        $this->assertEqualsWithDelta(1.0, $again->total, 0.001);
        $this->assertTrue($closed->closed_at->equalTo($again->fresh()->closed_at));
        $this->assertSame(1, BillingEvent::query()->where('type', 'bill.closed')->where('dedupe_key', 'bill.closed:'.$stale->id)->count());
    }

    public function test_open_bills_follow_a_currency_change(): void
    {
        $admin = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion(), $admin);
        DB::table('binding_usage_records')->insert([
            'id' => (string) Str::ulid(), 'binding_id' => (string) Str::ulid(), 'binding_name' => 'cache', 'type' => 'kv', 'plan' => 'shared',
            'region_id' => $project->knative_region_id, 'record_date' => '2026-10-05', 'storage_gb_hours' => 100, 'instance_hours' => 0,
            'created_by' => (string) $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bills = app(BillService::class);
        $bills->ensureOpenBills();

        config(['billing.currency' => 'EUR']);
        $bills->ensureOpenBills();

        $october = Bill::query()->where('period', '2026-10')->get();
        $this->assertSame(2, $october->count(), 'the project bill and the account bill, once each');
        $this->assertSame(['EUR'], $october->pluck('currency')->unique()->values()->all());
    }
}
