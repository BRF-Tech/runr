<?php

namespace Modules\Tenancy\Services\Plans;

use Illuminate\Support\Carbon;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Billing\BillingEvents;

/**
 * Plan lifecycle of a tenant (docs/billing.md). RunR calculates — it never collects:
 *
 *  - assign():        a plan put into effect by a platform administrator, the billing API or
 *                     self-service signup; its fee is a plan charge per period (free plans none);
 *  - applyPurchase(): a plan term bought in an external system (plan purchase) — prepaid, no plan
 *                     fee; buying the same plan again extends the term;
 *  - cancel():        at period end (default) or now;
 *  - renewPeriods():  daily — periods roll over (next plan fee), trials convert, prepaid terms and
 *                     cancelled plans end (fallback: the free default plan, else canceled).
 *
 * Plan fees are per period and not prorated: a plan change starts a new period with the new plan's
 * fee; the charge of the period that was running stays as calculated. Every plan that becomes
 * effective writes its quota onto the tenant's regions (PlanQuotaService); every change raises
 * billing.plan.changed.
 */
class PlanService
{
    public function __construct(
        private readonly PlanQuotaService $quota,
        private readonly BillingEvents $events,
    ) {}

    /**
     * Put a plan into effect (null plan = no plan; quotas stay as they are).
     *
     * @param  array{source?: string, external_ref?: ?string, prepaid?: bool}  $options
     */
    public function assign(Tenant $tenant, ?Plan $plan, string $interval = 'month', array $options = []): TenantSubscription
    {
        $subscription = TenantSubscription::forTenant((string) $tenant->id);
        $interval = in_array($interval, Plan::INTERVALS, true) ? $interval : 'month';
        $prepaid = (bool) ($options['prepaid'] ?? false);
        $before = $this->snapshot($subscription);

        if (array_key_exists('external_ref', $options)) {
            $subscription->external_ref = $options['external_ref'] !== null ? mb_substr((string) $options['external_ref'], 0, 191) : null;
        }

        $samePlan = $plan
            && $subscription->plan_id === $plan->id
            && $subscription->interval === $interval
            && (bool) $subscription->prepaid === $prepaid
            && $subscription->status !== TenantSubscription::STATUS_CANCELED
            && $subscription->current_period_end?->isFuture();

        if ($samePlan) {
            // Assigned again: the running period stays, a pending cancellation is lifted
            $subscription->cancel_at_period_end = false;
            $subscription->save();
            $this->changed($tenant, $subscription, $before, $options['source'] ?? 'api');

            return $subscription->fresh('plan');
        }

        if (! $plan) {
            $subscription->forceFill([
                'plan_id' => null,
                'interval' => $interval,
                'status' => TenantSubscription::STATUS_ACTIVE,
                'prepaid' => false,
                'trial_ends_at' => null,
                'current_period_start' => null,
                'current_period_end' => null,
                'cancel_at_period_end' => false,
            ])->save();
            $this->changed($tenant, $subscription, $before, $options['source'] ?? 'api');

            return $subscription->fresh('plan');
        }

        $trial = ! $prepaid && (int) $plan->trial_days > 0 && ! $subscription->trial_used;

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'interval' => $interval,
            'status' => $trial ? TenantSubscription::STATUS_TRIALING : TenantSubscription::STATUS_ACTIVE,
            'prepaid' => $prepaid,
            'trial_ends_at' => $trial ? now()->addDays((int) $plan->trial_days) : null,
            'trial_used' => $subscription->trial_used || $trial,
            'current_period_start' => now(),
            // A trial is the first period; the plan fee is calculated from the period after it
            'current_period_end' => $trial ? now()->addDays((int) $plan->trial_days) : self::periodEnd(now(), $interval),
            'cancel_at_period_end' => false,
        ])->save();

        $this->quota->apply($tenant, $plan);

        if (! $trial && ! $prepaid) {
            $this->recordPeriodFee($subscription);
        }

        $this->changed($tenant, $subscription, $before, $options['source'] ?? 'api');

        return $subscription->fresh('plan');
    }

    /**
     * A plan term bought in an external system: prepaid, no plan fee. The same plan and interval
     * bought again while its prepaid term runs extends the term by one interval.
     */
    public function applyPurchase(Tenant $tenant, Plan $plan, string $interval, Purchase $purchase): TenantSubscription
    {
        $interval = in_array($interval, Plan::INTERVALS, true) ? $interval : 'month';
        $subscription = TenantSubscription::forTenant((string) $tenant->id);

        $extends = $subscription->plan_id === $plan->id
            && $subscription->interval === $interval
            && $subscription->prepaid
            && $subscription->status !== TenantSubscription::STATUS_CANCELED
            && $subscription->current_period_end?->isFuture();

        if (! $extends) {
            return $this->assign($tenant, $plan, $interval, ['prepaid' => true, 'source' => 'purchase', 'external_ref' => $purchase->external_ref]);
        }

        $before = $this->snapshot($subscription);
        $subscription->forceFill([
            'current_period_end' => self::periodEnd($subscription->current_period_end, $interval),
            'external_ref' => $purchase->external_ref,
        ])->save();
        $this->changed($tenant, $subscription, $before, 'purchase');

        return $subscription->fresh('plan');
    }

    public function cancel(Tenant $tenant, bool $atPeriodEnd = true, string $source = 'api'): TenantSubscription
    {
        $subscription = TenantSubscription::forTenant((string) $tenant->id);

        if ($atPeriodEnd && $subscription->plan_id && $subscription->current_period_end?->isFuture()) {
            $before = $this->snapshot($subscription);
            $subscription->forceFill(['cancel_at_period_end' => true])->save();
            $this->changed($tenant, $subscription, $before, $source);

            return $subscription->fresh('plan');
        }

        return $this->end($subscription, $source);
    }

    /** Lift a cancellation at period end: the plan renews again. */
    public function keep(Tenant $tenant, string $source = 'api'): TenantSubscription
    {
        $subscription = TenantSubscription::forTenant((string) $tenant->id);

        if ($subscription->cancel_at_period_end) {
            $before = $this->snapshot($subscription);
            $subscription->forceFill(['cancel_at_period_end' => false])->save();
            $this->changed($tenant, $subscription, $before, $source);
        }

        return $subscription->fresh('plan');
    }

    public static function periodEnd(Carbon $start, string $interval): Carbon
    {
        return $interval === 'year' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();
    }

    /**
     * The plan fee of the subscription's current period as a plan charge (once per period). Free
     * plans and prepaid terms record nothing.
     */
    public function recordPeriodFee(TenantSubscription $subscription): ?Charge
    {
        $plan = $subscription->plan()->first();
        if (! $plan || $subscription->prepaid) {
            return null;
        }

        $amount = $plan->price($subscription->interval);
        if ($amount === null || $amount <= 0 || ! $subscription->current_period_start || ! $subscription->current_period_end) {
            return null;
        }

        $start = $subscription->current_period_start->copy()->startOfDay();
        $end = $subscription->current_period_end->copy()->subDay()->startOfDay();
        $notes = sprintf('Plan %s (%s) %s – %s', $plan->name, $subscription->interval, $start->toDateString(), max($start, $end)->toDateString());

        $exists = Charge::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('kind', Charge::KIND_PLAN)
            ->whereDate('period_start', $start->toDateString())
            ->where('notes', $notes)
            ->whereIn('status', [Charge::STATUS_OPEN, Charge::STATUS_CLOSED])
            ->exists();

        if ($exists) {
            return null;
        }

        return Charge::query()->create([
            'tenant_id' => $subscription->tenant_id,
            'kind' => Charge::KIND_PLAN,
            'amount' => $amount,
            'currency' => strtoupper($plan->currency),
            'period_start' => $start->toDateString(),
            'period_end' => max($start, $end)->toDateString(),
            'status' => Charge::STATUS_OPEN,
            'notes' => $notes,
        ]);
    }

    /**
     * Scheduled (PlanRenewalJob, daily): periods that ended roll over and record the next plan
     * fee; trials convert; prepaid terms and plans cancelled at period end end.
     *
     * @return array{renewed: int, ended: int}
     */
    public function renewPeriods(?Carbon $now = null): array
    {
        $now ??= now();
        $counts = ['renewed' => 0, 'ended' => 0];

        $due = TenantSubscription::query()
            ->whereNotNull('plan_id')
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIALING])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->get();

        foreach ($due as $subscription) {
            if ($subscription->cancel_at_period_end || $subscription->prepaid) {
                $this->end($subscription, 'renewal');
                $counts['ended']++;

                continue;
            }

            $before = $this->snapshot($subscription);
            $start = $subscription->current_period_end->copy();
            // Catch up whole periods missed while the scheduler was down
            while (self::periodEnd($start, $subscription->interval)->lte($now)) {
                $start = self::periodEnd($start, $subscription->interval);
            }

            $subscription->forceFill([
                'current_period_start' => $start,
                'current_period_end' => self::periodEnd($start, $subscription->interval),
                'status' => TenantSubscription::STATUS_ACTIVE,
            ])->save();

            $this->recordPeriodFee($subscription);
            $counts['renewed']++;

            if ($before['status'] !== TenantSubscription::STATUS_ACTIVE && ($tenant = Tenant::query()->find($subscription->tenant_id))) {
                $this->changed($tenant, $subscription, $before, 'renewal');
            }
        }

        return $counts;
    }

    /**
     * The plan ended: the free default plan when there is one (and it is not the plan that
     * ended), otherwise the subscription is canceled (no plan limits, usage charged as without a plan).
     */
    private function end(TenantSubscription $subscription, string $source): TenantSubscription
    {
        $tenant = Tenant::query()->find($subscription->tenant_id);
        $default = Plan::defaultPlan();

        if ($tenant && $default && $default->id !== $subscription->plan_id && $default->isFree($subscription->interval)) {
            return $this->assign($tenant, $default, $default->supportsInterval($subscription->interval) ? $subscription->interval : 'month', [
                'source' => $source, 'external_ref' => null,
            ]);
        }

        $before = $this->snapshot($subscription);
        $subscription->forceFill([
            'status' => TenantSubscription::STATUS_CANCELED,
            'prepaid' => false,
            'cancel_at_period_end' => false,
            'current_period_end' => $subscription->current_period_end && $subscription->current_period_end->isPast() ? $subscription->current_period_end : now(),
        ])->save();

        if ($tenant) {
            $this->changed($tenant, $subscription, $before, $source);
        }

        return $subscription->fresh('plan');
    }

    /** Re-apply the plan quota (add-on limits changed). */
    public function refreshQuota(Tenant $tenant): void
    {
        $subscription = TenantSubscription::query()->with('plan')->where('tenant_id', (string) $tenant->id)->first();

        if ($subscription?->plan && $subscription->status !== TenantSubscription::STATUS_CANCELED) {
            $this->quota->apply($tenant, $subscription->plan);
        }
    }

    // ── Events ──────────────────────────────────────────────────────────────

    /** @return array{plan_id: ?string, interval: string, status: string, prepaid: bool, period_end: ?string, cancel_at_period_end: bool} */
    private function snapshot(TenantSubscription $subscription): array
    {
        return [
            'plan_id' => $subscription->plan_id,
            'interval' => (string) $subscription->interval,
            'status' => (string) $subscription->status,
            'prepaid' => (bool) $subscription->prepaid,
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
        ];
    }

    private function changed(Tenant $tenant, TenantSubscription $subscription, array $before, string $source): void
    {
        $subscription = $subscription->fresh('plan') ?? $subscription;
        if ($this->snapshot($subscription) === $before) {
            return;
        }

        $previous = $before['plan_id'] ? Plan::query()->find($before['plan_id']) : null;

        $this->events->record(BillingEvents::PLAN_CHANGED, (string) $tenant->id, [
            'source' => $source,
            'previous' => [
                'plan' => $previous ? ['id' => $previous->id, 'slug' => $previous->slug, 'name' => $previous->name] : null,
                'interval' => $before['interval'],
                'status' => $before['status'],
                'prepaid' => $before['prepaid'],
            ],
            'subscription' => $subscription->toApiArray(),
        ]);
    }
}
