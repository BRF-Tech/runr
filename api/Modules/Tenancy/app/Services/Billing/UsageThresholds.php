<?php

namespace Modules\Tenancy\Services\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\Plans\PlanLimitService;

/**
 * billing.usage.threshold (docs/billing.md), checked hourly (UsageThresholdJob) — once per tenant
 * and month for each threshold crossed:
 *
 *  - amount:  the month-to-date rated usage in a currency reached one of the configured amounts
 *             (Settings → Plans & Billing, Helm billing.usageThresholds);
 *  - limit:   plans on, the month's sessions reached 80 % / 100 % of the plan's
 *             invocations_per_month (add-ons included).
 */
class UsageThresholds
{
    public function __construct(
        private readonly UsageRating $rating,
        private readonly PlanLimitService $limits,
        private readonly BillingEvents $events,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * @return int events recorded
     */
    public function check(?Carbon $now = null): int
    {
        $now ??= now();
        $amounts = $this->settings->usageThresholds();
        $plansOn = $this->settings->plansEnabled();
        if ($amounts === [] && ! $plansOn) {
            return 0;
        }

        $recorded = 0;
        foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get() as $tenant) {
            /** @var Tenant $tenant */
            try {
                $recorded += $this->checkTenant($tenant, $now, $amounts);
            } catch (\Throwable $e) {
                Log::warning('Usage thresholds not checked', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);
            }
        }

        return $recorded;
    }

    /** @param  list<float>  $amounts */
    public function checkTenant(Tenant $tenant, Carbon $now, array $amounts): int
    {
        $tenantId = (string) $tenant->id;
        $period = $now->format('Y-m');
        $from = $now->copy()->startOfMonth()->toDateString();
        $to = $now->toDateString();
        $recorded = 0;

        if ($amounts !== []) {
            foreach ($this->rating->totals($tenantId, $from, $to) as $currency => $total) {
                foreach ($amounts as $threshold) {
                    if ($total < $threshold) {
                        break;
                    }
                    $key = sprintf('usage.threshold:%s:%s:amount:%s:%s', $tenantId, $period, $currency, number_format($threshold, 2, '.', ''));
                    if ($this->events->record(BillingEvents::USAGE_THRESHOLD, $tenantId, [
                        'kind' => 'amount',
                        'period' => $period,
                        'currency' => $currency,
                        'threshold' => $threshold,
                        'amount' => $total,
                    ], $key)) {
                        $recorded++;
                    }
                }
            }
        }

        $plan = $this->limits->planFor($tenant);
        $max = $plan ? $this->limits->effectiveLimit($tenant, $plan, 'invocations_per_month') : null;
        if ($plan && $max !== null && $max > 0) {
            $used = $this->rating->sessions($tenantId, $from, $to);
            foreach ((array) config('tenancy.billing.events.limit_percentages', [80, 100]) as $percent) {
                if ($used * 100 < $max * (int) $percent) {
                    continue;
                }
                $key = sprintf('usage.threshold:%s:%s:limit:invocations_per_month:%d', $tenantId, $period, (int) $percent);
                if ($this->events->record(BillingEvents::USAGE_THRESHOLD, $tenantId, [
                    'kind' => 'limit',
                    'period' => $period,
                    'limit' => 'invocations_per_month',
                    'percent' => (int) $percent,
                    'used' => $used,
                    'max' => $max,
                    'plan' => ['id' => $plan->id, 'slug' => $plan->slug],
                ], $key)) {
                    $recorded++;
                }
            }
        }

        return $recorded;
    }
}
