<?php

namespace Modules\Tenancy\Http\Controllers\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Billing\BillingSettings;
use Modules\Tenancy\Services\Plans\PlanQuotaService;

/**
 * Plans (docs/billing.md) — platform administrators (config/route_permissions.php):
 *
 *  GET    /api/admin/plans           all plans with their tenant count (archived included)
 *  GET    /api/billing/plans         the same list for the billing API (billing.view tokens)
 *  POST   /api/admin/plans
 *  PATCH  /api/admin/plans/{plan}    a changed CPU / memory limit is written to its tenants' quotas
 *  DELETE /api/admin/plans/{plan}    deleted when unused, archived (hidden, kept) otherwise
 *
 * GET /api/plans/public — public plans (plans on, otherwise []), e.g. for the operator's pricing page.
 */
class PlanController extends Controller
{
    public function __construct(private readonly PlanQuotaService $quota) {}

    public function index(): JsonResponse
    {
        $plans = Plan::query()->withCount('subscriptions')->orderBy('sort_order')->orderBy('price_monthly')->get();

        return response()->json($plans->map->toAdminArray()->values());
    }

    /** @unauthenticated */
    public function publicIndex(BillingSettings $settings): JsonResponse
    {
        if (! $settings->plansEnabled()) {
            return response()->json([]);
        }

        return response()->json(Plan::query()->public()->orderBy('sort_order')->orderBy('price_monthly')->get()->map->toPublicArray()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $plan = DB::connection((new Plan)->getConnectionName())->transaction(fn () => $this->save(new Plan, $data));

        return response()->json($plan->loadCount('subscriptions')->toAdminArray(), 201);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $model = Plan::query()->findOrFail($plan);
        $data = $this->validated($request, $model);

        $before = [$model->cpu_quota_m, $model->memory_quota_mb];
        $model = DB::connection($model->getConnectionName())->transaction(fn () => $this->save($model, $data));

        // New CPU / memory limits reach the quotas of the plan's tenants
        if ($before !== [$model->cpu_quota_m, $model->memory_quota_mb]) {
            TenantSubscription::query()->where('plan_id', $model->id)->pluck('tenant_id')->each(function ($tenantId) use ($model) {
                if ($tenant = Tenant::query()->find($tenantId)) {
                    $this->quota->apply($tenant, $model);
                }
            });
        }

        return response()->json($model->loadCount('subscriptions')->toAdminArray());
    }

    public function destroy(string $plan): JsonResponse
    {
        $model = Plan::query()->findOrFail($plan);

        if ($model->subscriptions()->exists()) {
            $model->forceFill(['is_archived' => true, 'is_public' => false, 'is_default' => false])->save();

            return response()->json(['archived' => true, 'deleted' => false]);
        }

        $model->delete();

        return response()->json(['archived' => false, 'deleted' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function validated(Request $request, ?Plan $plan = null): array
    {
        $required = $plan ? 'sometimes' : 'required';
        $connection = (new Plan)->getConnectionName();

        $data = $request->validate([
            'slug' => [$required, 'string', 'max:64', 'alpha_dash', Rule::unique("{$connection}.plans", 'slug')->ignore($plan?->id)],
            'name' => [$required, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'currency' => [$required, 'string', 'size:3', 'alpha'],
            'price_monthly' => [$required, 'numeric', 'min:0', 'max:1000000000'],
            'price_yearly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'nullable', 'array'],
            'features.*' => ['boolean'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_public' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:-100000', 'max:100000'],
        ]);

        $unknownLimits = array_diff(array_keys((array) ($data['limits'] ?? [])), Plan::LIMITS);
        if ($unknownLimits) {
            throw ValidationException::withMessages(['limits' => __('whitelabel.plans.unknown_limit', ['keys' => implode(', ', $unknownLimits)])]);
        }

        $unknownFeatures = array_diff(array_keys((array) ($data['features'] ?? [])), Plan::FEATURES);
        if ($unknownFeatures) {
            throw ValidationException::withMessages(['features' => __('whitelabel.plans.unknown_feature', ['keys' => implode(', ', $unknownFeatures)])]);
        }

        return $data;
    }

    private function save(Plan $plan, array $data): Plan
    {
        foreach (['slug', 'name', 'description', 'price_monthly', 'price_yearly', 'trial_days', 'is_public', 'is_default', 'is_archived', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $plan->{$field} = $data[$field];
            }
        }

        if (array_key_exists('currency', $data)) {
            $plan->currency = strtoupper($data['currency']);
        }

        foreach ((array) ($data['limits'] ?? []) as $limit => $value) {
            $plan->{$limit} = $value === null ? null : (int) $value;
        }

        if (array_key_exists('features', $data)) {
            $plan->features = array_map('boolval', (array) ($data['features'] ?? []));
        }

        if ($plan->is_archived) {
            $plan->is_public = false;
            $plan->is_default = false;
        }

        $plan->save();

        // One default plan at most
        if ($plan->is_default) {
            Plan::query()->whereKeyNot($plan->getKey())->where('is_default', true)->update(['is_default' => false]);
        }

        return $plan->fresh();
    }
}
