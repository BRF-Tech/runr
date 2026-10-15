<?php

namespace Modules\Tenancy\Services\Billing;

use App\Models\KnativeRegion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\CapacityDeal;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;

/**
 * Charges — what each tenant owes per billing period (calendar month), docs/billing.md:
 *
 *  - ensureCharges():     the month's capacity-deal fees and usage charges (daily, idempotent);
 *  - closeEndedPeriods(): every open charge of a month that has ended gets its final line items,
 *                         total, the credit it draws from the tenant's balance and its amount due,
 *                         then one billing.period.closed event per tenant and month;
 *  - estimate():          line items and total of an open charge as of now (provisional);
 *  - void():              a charge that is not owed (billing.charge.voided when it was closed).
 *
 * Usage charges: one per tenant, month and currency of its usage — for every tenant while plans
 * are off, otherwise for tenants without a plan or on a plan with the metered_usage feature.
 */
class ChargeService
{
    public function __construct(
        private readonly ChargeLineItemBuilder $builder,
        private readonly UsageRating $rating,
        private readonly CreditLedger $credits,
        private readonly BillingEvents $events,
        private readonly BillingSettings $settings,
    ) {}

    // ── Creating ─────────────────────────────────────────────────────────────

    /**
     * Capacity-deal and usage charges of the month $at falls in.
     *
     * @return int charges created
     */
    public function ensureCharges(?Carbon $at = null): int
    {
        $at = ($at ?? now())->copy();

        return $this->ensureDealCharges($at) + $this->ensureUsageCharges($at);
    }

    private function ensureDealCharges(Carbon $at): int
    {
        $start = $at->copy()->startOfMonth();
        $end = $at->copy()->endOfMonth();
        $created = 0;

        $deals = CapacityDeal::query()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get();

        foreach ($deals as $deal) {
            $exists = Charge::query()
                ->where('capacity_deal_id', $deal->id)
                ->whereDate('period_start', $start->toDateString())
                ->exists();
            if ($exists) {
                continue;
            }

            Charge::query()->create([
                'tenant_id' => $deal->tenant_id,
                'capacity_deal_id' => $deal->id,
                'kind' => Charge::KIND_CAPACITY_DEAL,
                'amount' => $deal->monthly_price,
                'currency' => strtoupper((string) ($deal->currency ?: $this->defaultCurrency())),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'status' => Charge::STATUS_OPEN,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Usage charge in the tenant's billing currency for every metered tenant (further currencies
     * get theirs when the period closes and usage in them exists).
     */
    private function ensureUsageCharges(Carbon $at): int
    {
        $start = $at->copy()->startOfMonth();
        $created = 0;

        foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get() as $tenant) {
            /** @var Tenant $tenant */
            if ($this->metersUsage((string) $tenant->id)
                && $this->usageCharge((string) $tenant->id, $start, $this->currencyOf((string) $tenant->id))->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Usage is charged for a tenant while plans are off, without a plan, or on a plan with the
     * metered_usage feature.
     */
    public function metersUsage(string $tenantId): bool
    {
        if (! $this->settings->plansEnabled()) {
            return true;
        }

        $subscription = TenantSubscription::query()->with('plan')->where('tenant_id', $tenantId)->first();
        if (! $subscription?->plan || $subscription->status === TenantSubscription::STATUS_CANCELED) {
            return true;
        }

        return $subscription->plan->metersUsage();
    }

    private function usageCharge(string $tenantId, Carbon $monthStart, string $currency): Charge
    {
        $existing = Charge::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', Charge::KIND_USAGE)
            ->where('currency', $currency)
            ->whereDate('period_start', $monthStart->toDateString())
            ->first();

        return $existing ?? Charge::query()->create([
            'tenant_id' => $tenantId,
            'kind' => Charge::KIND_USAGE,
            'amount' => 0,
            'currency' => $currency,
            'period_start' => $monthStart->toDateString(),
            'period_end' => $monthStart->copy()->endOfMonth()->toDateString(),
            'status' => Charge::STATUS_OPEN,
        ]);
    }

    /**
     * A one-off charge (platform administrator, billing API).
     *
     * @param  array{amount: float|int|string, currency?: ?string, description: string, period_start?: ?string, period_end?: ?string}  $data
     */
    public function createManual(Tenant $tenant, array $data): Charge
    {
        $start = Carbon::parse($data['period_start'] ?? now()->toDateString())->startOfDay();
        $end = isset($data['period_end']) ? Carbon::parse($data['period_end'])->startOfDay() : $start->copy();

        return Charge::query()->create([
            'tenant_id' => (string) $tenant->id,
            'kind' => Charge::KIND_MANUAL,
            'amount' => round((float) $data['amount'], 2),
            'currency' => strtoupper((string) ($data['currency'] ?? $this->currencyOf((string) $tenant->id))),
            'period_start' => $start->toDateString(),
            'period_end' => max($start, $end)->toDateString(),
            'status' => Charge::STATUS_OPEN,
            'notes' => mb_substr(trim($data['description']), 0, 255),
        ]);
    }

    // ── Calculating ──────────────────────────────────────────────────────────

    /**
     * Line items and total of a charge as of now.
     *
     * @return array{line_items: list<array>, total: float}
     */
    public function estimate(Charge $charge): array
    {
        if ($charge->kind === Charge::KIND_USAGE) {
            $items = $this->rating->items(
                (string) $charge->tenant_id,
                $charge->period_start->toDateString(),
                $charge->period_end->toDateString(),
                (string) $charge->currency,
            );
        } else {
            $deal = $charge->capacity_deal_id ? $charge->capacityDeal : null;
            $region = $deal ? KnativeRegion::whereKey($deal->region_id)->value('name') : null;
            $item = $this->builder->fixedItem($charge, $deal, $region);
            $items = $item ? [$item] : [];
        }

        return ['line_items' => $items, 'total' => ChargeLineItemBuilder::total($items)];
    }

    /**
     * API shape of a charge — open charges with their estimate.
     */
    public function present(Charge $charge, bool $withEstimate = true): array
    {
        return $charge->toApiArray($charge->isOpen() && $withEstimate ? $this->estimate($charge) : null);
    }

    // ── Closing ──────────────────────────────────────────────────────────────

    /**
     * Close every open charge whose billing period (the month of period_start) ended before $now.
     *
     * @return int charges closed
     */
    public function closeEndedPeriods(?Carbon $now = null): int
    {
        $monthStart = ($now ?? now())->copy()->startOfMonth();

        $this->addUsageCurrencies($monthStart);

        $open = Charge::query()
            ->where('status', Charge::STATUS_OPEN)
            ->whereDate('period_start', '<', $monthStart->toDateString())
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();

        $closed = 0;
        foreach ($open->groupBy(fn (Charge $c) => $c->tenant_id.'|'.$c->billingPeriod()) as $charges) {
            /** @var Charge $first */
            $first = $charges->first();
            try {
                $this->closePeriod((string) $first->tenant_id, $first->billingPeriod(), $charges->values());
                $closed += $charges->count();
            } catch (\Throwable $e) {
                // One tenant's failure (schema not reachable, …) does not stop the others; retried next run
                Log::error('Closing billing period failed', ['tenant' => $first->tenant_id, 'period' => $first->billingPeriod(), 'error' => $e->getMessage()]);
            }
        }

        return $closed;
    }

    /**
     * Usage in a currency other than the tenant's billing currency gets a usage charge of its own
     * before the month closes.
     */
    private function addUsageCurrencies(Carbon $monthStart): void
    {
        $usageCharges = Charge::query()
            ->where('kind', Charge::KIND_USAGE)
            ->where('status', Charge::STATUS_OPEN)
            ->whereDate('period_start', '<', $monthStart->toDateString())
            ->get();

        foreach ($usageCharges->groupBy(fn (Charge $c) => $c->tenant_id.'|'.$c->period_start->toDateString()) as $group) {
            /** @var Charge $first */
            $first = $group->first();
            try {
                $totals = $this->rating->totals((string) $first->tenant_id, $first->period_start->toDateString(), $first->period_end->toDateString());
            } catch (\Throwable $e) {
                Log::warning('Usage currencies not read', ['tenant' => $first->tenant_id, 'error' => $e->getMessage()]);

                continue;
            }
            $have = $group->pluck('currency')->map(fn ($c) => strtoupper((string) $c))->all();
            foreach (array_keys($totals) as $currency) {
                if (! in_array($currency, $have, true)) {
                    $this->usageCharge((string) $first->tenant_id, $first->period_start->copy(), $currency);
                }
            }
        }
    }

    /**
     * Final amounts of one tenant's charges of one month, credit drawn per currency, one event.
     *
     * @param  Collection<int, Charge>  $charges
     */
    private function closePeriod(string $tenantId, string $period, Collection $charges): void
    {
        // Still open (a parallel run may have closed them)
        $charges = Charge::query()
            ->whereIn('id', $charges->pluck('id')->all())
            ->where('status', Charge::STATUS_OPEN)
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();
        if ($charges->isEmpty()) {
            return;
        }

        $estimates = $charges->mapWithKeys(fn (Charge $c) => [$c->id => $this->estimate($c)]);

        DB::connection((new Charge)->getConnectionName())->transaction(function () use ($tenantId, $charges, $estimates) {
            $balances = $this->credits->balances($tenantId);

            foreach ($charges as $charge) {
                $estimate = $estimates[$charge->id];
                $currency = strtoupper((string) $charge->currency);
                $credit = min($balances[$currency] ?? 0.0, $estimate['total']);
                $credit = max(0.0, round($credit, 2));
                if ($credit > 0) {
                    $balances[$currency] = round($balances[$currency] - $credit, 2);
                }

                $charge->forceFill([
                    'status' => Charge::STATUS_CLOSED,
                    'line_items' => $estimate['line_items'],
                    'total' => $estimate['total'],
                    'credit_applied' => $credit,
                    'amount_due' => round($estimate['total'] - $credit, 2),
                    'closed_at' => now(),
                ])->save();
            }
        });

        // A charge added later for the same month closes in a later run: a new event with the whole month
        $ids = $charges->pluck('id')->sort()->values()->all();
        $this->events->record(
            BillingEvents::PERIOD_CLOSED,
            $tenantId,
            ['closed_charge_ids' => $ids] + $this->periodPayload($tenantId, $period),
            "period.closed:{$tenantId}:{$period}:".sha1(implode(',', $ids)),
        );
    }

    /**
     * data of billing.period.closed: every closed charge of the tenant in the month, totals per
     * currency and the remaining credit.
     */
    public function periodPayload(string $tenantId, string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
        $charges = Charge::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Charge::STATUS_CLOSED)
            ->whereDate('period_start', '>=', $start->toDateString())
            ->whereDate('period_start', '<=', $start->copy()->endOfMonth()->toDateString())
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();

        $totals = [];
        foreach ($charges as $charge) {
            $currency = strtoupper((string) $charge->currency);
            $totals[$currency] ??= ['total' => 0.0, 'credit_applied' => 0.0, 'amount_due' => 0.0];
            $totals[$currency]['total'] = round($totals[$currency]['total'] + (float) $charge->total, 2);
            $totals[$currency]['credit_applied'] = round($totals[$currency]['credit_applied'] + (float) $charge->credit_applied, 2);
            $totals[$currency]['amount_due'] = round($totals[$currency]['amount_due'] + (float) $charge->amount_due, 2);
        }
        ksort($totals);

        return [
            'period' => $period,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'charges' => $charges->map(fn (Charge $c) => $c->toApiArray())->values()->all(),
            'totals' => (object) $totals,
            'credit_balances' => (object) $this->credits->balances($tenantId),
        ];
    }

    // ── Voiding ──────────────────────────────────────────────────────────────

    public function void(Charge $charge, ?string $reason = null): Charge
    {
        if ($charge->status === Charge::STATUS_VOID) {
            return $charge;
        }

        $wasClosed = $charge->status === Charge::STATUS_CLOSED;
        $charge->forceFill([
            'status' => Charge::STATUS_VOID,
            'voided_at' => now(),
            // Credit drawn by the charge goes back to the balance (CreditLedger counts closed charges)
            'credit_applied' => 0,
            'amount_due' => 0,
            'notes' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : $charge->notes,
        ])->save();

        if ($wasClosed) {
            $this->events->record(BillingEvents::CHARGE_VOIDED, (string) $charge->tenant_id, ['charge' => $charge->toApiArray()]);
        }

        return $charge;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function defaultCurrency(): string
    {
        return strtoupper((string) config('tenancy.billing.default_currency', 'USD'));
    }

    /** The tenant's billing currency: its plan's, else the install default. */
    public function currencyOf(string $tenantId): string
    {
        $plan = $this->settings->plansEnabled()
            ? TenantSubscription::query()->with('plan')->where('tenant_id', $tenantId)->first()?->plan
            : null;

        return strtoupper((string) ($plan?->currency ?: $this->defaultCurrency()));
    }
}
