<?php

namespace Modules\Billing\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Markup;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\RegionSetting;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\BillingPolicy;
use Modules\Billing\Support\Versions;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Support\TenantRegionShares;

/**
 * Shared platform regions from a tenant's side (docs/billing.md#sharing-a-region-with-a-tenant):
 * the platform shares a region → the share is pending and the tenant's billing managers are
 * notified → a tenant admin sees the region, its type and platform prices and a markup prefilled
 * from the tenant's default markup → accepts → the region is copied into the tenant and its users
 * can create projects there; the markup holds from the acceptance day.
 */
class RegionOffers
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly Regions $regions,
        private readonly RegionTypes $types,
        private readonly BillingEvents $events,
        private readonly BillingNotifier $notifier,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forTenant(Tenant $tenant): array
    {
        return TenantRegionShares::forTenant((string) $tenant->getKey())
            ->map(fn (object $share) => $this->offer($share))
            ->values()
            ->all();
    }

    public function find(Tenant $tenant, string $regionId): ?array
    {
        $share = TenantRegionShares::forTenant((string) $tenant->getKey())->first(fn (object $s) => (string) $s->knative_region_id === $regionId);

        return $share ? $this->offer($share) : null;
    }

    /** @return array<string, mixed> */
    private function offer(object $share): array
    {
        $regionId = (string) $share->knative_region_id;
        $region = $this->regions->findCentral($regionId);
        $current = $this->prices->current($regionId, PriceBook::PLATFORM);
        $active = ($share->status ?? TenantRegionShares::ACTIVE) === TenantRegionShares::ACTIVE;
        $markup = $this->prices->markupVersions($regionId)->filter(fn (Markup $m) => $m->effective_from->toDateString() <= Carbon::today()->toDateString())->last();

        return [
            'region' => ['id' => $regionId, 'name' => $region?->name],
            'status' => $active ? TenantRegionShares::ACTIVE : TenantRegionShares::PENDING,
            'offered_at' => $this->iso($share->offered_at ?? null),
            'accepted_at' => $this->iso($share->accepted_at ?? null),
            'billing_type' => $this->centralType($regionId),
            'currency' => BillingContext::currency(),
            'meters_gpu' => $region !== null && (string) ($region->getAttribute('gpu_mode') ?? 'none') !== 'none',
            'prices' => [
                'current' => $current->values + ['source' => $current->source, 'effective_from' => $current->effectiveFrom],
                'scheduled' => $this->scheduled($regionId),
            ],
            'markup_percent' => $markup ? round((float) $markup->percent, 3) : null,
            'default_markup_percent' => round($this->prices->defaultMarkupPercent(), 3),
        ];
    }

    /**
     * Accept a pending share with a markup (default: the tenant's default markup) from today.
     *
     * @throws BillingRefusal
     */
    public function accept(Tenant $tenant, string $regionId, ?float $percent, Authenticatable $user): array
    {
        $status = TenantRegionShares::status((string) $tenant->getKey(), $regionId);
        if ($status !== TenantRegionShares::PENDING) {
            throw new BillingRefusal('offer_not_pending', __('billing.offer_not_pending'), $status === null ? 404 : 409);
        }

        $percent ??= $this->prices->defaultMarkupPercent();
        $userId = (string) $user->getAuthIdentifier();

        // One step: the markup first (from the acceptance day; a version of today replaces an
        // earlier one of the same day), then the share — a failure of either leaves the offer
        // pending, never active without its markup. Announced only after the commit.
        DB::transaction(function () use ($tenant, $regionId, $percent, $userId) {
            Versions::put(Markup::class, $regionId, Carbon::today()->toDateString(), ['percent' => round($percent, 3), 'created_by' => $userId]);

            // Accepted by another request meanwhile: the markup is rolled back with this refusal
            if (! DB::connection(BillingContext::central())->transaction(fn () => TenantRegionShares::markAccepted($tenant, $regionId, $userId))) {
                throw new BillingRefusal('offer_not_pending', __('billing.offer_not_pending'), 409);
            }
        });

        TenantRegionShares::accepted($tenant, $regionId, $userId);
        $this->prices->flush();
        $this->regions->flush();
        $this->types->flush();

        $this->events->recordCentral(BillingEvents::REGION_SHARE_ACCEPTED, (string) $tenant->getKey(), [
            'tenant' => ['id' => (string) $tenant->getKey(), 'slug' => $tenant->getAttribute('slug'), 'name' => $tenant->getAttribute('name')],
            'region' => ['id' => $regionId, 'name' => $this->regions->findCentral($regionId)?->name],
            'billing_type' => $this->centralType($regionId),
            'markup_percent' => round($percent, 3),
            'accepted_by' => (string) $user->getAuthIdentifier(),
        ]);

        return $this->find($tenant, $regionId) ?? [];
    }

    /**
     * The platform shared a region with a tenant (RegionShareOffered): notify the tenant's billing
     * managers and record region_share.offered in the platform's log.
     */
    public function offered(Tenant $tenant, string $regionId): void
    {
        $region = $this->regions->findCentral($regionId);
        $data = [
            'tenant' => ['id' => (string) $tenant->getKey(), 'slug' => $tenant->getAttribute('slug'), 'name' => $tenant->getAttribute('name')],
            'region' => ['id' => $regionId, 'name' => $region?->name],
            'billing_type' => $this->centralType($regionId),
        ];

        $this->events->recordCentral(BillingEvents::REGION_SHARE_OFFERED, (string) $tenant->getKey(), $data);

        $notify = function () use ($region, $regionId, $data) {
            $this->notifier->notifyPermission('billing.manage', BillingNotifier::REGION_OFFER,
                __('billing.notify.offer_title', ['region' => $region->name ?? $regionId]),
                __('billing.notify.offer_body', ['region' => $region->name ?? $regionId, 'type' => __('billing.types.'.$data['billing_type'])]),
                ['region_id' => $regionId],
                '/settings?tab=billing&section=offers');
        };

        $tenant->run($notify);
    }

    private function centralType(string $regionId): string
    {
        $setting = RegionSetting::on(BillingContext::central())->find($regionId);

        return $setting->billing_type ?? BillingPolicy::DEFAULT_TYPE;
    }

    /** @return list<array<string, mixed>> scheduled platform price versions of the region (or the platform default) */
    private function scheduled(string $regionId): array
    {
        $today = Carbon::today()->toDateString();
        /** @var Collection<int, Price> $rows */
        $rows = $this->prices->versions($regionId, BillingContext::inTenant())->merge($this->prices->versions(null, BillingContext::inTenant()));

        return $rows->filter(fn (Price $p) => $p->effectiveDate() > $today)
            ->sortBy(fn (Price $p) => $p->effectiveDate())
            ->map(fn (Price $p) => $p->toApiArray())
            ->values()->all();
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
