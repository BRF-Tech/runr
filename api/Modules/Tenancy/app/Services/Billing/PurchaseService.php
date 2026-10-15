<?php

namespace Modules\Tenancy\Services\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\Plans\PlanService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Purchases made in an external system, recorded through the billing API (docs/billing.md), so
 * that RunR's calculation reflects them:
 *
 *   credit  → the tenant's credit balance grows; closed charges draw on it
 *   plan    → the plan term is prepaid (PlanService::applyPurchase): in effect at once, no plan fee
 *   addon   → its limits add to the tenant's plan limits until ends_at (default: one month)
 *
 * external_ref is the idempotency key: recording the same reference again returns the stored
 * purchase (a reference of another tenant or kind is a conflict).
 */
class PurchaseService
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly BillingSettings $settings,
    ) {}

    /**
     * @param  array{kind: string, external_ref: string, description?: ?string, amount?: float|int|string|null, currency?: ?string, plan?: ?string, interval?: ?string, limits?: ?array, ends_at?: ?string, purchased_at?: ?string, metadata?: ?array}  $data
     * @return array{0: Purchase, 1: bool} the purchase, whether it was recorded now
     */
    public function record(Tenant $tenant, array $data): array
    {
        $existing = Purchase::query()->where('external_ref', $data['external_ref'])->first();
        if ($existing) {
            if ((string) $existing->tenant_id !== (string) $tenant->id || $existing->kind !== $data['kind']) {
                throw new ConflictHttpException(__('api.billing.purchase_ref_taken'));
            }

            return [$existing, false];
        }

        $kind = $data['kind'];
        if ($kind !== Purchase::KIND_CREDIT && ! $this->settings->plansEnabled()) {
            throw ValidationException::withMessages(['kind' => __('whitelabel.plans.disabled')]);
        }

        $purchasedAt = isset($data['purchased_at']) ? Carbon::parse($data['purchased_at']) : now();
        $attributes = [
            'tenant_id' => (string) $tenant->id,
            'kind' => $kind,
            'external_ref' => $data['external_ref'],
            'description' => isset($data['description']) ? mb_substr(trim((string) $data['description']), 0, 255) : null,
            'amount' => isset($data['amount']) ? round((float) $data['amount'], 2) : null,
            'currency' => isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            'purchased_at' => $purchasedAt,
            'metadata' => $data['metadata'] ?? null,
        ];

        return DB::connection((new Purchase)->getConnectionName())->transaction(function () use ($tenant, $kind, $data, $attributes) {
            return match ($kind) {
                Purchase::KIND_CREDIT => [$this->credit($attributes), true],
                Purchase::KIND_PLAN => [$this->plan($tenant, $data, $attributes), true],
                default => [$this->addon($tenant, $data, $attributes), true],
            };
        });
    }

    public function void(Purchase $purchase): Purchase
    {
        if ($purchase->voided_at) {
            return $purchase;
        }

        $purchase->forceFill(['voided_at' => now()])->save();

        if ($purchase->kind === Purchase::KIND_ADDON && ($tenant = Tenant::query()->find($purchase->tenant_id))) {
            $this->plans->refreshQuota($tenant);
        }

        return $purchase;
    }

    private function credit(array $attributes): Purchase
    {
        if (($attributes['amount'] ?? 0) <= 0 || empty($attributes['currency'])) {
            throw ValidationException::withMessages(['amount' => __('api.billing.credit_amount_required')]);
        }

        return Purchase::query()->create($attributes);
    }

    private function plan(Tenant $tenant, array $data, array $attributes): Purchase
    {
        $key = (string) ($data['plan'] ?? '');
        $plan = $key === '' ? null : (Plan::query()->available()->whereKey($key)->first() ?? Plan::query()->available()->where('slug', $key)->first());
        if (! $plan) {
            throw ValidationException::withMessages(['plan' => __('whitelabel.plans.not_available')]);
        }

        $interval = $data['interval'] ?? 'month';
        if (! in_array($interval, Plan::INTERVALS, true)) {
            throw ValidationException::withMessages(['interval' => __('whitelabel.plans.interval_not_available')]);
        }

        $purchase = Purchase::query()->create($attributes + [
            'plan_id' => $plan->id,
            'interval' => $interval,
            'starts_at' => now(),
        ]);

        // The term this purchase pays for: the plan's new period, or the interval it extended
        $subscription = $this->plans->applyPurchase($tenant, $plan, $interval, $purchase);
        $endsAt = $subscription->current_period_end;
        $purchase->forceFill([
            'starts_at' => $endsAt ? ($interval === 'year' ? $endsAt->copy()->subYearNoOverflow() : $endsAt->copy()->subMonthNoOverflow()) : $purchase->starts_at,
            'ends_at' => $endsAt,
        ])->save();

        return $purchase;
    }

    private function addon(Tenant $tenant, array $data, array $attributes): Purchase
    {
        $limits = [];
        foreach ((array) ($data['limits'] ?? []) as $limit => $value) {
            if (! in_array($limit, Purchase::ADDON_LIMITS, true)) {
                throw ValidationException::withMessages(['limits' => __('whitelabel.plans.unknown_limit', ['keys' => (string) $limit])]);
            }
            if ((int) $value > 0) {
                $limits[$limit] = (int) $value;
            }
        }
        if ($limits === []) {
            throw ValidationException::withMessages(['limits' => __('api.billing.addon_limits_required')]);
        }

        $endsAt = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : now()->addMonthNoOverflow();
        if ($endsAt->isPast()) {
            throw ValidationException::withMessages(['ends_at' => __('api.billing.ends_at_past')]);
        }

        $purchase = Purchase::query()->create($attributes + [
            'limits' => $limits,
            'starts_at' => now(),
            'ends_at' => $endsAt,
        ]);

        if (isset($limits['cpu_quota_m']) || isset($limits['memory_quota_mb'])) {
            $this->plans->refreshQuota($tenant);
        }

        return $purchase;
    }
}
