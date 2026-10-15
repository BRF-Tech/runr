<?php

namespace Modules\Tenancy\Http\Controllers\Billing;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Http\Controllers\Billing\Concerns\ResolvesBillingTenant;
use Modules\Tenancy\Models\Charge;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Services\Billing\ChargeService;
use Modules\Tenancy\Services\Billing\CreditLedger;
use Modules\Tenancy\Services\Plans\PlanLimitService;
use Modules\Tenancy\Services\Plans\PlanService;

/**
 * Billing API — tenants and their plans (docs/billing.md). Platform administrators and API tokens
 * with billing.view (reads) / billing.manage (writes); {tenant} is the tenant id or slug.
 *
 *  GET /api/billing/tenants                          every tenant with its plan
 *  GET /api/billing/tenants/{tenant}                 plan, effective limits, credit balances, open charges (estimated)
 *  GET /api/billing/tenants/{tenant}/subscription
 *  PUT /api/billing/tenants/{tenant}/subscription    {plan: id|slug|null, interval?, external_ref?} | {cancel_at_period_end: true|false}
 */
class BillingTenantController extends Controller
{
    use ResolvesBillingTenant;

    public function __construct(
        private readonly PlanService $plans,
        private readonly BillingSettings $settings,
        private readonly CreditLedger $credits,
        private readonly ChargeService $charges,
        private readonly PlanLimitService $limits,
    ) {}

    public function index(): JsonResponse
    {
        $subscriptions = TenantSubscription::query()->with('plan')->get()->keyBy('tenant_id');

        $rows = [];
        foreach (Tenant::query()->orderBy('name')->get() as $tenant) {
            /** @var Tenant $tenant */
            $rows[] = [
                'tenant' => $this->tenantArray($tenant),
                'subscription' => $subscriptions->get((string) $tenant->id)?->toApiArray(),
            ];
        }

        return response()->json(['plans_enabled' => $this->settings->plansEnabled(), 'tenants' => $rows]);
    }

    public function show(string $tenant): JsonResponse
    {
        $model = $this->billingTenant($tenant);
        $subscription = TenantSubscription::findForTenant((string) $model->id);
        $plan = $this->limits->planFor($model);

        $open = Charge::query()
            ->where('tenant_id', (string) $model->id)
            ->where('status', Charge::STATUS_OPEN)
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();

        return response()->json([
            'tenant' => $this->tenantArray($model),
            'plans_enabled' => $this->settings->plansEnabled(),
            'subscription' => $subscription?->toApiArray(),
            'limits' => $plan ? $this->limits->effectiveLimits($model, $plan) : null,
            'addon_limits' => (object) $this->limits->addonLimits($model),
            'metered_usage' => $this->charges->metersUsage((string) $model->id),
            'currency' => $this->charges->currencyOf((string) $model->id),
            'credit_balances' => (object) $this->credits->balances((string) $model->id),
            'open_charges' => $open->map(fn (Charge $c) => $this->charges->present($c))->values(),
        ]);
    }

    public function subscription(string $tenant): JsonResponse
    {
        $model = $this->billingTenant($tenant);

        return response()->json([
            'plans_enabled' => $this->settings->plansEnabled(),
            'subscription' => TenantSubscription::findForTenant((string) $model->id)?->toApiArray(),
        ]);
    }

    public function updateSubscription(Request $request, string $tenant): JsonResponse
    {
        $model = $this->billingTenant($tenant);
        if (! $this->settings->plansEnabled()) {
            return response()->json(['message' => __('whitelabel.plans.disabled'), 'code' => 'plans_disabled'], 409);
        }

        $data = $request->validate([
            'plan' => ['sometimes', 'nullable', 'string', 'max:64'],
            'interval' => ['sometimes', 'string', 'in:'.implode(',', Plan::INTERVALS)],
            'external_ref' => ['sometimes', 'nullable', 'string', 'max:191'],
            'cancel_at_period_end' => ['sometimes', 'boolean'],
        ]);
        if (! array_key_exists('plan', $data) && ! array_key_exists('cancel_at_period_end', $data)) {
            throw ValidationException::withMessages(['plan' => __('validation.required', ['attribute' => 'plan'])]);
        }

        if (! array_key_exists('plan', $data)) {
            $subscription = $data['cancel_at_period_end']
                ? $this->plans->cancel($model, true, 'api')
                : $this->plans->keep($model, 'api');

            return response()->json(['subscription' => $subscription->toApiArray()]);
        }

        $plan = null;
        if ($data['plan'] !== null) {
            $plan = Plan::query()->available()->whereKey($data['plan'])->first()
                ?? Plan::query()->available()->where('slug', $data['plan'])->first();
            if (! $plan) {
                throw ValidationException::withMessages(['plan' => __('whitelabel.plans.not_available')]);
            }
        }

        $interval = $data['interval'] ?? 'month';
        if ($plan && ! $plan->supportsInterval($interval)) {
            throw ValidationException::withMessages(['interval' => __('whitelabel.plans.interval_not_available')]);
        }

        $options = ['source' => 'api'];
        if (array_key_exists('external_ref', $data)) {
            $options['external_ref'] = $data['external_ref'];
        }

        $subscription = $this->plans->assign($model, $plan, $interval, $options);

        return response()->json(['subscription' => $subscription->toApiArray()]);
    }

    private function tenantArray(Tenant $tenant): array
    {
        return [
            'id' => (string) $tenant->id,
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'status' => $tenant->status ?? Tenant::STATUS_ACTIVE,
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
