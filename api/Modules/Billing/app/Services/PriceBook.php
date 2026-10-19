<?php

namespace Modules\Billing\Services;

use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Billing\Models\Markup;
use Modules\Billing\Models\Price;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\DefaultPrices;
use Modules\Billing\Support\Money;
use Modules\Billing\Support\ResolvedPrice;

/**
 * Price resolution (docs/billing.md#price-resolution). No region is ever unpriced and a missing
 * price never counts as zero: the chain always ends at the seeded DefaultPrices. Within a scope the
 * version in effect on a day is the one with the latest effective_from on or before that day.
 *
 *  install / platform, any region   region version → default version → DefaultPrices
 *  tenant, its own region           tenant region version → tenant default → platform default → DefaultPrices
 *  tenant, shared platform region   platform region version → platform default → DefaultPrices,
 *                                   plus the tenant's markup (region markup → default markup → 0)
 *  platform → tenant bills          platform region version → platform default → DefaultPrices, no markup
 */
class PriceBook
{
    public const CONTEXT = 'context';

    public const PLATFORM = 'platform';

    /** @var array<string, Collection<int, Price>> */
    private array $rows = [];

    /** @var array<string, Collection<int, Markup>> */
    private array $markups = [];

    public function __construct(private readonly Regions $regions) {}

    public function resolve(?string $regionId, CarbonInterface|string $day, string $mode = self::CONTEXT): ResolvedPrice
    {
        $date = $day instanceof CarbonInterface ? $day->toDateString() : Carbon::parse($day)->toDateString();

        if ($mode === self::PLATFORM) {
            return $this->firstOf($this->platformChain($regionId), $date);
        }

        if (! BillingContext::inTenant()) {
            return $this->firstOf([
                ...($regionId !== null ? [['region', null, $regionId]] : []),
                ['platform_default', null, null],
            ], $date);
        }

        if ($regionId !== null && $this->regions->isShared($regionId)) {
            $price = $this->firstOf($this->platformChain($regionId), $date);
            [$percent, $markupId, $exact] = $this->markup($regionId, $date);

            return $price->withMarkup($percent, $markupId, $exact);
        }

        return $this->firstOf([
            ...($regionId !== null ? [['region', null, $regionId]] : []),
            ['tenant_default', null, null],
            ['platform_default', BillingContext::central(), null],
        ], $date);
    }

    public function current(?string $regionId, string $mode = self::CONTEXT): ResolvedPrice
    {
        return $this->resolve($regionId, Carbon::today(), $mode);
    }

    /**
     * What applies to the organization's regions when it has no default version of its own: the
     * platform default (tenant) or the seeded defaults (install / platform).
     */
    public function inheritedDefault(?CarbonInterface $day = null): ResolvedPrice
    {
        $date = ($day ?? Carbon::today())->toDateString();

        return BillingContext::inTenant()
            ? $this->firstOf([['platform_default', BillingContext::central(), null]], $date)
            : $this->seeded();
    }

    /**
     * Versions of one scope of the organization (region id, or null for its default prices),
     * oldest first.
     *
     * @return Collection<int, Price>
     */
    public function versions(?string $regionId, bool $central = false): Collection
    {
        $connection = $central ? BillingContext::central() : null;
        $key = ($central ? 'central:'.($connection ?? '') : BillingContext::key()).'|'.($regionId ?? '*');

        if (! isset($this->rows[$key])) {
            try {
                $this->rows[$key] = Price::on($connection)
                    ->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))
                    ->orderBy('effective_from')
                    ->orderBy('created_at')
                    ->get();
            } catch (QueryException) {
                $this->rows[$key] = new Collection;
            }
        }

        return $this->rows[$key];
    }

    /** The version of a scope in effect on $date, null when none. */
    public function versionOn(?string $regionId, string $date, bool $central = false): ?Price
    {
        return $this->versions($regionId, $central)
            ->filter(fn (Price $p) => $p->effectiveDate() <= $date)
            ->last();
    }

    /**
     * The tenant's markup on a region on $date: the region's version, else the default version,
     * else 0. Outside a tenant: 0.
     *
     * @return array{0: float, 1: string|null}
     */
    public function markupOn(?string $regionId, string $date): array
    {
        [$percent, $id] = $this->markup($regionId, $date);

        return [$percent, $id];
    }

    /**
     * markupOn() with the exact percentage.
     *
     * @return array{0: float, 1: string|null, 2: BigDecimal}
     */
    private function markup(?string $regionId, string $date): array
    {
        if (! BillingContext::inTenant()) {
            return [0.0, null, BigDecimal::zero()];
        }

        foreach ($regionId !== null ? [$regionId, null] : [null] as $scope) {
            $markup = $this->markupVersions($scope)->filter(fn (Markup $m) => $m->effective_from->toDateString() <= $date)->last();
            if ($markup) {
                return [(float) $markup->percent, $markup->id, Money::dec($markup->getAttributes()['percent'] ?? null)];
            }
        }

        return [0.0, null, BigDecimal::zero()];
    }

    /** @return Collection<int, Markup> */
    public function markupVersions(?string $regionId): Collection
    {
        $key = BillingContext::key().'|'.($regionId ?? '*');

        if (! isset($this->markups[$key])) {
            try {
                $this->markups[$key] = Markup::query()
                    ->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))
                    ->orderBy('effective_from')
                    ->orderBy('created_at')
                    ->get();
            } catch (QueryException) {
                $this->markups[$key] = new Collection;
            }
        }

        return $this->markups[$key];
    }

    public function defaultMarkupPercent(?CarbonInterface $day = null): float
    {
        return $this->markupOn(null, ($day ?? Carbon::today())->toDateString())[0];
    }

    public function flush(): void
    {
        $this->rows = [];
        $this->markups = [];
    }

    public function seeded(): ResolvedPrice
    {
        return new ResolvedPrice(DefaultPrices::VALUES, 'default', null, null);
    }

    /** @return list<array{0: string, 1: string|null, 2: string|null}> */
    private function platformChain(?string $regionId): array
    {
        $central = BillingContext::central();

        return [
            ...($regionId !== null ? [['region', $central, $regionId]] : []),
            ['platform_default', $central, null],
        ];
    }

    /**
     * The first scope of the chain with a version in effect on $date.
     *
     * @param  list<array{0: string, 1: string|null, 2: string|null}>  $chain  [source, connection (central or null = the organization's), region id or null]
     */
    private function firstOf(array $chain, string $date): ResolvedPrice
    {
        foreach ($chain as [$source, $connection, $regionId]) {
            $central = $connection !== null && BillingContext::inTenant();
            $version = $this->versionOn($regionId, $date, $central);
            if ($version) {
                return new ResolvedPrice($version->values(), $source, $version->id, $version->effectiveDate(), exact: $version->exactValues());
            }
        }

        return $this->seeded();
    }
}
