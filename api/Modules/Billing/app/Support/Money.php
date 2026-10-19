<?php

namespace Modules\Billing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Exact money arithmetic of the bills (docs/billing.md#how-a-bill-is-calculated): prices, markups
 * and quantities are exact decimals or fractions (brick/math, a dependency of the framework), never
 * floats. A line's amount is calculated exactly and rounded once, half up, to the minor unit of the
 * currency; a bill's totals are the sums of its rounded lines.
 *
 * Values read from the database are decimal strings on PostgreSQL; SQLite (the test suite) returns
 * floats for decimal columns, read back as the decimal they were stored as.
 */
final class Money
{
    /** ISO 4217 currencies without a minor unit. */
    private const NO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** ISO 4217 currencies with three decimals. */
    private const THREE_DECIMALS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** Decimals of the currency's minor unit (default: the installation's currency). */
    public static function minorUnit(?string $currency = null): int
    {
        $currency = strtoupper($currency ?? BillingContext::currency());

        return match (true) {
            in_array($currency, self::NO_DECIMALS, true) => 0,
            in_array($currency, self::THREE_DECIMALS, true) => 3,
            default => 2,
        };
    }

    /** An exact decimal of a database or configuration value (null and '' are 0). */
    public static function dec(BigNumber|int|float|string|null $value): BigDecimal
    {
        if ($value instanceof BigNumber) {
            return $value->toBigDecimal();
        }
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            // The shortest representation that reads back as the same float: the decimal the value
            // was stored as (database decimals, rounded amounts), never its binary approximation
            return BigDecimal::of(var_export($value, true));
        }

        return BigDecimal::of(is_int($value) ? $value : trim($value));
    }

    /** An exact fraction numerator / denominator (metered quantities: milliseconds per hour). */
    public static function fraction(int|string $numerator, int $denominator): BigRational
    {
        return BigRational::of(BigDecimal::of($numerator))->dividedBy($denominator);
    }

    /** Rounded half up to the minor unit (or $scale). */
    public static function round(BigNumber $amount, ?int $scale = null): BigDecimal
    {
        return $amount->toScale($scale ?? self::minorUnit(), RoundingMode::HalfUp);
    }

    /** The exact sum of amounts. */
    public static function sum(iterable $amounts): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($amounts as $amount) {
            $sum = $sum->plus(self::dec($amount));
        }

        return $sum;
    }

    /** For the API and JSON: a float of a value that is already rounded (exact in its shortest form). */
    public static function toFloat(BigNumber $amount): float
    {
        return (float) (string) $amount->toBigDecimal();
    }

    /** A float rounded half up to the minor unit (display values: unit prices, breakdowns). */
    public static function roundedFloat(BigNumber $amount, ?int $scale = null): float
    {
        return self::toFloat(self::round($amount, $scale));
    }
}
