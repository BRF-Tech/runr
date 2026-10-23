<?php

namespace Tests\Unit\Rightsizing;

use App\Services\Rightsizing\SizeRecommender;
use Tests\TestCase;

/**
 * The size a project's last days call for (docs/monitoring.md#rightsizing): memory from the
 * highest working set × 1.3, CPU from the 95th percentile × 1.3, both rounded up to the next step;
 * larger after an OOM kill, a peak near the memory limit or CPU throttling at the current size;
 * nothing before there is enough data.
 */
class SizeRecommenderTest extends TestCase
{
    private function recommender(array $overrides = []): SizeRecommender
    {
        return new SizeRecommender(array_replace(require base_path('config/rightsizing.php'), $overrides));
    }

    /** A day of peaks at a size (512 MiB / 500m unless given). */
    private function day(string $day, array $values = []): array
    {
        return array_replace([
            'day' => $day,
            'cpu_m' => 500,
            'memory_mb' => 512,
            'memory_max_mb' => 100.0,
            'memory_p95_mb' => 90.0,
            'cpu_max_m' => 200,
            'cpu_p95_m' => 80,
            'throttled_ratio' => 0.0,
            'oom_kills' => 0,
            'samples' => 3600,
            'pods' => 10,
        ], $values);
    }

    /** @return list<array<string, mixed>> */
    private function days(int $count, array $values = []): array
    {
        $days = [];
        for ($i = 1; $i <= $count; $i++) {
            $days[] = $this->day(sprintf('2026-10-%02d', $i), $values);
        }

        return $days;
    }

    private const SCALE_TO_ZERO = ['cpu_m' => 500, 'memory_mb' => 512, 'always_on' => false];

    private const ALWAYS_ON = ['cpu_m' => 500, 'memory_mb' => 512, 'always_on' => true];

    public function test_a_function_that_used_at_most_140_mb_of_512_gets_256(): void
    {
        $days = $this->days(14, ['memory_max_mb' => 120.0]);
        $days[6]['memory_max_mb'] = 140.0;

        $result = $this->recommender()->recommend(self::SCALE_TO_ZERO, $days, 500, '2026-10-01', '2026-10-14');

        $this->assertSame('recommended', $result['status']);
        $this->assertSame(256, $result['memory_mb'], '140 × 1.3 = 182 → the next step 256');
        $this->assertSame(['cpu' => 'down', 'memory' => 'down'], $result['changes']);
        $this->assertSame(125, $result['cpu_m'], 'p95 80m × 1.3 = 104m → 125m');
        $this->assertSame('down', $result['direction']);
        $this->assertSame(['memory_over_provisioned', 'cpu_over_provisioned'], $result['reasons']);

        $evidence = $result['evidence'];
        $this->assertSame(14, $evidence['days']);
        $this->assertSame(500, $evidence['sessions']);
        $this->assertSame(140.0, $evidence['memory']['max_mb']);
        $this->assertSame('max', $evidence['memory']['basis']);
        $this->assertSame(80, $evidence['cpu']['p95_m']);
        $this->assertSame('2026-10-01', $evidence['from']);
        $this->assertSame('2026-10-14', $evidence['to']);
    }

    public function test_too_little_data_is_still_collecting(): void
    {
        // two days with readings, a day without any pod
        $days = [$this->day('2026-10-01'), $this->day('2026-10-02'), $this->day('2026-10-03', ['samples' => 0, 'pods' => 0, 'memory_max_mb' => null])];
        $result = $this->recommender()->recommend(self::SCALE_TO_ZERO, $days, 500);

        $this->assertSame('collecting', $result['status']);
        $this->assertSame(['days' => 2, 'required_days' => 3, 'sessions' => 500, 'required_sessions' => 20, 'window_days' => 14], $result['collecting']);

        // enough days, too few sessions of a scale-to-zero function (pods count as sessions too)
        $result = $this->recommender()->recommend(self::SCALE_TO_ZERO, $this->days(5, ['pods' => 1]), 3);
        $this->assertSame('collecting', $result['status']);
        $this->assertSame(5, $result['collecting']['sessions']);

        // an always-on workload needs the days only
        $this->assertSame('recommended', $this->recommender()->recommend(self::ALWAYS_ON, $this->days(3, ['pods' => 1]), 0)['status']);
    }

    public function test_a_project_that_uses_its_size_is_right_sized(): void
    {
        $result = $this->recommender()->recommend(self::ALWAYS_ON, $this->days(7, ['memory_max_mb' => 380.0, 'cpu_p95_m' => 350]), 0);

        $this->assertSame('right_sized', $result['status'], '380 × 1.3 = 494 → 512, 350 × 1.3 = 455 → 500: no change');
        $this->assertSame(380.0, $result['evidence']['memory']['max_mb']);
    }

    public function test_an_oom_kill_at_the_current_size_asks_for_the_next_step(): void
    {
        $days = $this->days(5, ['memory_max_mb' => 200.0]);
        $days[3]['oom_kills'] = 2;

        $result = $this->recommender()->recommend(self::ALWAYS_ON, $days, 0);

        $this->assertSame('recommended', $result['status']);
        $this->assertSame(1024, $result['memory_mb']);
        $this->assertSame('up', $result['changes']['memory']);
        $this->assertContains('memory_oom_killed', $result['reasons']);
        $this->assertSame(2, $result['evidence']['oom_kills']);
        $this->assertSame('mixed', $result['direction'], 'memory up, CPU down');
    }

    public function test_a_peak_near_the_limit_asks_for_more_memory(): void
    {
        $result = $this->recommender()->recommend(self::ALWAYS_ON, $this->days(4, ['memory_max_mb' => 470.0, 'cpu_p95_m' => 400]), 0);

        $this->assertSame(1024, $result['memory_mb'], '470 of 512 MiB is past 90 %');
        $this->assertSame(['memory_near_limit'], $result['reasons']);
        $this->assertTrue($result['evidence']['memory']['near_limit']);
        $this->assertSame('up', $result['direction']);
    }

    public function test_signals_count_only_at_the_current_size(): void
    {
        // OOM kills and throttling at the old, smaller size (256 MiB / 250m) — the project has grown since
        $days = $this->days(6, ['memory_max_mb' => 300.0, 'cpu_p95_m' => 300]);
        $days[0] = $this->day('2026-10-01', ['memory_mb' => 256, 'cpu_m' => 250, 'memory_max_mb' => 256.0, 'oom_kills' => 3, 'throttled_ratio' => 0.8]);

        $result = $this->recommender()->recommend(self::ALWAYS_ON, $days, 0);

        $this->assertSame('right_sized', $result['status']);
        $this->assertSame(0, $result['evidence']['oom_kills']);
        $this->assertSame(0.0, $result['evidence']['throttled_ratio']);
    }

    public function test_cpu_throttling_asks_for_more_cpu(): void
    {
        $result = $this->recommender()->recommend(self::ALWAYS_ON, $this->days(4, ['memory_max_mb' => 380.0, 'cpu_p95_m' => 490, 'throttled_ratio' => 0.4]), 0);

        $this->assertSame(1000, $result['cpu_m']);
        $this->assertSame(512, $result['memory_mb']);
        $this->assertSame(['cpu' => 'up', 'memory' => 'keep'], $result['changes']);
        $this->assertSame(['cpu_throttled'], $result['reasons']);
        $this->assertSame(0.4, $result['evidence']['throttled_ratio']);
    }

    public function test_the_basis_and_margin_are_configurable(): void
    {
        $days = $this->days(5, ['memory_max_mb' => 300.0, 'memory_p95_mb' => 120.0]);

        $max = $this->recommender()->recommend(self::ALWAYS_ON, $days, 0);
        $this->assertSame(512, $max['memory_mb'], 'max 300 × 1.3 = 390 → 512 keeps the memory');
        $this->assertSame('keep', $max['changes']['memory']);
        $p95 = $this->recommender(['memory_basis' => 'p95'])->recommend(self::ALWAYS_ON, $days, 0);
        $this->assertSame(256, $p95['memory_mb'], 'p95 120 × 1.3 = 156 → 256');
        $this->assertSame('p95', $p95['evidence']['memory']['basis']);

        $tight = $this->recommender(['memory_margin' => 1.0])->recommend(self::ALWAYS_ON, $this->days(5, ['memory_max_mb' => 250.0]), 0);
        $this->assertSame(256, $tight['memory_mb']);
    }

    public function test_a_resource_without_readings_keeps_its_size(): void
    {
        $result = $this->recommender()->recommend(self::ALWAYS_ON, $this->days(5, ['cpu_p95_m' => null, 'cpu_max_m' => null, 'memory_max_mb' => 100.0]), 0);

        $this->assertSame(500, $result['cpu_m']);
        $this->assertSame('keep', $result['changes']['cpu']);
        $this->assertSame(256, $result['memory_mb']);
        $this->assertNull($result['evidence']['cpu']['p95_m']);
    }

    public function test_steps(): void
    {
        $steps = [128, 256, 512, 1024];

        $this->assertSame(128, SizeRecommender::ceilStep(10, $steps));
        $this->assertSame(256, SizeRecommender::ceilStep(182, $steps));
        $this->assertSame(256, SizeRecommender::ceilStep(256, $steps));
        $this->assertSame(2048, SizeRecommender::ceilStep(1500, $steps), 'above the ladder: multiples of its last step');

        $this->assertSame(512, SizeRecommender::stepAbove(256, $steps));
        $this->assertSame(512, SizeRecommender::stepAbove(300, $steps));
        $this->assertSame(2048, SizeRecommender::stepAbove(1024, $steps), 'above the ladder: twice the size');
    }
}
