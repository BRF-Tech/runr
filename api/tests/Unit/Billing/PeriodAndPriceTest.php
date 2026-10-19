<?php

namespace Tests\Unit\Billing;

use Carbon\CarbonImmutable;
use Modules\Billing\Support\DefaultPrices;
use Modules\Billing\Support\Period;
use Modules\Billing\Support\ResolvedPrice;
use PHPUnit\Framework\TestCase;

/**
 * Billing periods (calendar months) and resolved prices.
 */
class PeriodAndPriceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_period_is_a_calendar_month(): void
    {
        $period = Period::fromKey('2026-02');

        $this->assertSame('2026-02-01', $period->startDate());
        $this->assertSame('2026-02-28', $period->endDate());
        $this->assertSame(28, $period->days());
        $this->assertSame('2026-01', $period->previous()->key());
        $this->assertSame('2026-03', $period->next()->key());
        $this->assertTrue($period->contains('2026-02-14'));
        $this->assertFalse($period->contains('2026-03-01'));
        $this->assertSame('2026-10', Period::of('2026-10-31')->key());
    }

    public function test_period_keys_are_validated(): void
    {
        $this->assertTrue(Period::isKey('2026-12'));
        $this->assertFalse(Period::isKey('2026-13'));
        $this->assertFalse(Period::isKey('26-01'));

        $this->expectException(\InvalidArgumentException::class);
        Period::fromKey('2026-1');
    }

    public function test_current_and_ended_periods(): void
    {
        CarbonImmutable::setTestNow('2026-10-15 12:00:00');

        $this->assertTrue(Period::current()->isCurrent());
        $this->assertTrue(Period::fromKey('2026-09')->hasEnded());
        $this->assertFalse(Period::fromKey('2026-10')->hasEnded());
    }

    public function test_a_resolved_price_never_returns_zero_for_a_missing_field(): void
    {
        $price = new ResolvedPrice(['cpu_vcpu_hour' => 0.5, 'multiplier_queue' => 2.0], 'region', 'p1', '2026-01-01', 10.0, 'm1');

        $this->assertSame(0.5, $price->get('cpu_vcpu_hour'));
        $this->assertSame(DefaultPrices::VALUES['gpu_month'], $price->get('gpu_month'));
        $this->assertSame(2.0, $price->multiplier('queue'));
        $this->assertSame(1.0, $price->multiplier('http'));
        $this->assertEqualsWithDelta(1.1, $price->markupFactor(), 1e-9);
        $this->assertSame('p1|m1', $price->versionKey());
        $this->assertSame(0.0, $price->withMarkup(0, null)->markupPercent);
    }
}
