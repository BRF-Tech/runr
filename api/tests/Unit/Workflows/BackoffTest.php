<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\Backoff;
use PHPUnit\Framework\TestCase;

class BackoffTest extends TestCase
{
    public function test_exponential_without_jitter(): void
    {
        $b = new Backoff(base: 2, factor: 2, max: 600, jitter: 0);

        $this->assertSame(2_000, $b->delayMs(1));
        $this->assertSame(4_000, $b->delayMs(2));
        $this->assertSame(8_000, $b->delayMs(3));
        $this->assertSame(16_000, $b->delayMs(4));
    }

    public function test_capped_at_max(): void
    {
        $b = new Backoff(base: 2, factor: 2, max: 60, jitter: 0);

        $this->assertSame(60_000, $b->delayMs(10));
        $this->assertSame(60_000, $b->delayMs(50));
    }

    public function test_attempt_below_one_counts_as_one(): void
    {
        $b = new Backoff(base: 5, factor: 3, max: 600, jitter: 0);

        $this->assertSame(5_000, $b->delayMs(0));
        $this->assertSame(15_000, $b->delayMs(2));
    }

    public function test_jitter_stays_within_bounds_and_cap(): void
    {
        $b = new Backoff(base: 10, factor: 2, max: 30, jitter: 0.2);

        for ($i = 0; $i < 200; $i++) {
            $d = $b->delayMs(1);
            $this->assertGreaterThanOrEqual(8_000, $d);
            $this->assertLessThanOrEqual(12_000, $d);

            $capped = $b->delayMs(5);
            $this->assertLessThanOrEqual(30_000, $capped);
            $this->assertGreaterThanOrEqual(24_000, $capped);
        }
    }
}
