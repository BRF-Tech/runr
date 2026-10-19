<?php

namespace Modules\Billing\Support;

use Brick\Math\BigDecimal;

/**
 * The prices that apply to a region on a day (PriceBook::resolve()): every price field, where they
 * come from and the markup on top of them (a tenant's markup on a shared platform region).
 *
 *   source  region | tenant_default | platform_default | default (the seeded DefaultPrices)
 *
 * values / markupPercent are for display; bills are calculated from the exact decimals (exact(),
 * markupFactorExact(), Money).
 */
final class ResolvedPrice
{
    /** @var array<string, BigDecimal> */
    private readonly array $exact;

    private readonly BigDecimal $markupExact;

    /**
     * @param  array<string, float>  $values
     * @param  array<string, BigDecimal>  $exact  exact decimals of the values (default: read from $values)
     */
    public function __construct(
        public readonly array $values,
        public readonly string $source,
        public readonly ?string $priceId,
        public readonly ?string $effectiveFrom,
        public readonly float $markupPercent = 0.0,
        public readonly ?string $markupId = null,
        array $exact = [],
        ?BigDecimal $markupExact = null,
    ) {
        $decimals = [];
        foreach ($values as $field => $value) {
            $decimals[$field] = $exact[$field] ?? Money::dec($value);
        }
        $this->exact = $decimals;
        $this->markupExact = $markupExact ?? Money::dec($markupPercent);
    }

    public function get(string $field): float
    {
        return (float) ($this->values[$field] ?? DefaultPrices::VALUES[$field] ?? 0.0);
    }

    /** The exact price of a field. */
    public function exact(string $field): BigDecimal
    {
        return $this->exact[$field] ?? Money::dec(DefaultPrices::VALUES[$field] ?? 0);
    }

    /** 1 + markup / 100 */
    public function markupFactor(): float
    {
        return 1 + $this->markupPercent / 100;
    }

    /** 1 + markup / 100, exact */
    public function markupFactorExact(): BigDecimal
    {
        return BigDecimal::one()->plus($this->markupExact->exactlyDividedBy(100));
    }

    public function multiplier(?string $entryPoint): float
    {
        $field = $this->multiplierField($entryPoint);

        return $field === null ? 1.0 : $this->get($field);
    }

    /** The trigger multiplier, exact (1 for other entry points). */
    public function multiplierExact(?string $entryPoint): BigDecimal
    {
        $field = $this->multiplierField($entryPoint);

        return $field === null ? BigDecimal::one() : $this->exact($field);
    }

    private function multiplierField(?string $entryPoint): ?string
    {
        return match ($entryPoint) {
            'queue' => 'multiplier_queue',
            'schedule' => 'multiplier_schedule',
            'http', null, '' => 'multiplier_http',
            default => null,
        };
    }

    public function withMarkup(float $percent, ?string $markupId, ?BigDecimal $exact = null): self
    {
        return new self($this->values, $this->source, $this->priceId, $this->effectiveFrom, $percent, $markupId, $this->exact, $exact);
    }

    /** Group key of a line: same price version and markup version. */
    public function versionKey(): string
    {
        return ($this->priceId ?? $this->source).'|'.($this->markupId ?? round($this->markupPercent, 3));
    }

    public function toArray(): array
    {
        return $this->values + [
            'source' => $this->source,
            'price_id' => $this->priceId,
            'effective_from' => $this->effectiveFrom,
            'markup_percent' => round($this->markupPercent, 3),
        ];
    }
}
