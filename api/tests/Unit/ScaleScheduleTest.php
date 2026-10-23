<?php

namespace Tests\Unit;

use App\Rules\ScaleScheduleCron;
use App\Support\Projects\CronFields;
use App\Support\Projects\ScaleSchedule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The timed warmer's schedule (docs/scaling.md#timed-warmer): each row means "from this moment on,
 * at least N containers" until the next row fires, in the organization's time zone.
 * 2026-10-21 is a Wednesday; Europe/Istanbul is UTC+3 all year.
 */
class ScaleScheduleTest extends TestCase
{
    private const IST = 'Europe/Istanbul';

    /** Weekdays from 09:00 three containers stay warm, from 18:00 none. */
    private const OFFICE = [
        ['cron' => '0 9 * * 1-5', 'min_scale' => 3],
        ['cron' => '0 18 * * 1-5', 'min_scale' => 0],
    ];

    private static function at(string $time, string $zone = self::IST): CarbonImmutable
    {
        return CarbonImmutable::parse($time, $zone);
    }

    // ── The value at a moment ───────────────────────────────────────────────

    public static function officeMoments(): array
    {
        return [
            'Wednesday morning' => ['2026-10-21 10:30', 3],
            'before the first row of the day' => ['2026-10-21 08:59', 0],
            'the minute a row fires' => ['2026-10-21 09:00', 3],
            'the last warm minute' => ['2026-10-21 17:59', 3],
            'the minute the evening row fires' => ['2026-10-21 18:00', 0],
            'Saturday: Friday 18:00 still holds' => ['2026-10-24 12:00', 0],
            'Monday morning' => ['2026-10-26 09:00', 3],
        ];
    }

    #[DataProvider('officeMoments')]
    public function test_the_row_that_fired_last_decides(string $time, int $want): void
    {
        $this->assertSame($want, ScaleSchedule::valueAt(self::OFFICE, self::at($time), self::IST));
    }

    public function test_the_crons_run_in_the_given_zone(): void
    {
        // 06:30 UTC is 09:30 in Istanbul
        $instant = CarbonImmutable::parse('2026-10-21 06:30', 'UTC');

        $this->assertSame(3, ScaleSchedule::valueAt(self::OFFICE, $instant, self::IST));
        $this->assertSame(0, ScaleSchedule::valueAt(self::OFFICE, $instant, 'UTC'));
    }

    public function test_rows_firing_in_the_same_minute_keep_the_larger_count(): void
    {
        $rows = [['cron' => '0 9 * * *', 'min_scale' => 1], ['cron' => '0 9 * * *', 'min_scale' => 4]];

        $this->assertSame(4, ScaleSchedule::valueAt($rows, self::at('2026-10-21 10:00'), self::IST));
        $this->assertSame(4, ScaleSchedule::valueAt(array_reverse($rows), self::at('2026-10-21 10:00'), self::IST));
    }

    public function test_a_schedule_that_never_fired_keeps_nothing_warm(): void
    {
        $this->assertSame(0, ScaleSchedule::valueAt([['cron' => '0 0 30 2 *', 'min_scale' => 5]], self::at('2026-10-21 10:00'), self::IST));
    }

    public function test_a_monthly_row_holds_until_the_next_row_fires(): void
    {
        $rows = [['cron' => '0 0 1 * *', 'min_scale' => 2], ['cron' => '0 0 15 * *', 'min_scale' => 1]];

        $this->assertSame(1, ScaleSchedule::valueAt($rows, self::at('2026-10-21 10:00'), self::IST));
        $this->assertSame(2, ScaleSchedule::valueAt($rows, self::at('2026-11-03 10:00'), self::IST));
    }

    // ── Stretches over time ─────────────────────────────────────────────────

    public function test_segments_of_a_weekday(): void
    {
        $from = self::at('2026-10-21 00:00');
        $to = self::at('2026-10-22 00:00');
        $h = 3600;
        $start = $from->getTimestamp();

        $this->assertSame([
            [$start, $start + 9 * $h, 0],
            [$start + 9 * $h, $start + 18 * $h, 3],
            [$start + 18 * $h, $start + 24 * $h, 0],
        ], ScaleSchedule::segments(self::OFFICE, $from, $to, self::IST));

        $this->assertSame(9 * $h * 3, ScaleSchedule::containerSeconds(self::OFFICE, $from, $to, self::IST));
    }

    public function test_scale_to_zero_off_keeps_one_container(): void
    {
        $from = self::at('2026-10-21 00:00');
        $to = self::at('2026-10-22 00:00');

        $this->assertSame([1, 3, 1], array_column(ScaleSchedule::segments(self::OFFICE, $from, $to, self::IST, 1), 2));
        $this->assertSame((9 + 27 + 6) * 3600, ScaleSchedule::containerSeconds(self::OFFICE, $from, $to, self::IST, 1));
        $this->assertSame(0, ScaleSchedule::floor(true));
        $this->assertSame(0, ScaleSchedule::floor(null));
        $this->assertSame(1, ScaleSchedule::floor(false));
        $this->assertSame(1, ScaleSchedule::floor('0'));
    }

    public function test_segments_follow_daylight_saving_time(): void
    {
        // Berlin leaves summer time on Sunday 2026-10-25 at 03:00: 01:00 → 04:00 lasts four hours
        $rows = [['cron' => '0 1 * * *', 'min_scale' => 1], ['cron' => '0 4 * * *', 'min_scale' => 0]];
        $from = self::at('2026-10-24 12:00', 'Europe/Berlin');
        $to = self::at('2026-10-26 00:00', 'Europe/Berlin');

        $warm = array_values(array_filter(ScaleSchedule::segments($rows, $from, $to, 'Europe/Berlin'), fn ($s) => $s[2] === 1));
        $this->assertCount(1, $warm);
        $this->assertSame(CarbonImmutable::parse('2026-10-24 23:00', 'UTC')->getTimestamp(), $warm[0][0]);
        $this->assertSame(4 * 3600, $warm[0][1] - $warm[0][0]);
        $this->assertSame(4 * 3600, ScaleSchedule::containerSeconds($rows, $from, $to, 'Europe/Berlin'));
    }

    public function test_the_week_timeline_holds_the_most_warm_containers_of_each_hour(): void
    {
        $rows = [...self::OFFICE, ['cron' => '30 9 * * 1-5', 'min_scale' => 1]];
        $week = ScaleSchedule::week($rows, self::at('2026-10-21 10:30'), self::IST);

        $this->assertSame('2026-10-19T00:00:00+03:00', $week['week_start']->toIso8601String());
        $this->assertCount(7, $week['timeline']);
        $this->assertCount(24, $week['timeline'][0]);

        $monday = $week['timeline'][0];
        $this->assertSame(0, $monday[8]);
        $this->assertSame(3, $monday[9], '09:00 → 3, 09:30 → 1: the hour shows the most');
        $this->assertSame(1, $monday[10]);
        $this->assertSame(1, $monday[17]);
        $this->assertSame(0, $monday[18]);
        $this->assertSame(1, $week['timeline'][4][17], 'Friday');
        $this->assertSame(array_fill(0, 24, 0), $week['timeline'][5], 'Saturday');
        $this->assertSame(array_fill(0, 24, 0), $week['timeline'][6], 'Sunday');
    }

    // ── Rows ────────────────────────────────────────────────────────────────

    public function test_rows_are_normalized_to_their_stored_shape(): void
    {
        $this->assertNull(ScaleSchedule::normalize(null));
        $this->assertNull(ScaleSchedule::normalize([]));
        $this->assertNull(ScaleSchedule::normalize([['cron' => '', 'min_scale' => 1]]));

        $this->assertSame(
            [['cron' => '0 9 * * 1-5', 'min_scale' => 3], ['cron' => '0 18 * * 1-5', 'min_scale' => 0]],
            ScaleSchedule::normalize([
                ['cron' => '  0   9 * *   1-5 ', 'min_scale' => '3'],
                ['cron' => 'nope'],
                (object) ['cron' => '0 18 * * 1-5', 'min' => 0],
            ]),
        );
        $this->assertSame([['cron' => '* * * * *', 'min_scale' => 2]], ScaleSchedule::normalize('[{"cron":"* * * * *","min_scale":2}]'));
    }

    public function test_the_reservation_counts_the_peak_while_a_schedule_is_set(): void
    {
        $this->assertSame(3, ScaleSchedule::peak(self::OFFICE));
        $this->assertSame(3, ScaleSchedule::reservedMinScale(['min_scale' => 1, 'scale_schedule' => self::OFFICE]));
        $this->assertSame(3, ScaleSchedule::reservedMinScale((object) ['min_scale' => 5, 'scale_schedule' => self::OFFICE]));
        $this->assertSame(5, ScaleSchedule::reservedMinScale(['min_scale' => 5, 'scale_schedule' => []]));
        $this->assertSame(0, ScaleSchedule::reservedMinScale(null));
        $this->assertNull(ScaleSchedule::rows(['min_scale' => 2]));
    }

    // ── Cron fields ─────────────────────────────────────────────────────────

    public function test_cron_fields_expand_steps_ranges_and_names(): void
    {
        $this->assertSame([0, 15, 30, 45], CronFields::parse('*/15 * * * *')->minutes);
        $this->assertSame([5, 25, 45], CronFields::parse('5/20 * * * *')->minutes);
        $this->assertSame([9, 13, 17], CronFields::parse('0 9-17/4 * * *')->hours);
        $this->assertSame([0, 30], CronFields::parse('0,30 * * * *')->minutes);

        $weekdays = CronFields::parse('0 9 * * MON-FRI');
        $this->assertTrue($weekdays->matchesDay(self::at('2026-10-21')));
        $this->assertFalse($weekdays->matchesDay(self::at('2026-10-24')));

        $this->assertTrue(CronFields::parse('0 9 * * 7')->matchesDay(self::at('2026-10-25')), '7 is Sunday');
        $this->assertTrue(CronFields::parse('0 0 * jan *')->matchesDay(self::at('2027-01-05')));
        $this->assertFalse(CronFields::parse('0 0 * JAN *')->matchesDay(self::at('2026-10-21')));
    }

    public function test_day_of_month_and_day_of_week_match_either_when_both_are_restricted(): void
    {
        $fields = CronFields::parse('0 0 1 * MON');

        $this->assertTrue($fields->matchesDay(self::at('2026-11-01')), 'the 1st, a Sunday');
        $this->assertTrue($fields->matchesDay(self::at('2026-10-26')), 'a Monday');
        $this->assertFalse($fields->matchesDay(self::at('2026-10-27')));
        $this->assertFalse(CronFields::parse('0 0 * * MON')->matchesDay(self::at('2026-11-01')));
    }

    public function test_the_last_firing_minute_of_a_day(): void
    {
        $fields = CronFields::parse('0,30 9,18 * * *');

        $this->assertSame(540, $fields->lastMinuteOfDayUpTo(569));
        $this->assertSame(570, $fields->lastMinuteOfDayUpTo(570));
        $this->assertNull($fields->lastMinuteOfDayUpTo(539));
        $this->assertSame(1110, $fields->lastMinuteOfDayUpTo(1439));
    }

    public static function invalidCrons(): array
    {
        return [
            'four fields' => ['* * * *'],
            'six fields (seconds)' => ['0 * * * * *'],
            'minute 60' => ['60 * * * *'],
            'hour 24' => ['* 24 * * *'],
            'day 0' => ['* * 0 * *'],
            'month 13' => ['* * * 13 *'],
            'weekday 8' => ['* * * * 8'],
            'descending range' => ['5-1 * * * *'],
            'step 0' => ['*/0 * * * *'],
            'macro' => ['@daily'],
            'last day (Quartz)' => ['0 0 L * *'],
        ];
    }

    #[DataProvider('invalidCrons')]
    public function test_invalid_crons_are_refused(string $cron): void
    {
        $this->assertNull(CronFields::tryParse($cron));
        $this->assertFalse(ScaleScheduleCron::isValid($cron));
        $this->assertFalse(ScaleSchedule::understands($cron));

        $this->expectException(InvalidArgumentException::class);
        CronFields::parse($cron);
    }

    public function test_valid_warmer_crons(): void
    {
        foreach (['0 9 * * 1-5', '0 9 * * MON-FRI', '*/10 8-18 * * *', '30 6 1,15 * *', '0 0 * * 0'] as $cron) {
            $this->assertTrue(ScaleScheduleCron::isValid($cron), $cron);
        }
    }
}
