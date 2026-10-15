<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\Duration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public static function durations(): array
    {
        return [
            ['500ms', 500],
            ['30s', 30_000],
            ['15m', 900_000],
            ['2h', 7_200_000],
            ['1d', 86_400_000],
            ['1w', 604_800_000],
            ['1h30m', 5_400_000],
            ['1d2h3m4s', 93_784_000],
            ['1.5h', 5_400_000],
            ['PT1H30M', 5_400_000],
            ['P1D', 86_400_000],
            ['P1W', 604_800_000],
            ['PT0.5S', 500],
            ['90', 90_000],
            [' 2H ', 7_200_000],
        ];
    }

    #[DataProvider('durations')]
    public function test_parses(string $input, int $ms): void
    {
        $this->assertSame($ms, Duration::milliseconds($input));
    }

    public function test_numbers_are_seconds(): void
    {
        $this->assertSame(1_500, Duration::milliseconds(1.5));
        $this->assertSame(60_000, Duration::milliseconds(60));
        $this->assertSame(1, Duration::seconds('500ms'));
        $this->assertSame(3600, Duration::seconds('1h'));
    }

    public static function invalid(): array
    {
        return [[''], ['abc'], ['1y'], ['h1'], ['1h x'], ['P'], ['PT'], ['-5s'], ['5 s']];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid(string $input): void
    {
        $this->assertFalse(Duration::isValid($input));
        $this->expectException(InvalidArgumentException::class);
        Duration::milliseconds($input);
    }

    public function test_rejects_negative_numbers_and_non_strings(): void
    {
        $this->assertFalse(Duration::isValid(-1));
        $this->assertFalse(Duration::isValid(null));
        $this->assertFalse(Duration::isValid(['1h']));
    }

    public function test_parse_instant(): void
    {
        $this->assertSame(1_700_000_000_000, Duration::parseInstant('2023-11-14T22:13:20Z')->getTimestampMs());
        $this->assertSame(1_700_000_000_000, Duration::parseInstant(1_700_000_000_000)->getTimestampMs());
        $this->assertSame(1_700_000_000_000, Duration::parseInstant('1700000000000')->getTimestampMs());

        $this->expectException(InvalidArgumentException::class);
        Duration::parseInstant('not a date at all');
    }
}
