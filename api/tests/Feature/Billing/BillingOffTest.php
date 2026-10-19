<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Jobs\CloseBillsJob;
use Modules\Billing\Models\Bill;
use Modules\Billing\Services\BillingActivity;
use Modules\Billing\Services\BillService;
use Modules\Billing\Services\PriceBook;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * No money for days billing was off (docs/billing.md#turning-billing-off-and-on): metering keeps
 * running and allocations stay approved while it is off, but the bills open before the off period
 * end with its last active day, a month inside it gets no bill, and billing starts again on the day
 * it comes back. A time without any scheduler (an outage) is no off period.
 */
class BillingOffTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    private Project $payg;

    private Project $fixed;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 09:00:00');
        $this->setUpBilling();

        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion();
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_hour' => 0.10, 'memory_gb_hour' => 0]);
        $fixedRegion = $this->billingRegion('fx', 'fixed');
        // 31 a month: 1.00 a day in October
        $this->priceVersion($fixedRegion->id, '2026-01-01', ['cpu_vcpu_month' => 31, 'memory_gb_month' => 0]);
        $this->payg = $this->billedProject('api', $region, $owner);
        $this->fixed = $this->billedProject('db', $fixedRegion, $owner);
        $this->allocation($this->fixed, 1000, 0, '2026-09-15');
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow($time);
        app(PriceBook::class)->flush();
    }

    /** The scheduler runs with billing off (the billing-off-marker task). */
    private function off(string ...$days): void
    {
        foreach ($days as $day) {
            $this->at($day.' 05:00:00');
            BillingActivity::markOff();
        }
    }

    /** @return array<string, list<array{0: string, 1: string, 2: string, 3: float}>> project id → [start, end, status, total] */
    private function bills(string $period): array
    {
        return Bill::query()->where('period', $period)->where('kind', Bill::PROJECT)->orderBy('period_start')->get()
            ->groupBy('project_id')
            ->map(fn ($bills) => $bills->map(fn (Bill $b) => [$b->period_start->toDateString(), $b->period_end->toDateString(), $b->status, (float) $b->total])->all())
            ->all();
    }

    public function test_the_bill_before_an_off_period_ends_with_its_last_active_day_and_billing_restarts_when_it_comes_back(): void
    {
        $this->at('2026-10-01 09:00:00');
        app(BillService::class)->ensureOpenBills();            // October's bills, billing on
        $this->usageDay($this->payg, '2026-10-03', 10);         // 1.00
        $this->at('2026-10-05 18:00:00');
        app(BillingActivity::class)->touch();                   // the last active day

        $this->off('2026-10-06', '2026-10-12', '2026-10-19');   // billing off; metering goes on
        $this->usageDay($this->payg, '2026-10-10', 10);

        $this->at('2026-10-20 11:00:00');                       // billing back
        app(BillService::class)->ensureOpenBills();
        $this->usageDay($this->payg, '2026-10-22', 10);         // 1.00

        $this->assertSame([['from' => '2026-10-06', 'to' => '2026-10-19']], app(BillingActivity::class)->offPeriods());

        $this->at('2026-10-21 02:30:00');
        dispatch_sync(new CloseBillsJob);
        $this->at('2026-11-01 02:30:00');
        dispatch_sync(new CloseBillsJob);

        $october = $this->bills('2026-10');
        $this->assertSame([['2026-10-01', '2026-10-05', Bill::CLOSED, 1.0], ['2026-10-20', '2026-10-31', Bill::CLOSED, 1.0]], $october[$this->payg->id]);
        // 5 days, then 12 days at 1.00
        $this->assertSame([['2026-10-01', '2026-10-05', Bill::CLOSED, 5.0], ['2026-10-20', '2026-10-31', Bill::CLOSED, 12.0]], $october[$this->fixed->id]);
        // Each closed once
        foreach (Bill::query()->where('period', '2026-10')->pluck('id') as $id) {
            $this->assertSame(1, DB::table('billing_events')->where('dedupe_key', 'bill.closed:'.$id)->count());
        }
    }

    public function test_a_month_inside_an_off_period_gets_no_bill(): void
    {
        app(BillService::class)->ensureOpenBills();             // Sep 25: September's bills
        $this->usageDay($this->payg, '2026-09-24', 10);
        $this->off('2026-09-26', '2026-10-15', '2026-11-02');
        $this->usageDay($this->payg, '2026-10-10', 10);

        $this->at('2026-11-03 10:00:00');
        app(BillService::class)->ensureOpenBills();
        $this->at('2026-11-04 02:30:00');
        dispatch_sync(new CloseBillsJob);

        $september = $this->bills('2026-09');
        $this->assertSame([['2026-09-01', '2026-09-25', Bill::CLOSED, 1.0]], $september[$this->payg->id]);
        // Sep 15 … 25: 11 days of 31 / 30
        $this->assertSame([['2026-09-01', '2026-09-25', Bill::CLOSED, round(31 / 30 * 11, 2)]], $september[$this->fixed->id]);
        $this->assertSame([], $this->bills('2026-10'));
        $this->assertSame([['2026-11-03', '2026-11-30', Bill::OPEN, 0.0]], $this->bills('2026-11')[$this->payg->id]);
    }

    public function test_an_outage_without_any_scheduler_is_no_off_period(): void
    {
        $this->at('2026-10-02 09:00:00');
        app(BillService::class)->ensureOpenBills();
        $this->usageDay($this->payg, '2026-10-03', 10);

        // Nothing ran from Oct 3 to Oct 19 — neither billing nor the off marker
        $this->at('2026-10-20 11:00:00');
        app(BillService::class)->ensureOpenBills();
        $this->at('2026-11-01 02:30:00');
        dispatch_sync(new CloseBillsJob);

        $this->assertSame([], app(BillingActivity::class)->offPeriods());
        $this->assertSame([['2026-10-01', '2026-10-31', Bill::CLOSED, 1.0]], $this->bills('2026-10')[$this->payg->id]);
        $this->assertSame([['2026-10-01', '2026-10-31', Bill::CLOSED, 31.0]], $this->bills('2026-10')[$this->fixed->id]);
    }
}
