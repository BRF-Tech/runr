<?php

namespace Modules\Billing\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A billing period: one calendar month in the application time zone ("2026-10").
 */
final class Period
{
    private function __construct(public readonly CarbonImmutable $start) {}

    public static function of(CarbonInterface|string|null $date = null): self
    {
        $day = $date === null ? CarbonImmutable::now() : CarbonImmutable::parse($date instanceof CarbonInterface ? $date->toDateString() : $date);

        return new self($day->startOfMonth()->startOfDay());
    }

    /** "YYYY-MM" (validated by the caller). */
    public static function fromKey(string $key): self
    {
        $date = self::isKey($key) ? CarbonImmutable::createFromFormat('!Y-m', $key) : null;
        if (! $date instanceof CarbonImmutable) {
            throw new \InvalidArgumentException("Not a billing period: {$key}");
        }

        return new self($date->startOfMonth());
    }

    public static function current(): self
    {
        return self::of(CarbonImmutable::now());
    }

    public static function isKey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    public function key(): string
    {
        return $this->start->format('Y-m');
    }

    public function end(): CarbonImmutable
    {
        return $this->start->endOfMonth()->startOfDay();
    }

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end()->toDateString();
    }

    public function days(): int
    {
        return $this->start->daysInMonth;
    }

    public function previous(): self
    {
        return new self($this->start->subMonthNoOverflow());
    }

    public function next(): self
    {
        return new self($this->start->addMonthNoOverflow());
    }

    public function isCurrent(): bool
    {
        return $this->key() === self::current()->key();
    }

    public function hasEnded(): bool
    {
        return $this->end()->lt(CarbonImmutable::today());
    }

    public function contains(string $date): bool
    {
        return $date >= $this->startDate() && $date <= $this->endDate();
    }
}
