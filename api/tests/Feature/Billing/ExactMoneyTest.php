<?php

namespace Tests\Feature\Billing;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\BillCalculator;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Support\Money;
use Modules\Billing\Support\Period;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Money is exact (docs/billing.md#how-a-bill-is-calculated): every line is calculated from exact
 * prices and quantities and rounded once, half up, to the minor unit; a bill's totals are the sums
 * of its rounded lines; the quote of fixed resources is the bill's own calculation. The property
 * tests compare the calculator with an integer oracle over every month length.
 */
class ExactMoneyTest extends TestCase
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

    /** Integer half-up of numerator / denominator (both positive). */
    private function halfUp(BigInteger $numerator, BigInteger $denominator): string
    {
        return (string) $numerator->multipliedBy(2)->plus($denominator)->quotient($denominator->multipliedBy(2));
    }

    public function test_a_prorated_month_is_rounded_half_up_exactly(): void
    {
        // 9.31 a month, February 2026 (28 days), 26 days: exactly 8.645 → 8.65 (floats gave 8.64)
        $region = $this->billingRegion('fx', 'fixed');
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_month' => 9.31, 'memory_gb_month' => 0]);

        $first = app(BillCalculator::class)->firstMonth($region->id, 1000, 0, 0, CarbonImmutable::parse('2026-02-03'));

        $this->assertSame(26, $first['days']);
        $this->assertSame(8.65, $first['amount']);
    }

    public function test_prorated_fixed_lines_match_an_integer_oracle_for_every_month_length(): void
    {
        $region = $this->billingRegion('fx', 'fixed');
        $price = $this->priceVersion($region->id, '2000-01-01');
        $calculator = app(BillCalculator::class);
        $months = ['2026-02', '2028-02', '2026-04', '2026-10'];   // 28, 29, 30 and 31 days
        mt_srand(58);

        for ($case = 0; $case < 400; $case++) {
            $period = Period::fromKey($months[$case % 4]);
            $from = $period->start->addDays(mt_rand(0, $period->days() - 1));
            $cpuM = mt_rand(1, 640) * 100;
            $memoryMb = mt_rand(1, 2048) * 128;
            $cpu4 = mt_rand(0, 9_999_999);     // the price × 10⁴ (four decimals, up to 999.9999)
            $memory4 = mt_rand(0, 999_999);
            Price::query()->whereKey($price->id)->update(['cpu_vcpu_month' => $cpu4 / 10_000, 'memory_gb_month' => $memory4 / 10_000]);
            app(PriceBook::class)->flush();

            $days = $period->days() - $from->day + 1;
            // (cpu_m / 1000 × cpu + memory_mb / 1024 × memory) × days / days of the month, in cents
            $numerator = BigInteger::of($cpuM)->multipliedBy($cpu4)->multipliedBy(1024)
                ->plus(BigInteger::of($memoryMb)->multipliedBy($memory4)->multipliedBy(1000))
                ->multipliedBy($days)->multipliedBy(100);
            $denominator = BigInteger::of(1000 * 1024 * 10_000)->multipliedBy($period->days());

            $amount = $calculator->firstMonth($region->id, $cpuM, $memoryMb, 0, $from)['amount'];
            $this->assertSame($this->halfUp($numerator, $denominator), (string) (int) round($amount * 100), "case {$case}: {$cpuM} m, {$memoryMb} MB, {$cpu4}/{$memory4}, {$from->toDateString()}");
        }
    }

    public function test_usage_lines_match_an_integer_oracle(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion();
        $price = $this->priceVersion($region->id, '2000-01-01');
        $calculator = app(BillCalculator::class);
        mt_srand(85);

        for ($case = 0; $case < 60; $case++) {
            $project = $this->billedProject('p'.$case, $region, $owner);
            $hours8 = mt_rand(1, 99_999_999);     // the vCPU-hour price × 10⁸
            $multiplier3 = mt_rand(1, 5000);       // the HTTP multiplier × 10³
            $milliCoreMs = [mt_rand(1, PHP_INT_MAX >> 24), mt_rand(1, PHP_INT_MAX >> 24)];
            Price::query()->whereKey($price->id)->update(['cpu_vcpu_hour' => $hours8 / 100_000_000, 'memory_gb_hour' => 0, 'multiplier_http' => $multiplier3 / 1000]);
            app(PriceBook::class)->flush();
            foreach ($milliCoreMs as $day => $ms) {
                $this->usageDay($project, '2026-10-0'.($day + 1), 0, 0, 0, 'http', ['total_cpu_mc_ms' => $ms]);
            }

            // Σ ms / 3.6·10⁹ × price × multiplier, in cents
            $numerator = BigInteger::of($milliCoreMs[0] + $milliCoreMs[1])->multipliedBy($hours8)->multipliedBy($multiplier3)->multipliedBy(100);
            $denominator = BigInteger::of(3_600_000_000)->multipliedBy(100_000_000)->multipliedBy(1000);

            $result = $calculator->forProject((string) $project->id, Period::fromKey('2026-10'));
            $this->assertCount(1, $result['line_items'], "case {$case}");
            $this->assertSame($this->halfUp($numerator, $denominator), (string) (int) round($result['total'] * 100), "case {$case}");
        }
    }

    public function test_a_bill_total_is_the_sum_of_its_rounded_lines(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion();
        // 0.005 per vCPU-hour: every day 1 vCPU-hour → 0.005 → a line of 0.01 per run, never 0.015 summed first
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_hour' => 0.005, 'memory_gb_hour' => 0]);
        $this->priceVersion($region->id, '2026-10-10', ['cpu_vcpu_hour' => 0.005, 'memory_gb_hour' => 0]);
        $this->priceVersion($region->id, '2026-10-20', ['cpu_vcpu_hour' => 0.005, 'memory_gb_hour' => 0]);
        $project = $this->billedProject('api', $region, $owner);
        foreach (['2026-10-05', '2026-10-15', '2026-10-25'] as $day) {
            $this->usageDay($project, $day, 1);
        }

        $result = app(BillCalculator::class)->forProject((string) $project->id, Period::fromKey('2026-10'));

        $this->assertSame([0.01, 0.01, 0.01], array_column($result['line_items'], 'total'));
        $this->assertSame(0.03, $result['total']);
        $this->assertSame(0.03, $result['usage_total']);
    }

    public function test_the_quote_is_what_the_bill_charges_for_an_approval_today(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $this->priceVersion($region->id, '2026-01-01', ['cpu_vcpu_month' => 9.31, 'memory_gb_month' => 2.17]);
        $project = $this->billedProject('api', $region, $owner);

        foreach (['2026-02-03', '2026-02-27', '2026-04-11', '2026-10-30'] as $today) {
            Carbon::setTestNow($today.' 10:00:00');
            app(PriceBook::class)->flush();
            $quote = $this->actingAs($owner)->getJson("/api/projects/{$project->id}/billing/quote?cpu_m=1500&memory_mb=3000")->assertOk()->json();

            $allocation = $this->allocation($project, 1500, 3000, $today);
            $bill = app(BillCalculator::class)->forProject((string) $project->id, Period::of($today));
            $allocation->delete();

            $this->assertSame($bill['fixed_total'], (float) $quote['first_month']['amount'], $today);
        }
    }

    public function test_the_minor_unit_follows_the_currency(): void
    {
        $this->assertSame(2, Money::minorUnit('EUR'));
        $this->assertSame(0, Money::minorUnit('JPY'));
        $this->assertSame(3, Money::minorUnit('KWD'));
        $this->assertSame('8.65', (string) Money::round(Money::dec('8.645')));
        $this->assertSame('9', (string) Money::round(Money::dec('8.5'), 0));
        $this->assertSame('0.30', (string) Money::round(Money::dec(0.1)->plus(Money::dec(0.2))));
    }
}
