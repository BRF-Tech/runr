<?php

namespace App\Services\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiPlan;
use App\Models\Gateway;
use App\Policies\ApiProductPolicy;
use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * What a user sees of the API product (ApiProductPolicy) and the checks that tie consumers, keys and
 * plans to one gateway: a plan or JWT subject of another gateway never applies.
 */
class ApiProductAccess
{
    public function __construct(private readonly ApiProductPolicy $policy) {}

    /** @return list<string> ids of the gateways $user may see */
    public function visibleGatewayIds(Authenticatable $user): array
    {
        return Gateway::query()->get()
            ->filter(fn (Gateway $gateway) => Gate::forUser($user)->allows('view', $gateway))
            ->map(fn (Gateway $gateway) => (string) $gateway->id)
            ->values()->all();
    }

    public function managesOrganization(Authenticatable $user): bool
    {
        return $this->policy->manageOrganization($user);
    }

    /**
     * The usage of every gateway: api_products.manage, or billing.view — the invoicing system's
     * token reads what to bill (docs/api-product.md#usage).
     */
    public function readsAllUsage(Authenticatable $user): bool
    {
        return $this->managesOrganization($user) || app(Rbac::class)->allows($user, 'billing.view');
    }

    /**
     * A plan a consumer / key of $gatewayId (null: of the organization) may use: the gateway's own
     * plans and the organization's; an organization consumer only the organization's.
     */
    public function assertPlanUsable(?string $planId, ?string $gatewayId, string $field = 'plan_id'): void
    {
        if ($planId === null) {
            return;
        }

        $plan = ApiPlan::query()->find($planId);
        if (! $plan || ($plan->gateway_id !== null && $plan->gateway_id !== $gatewayId)) {
            throw ValidationException::withMessages([$field => __('api_product.plan.not_usable')]);
        }
    }

    /** A JWT subject names one consumer among those a gateway accepts. */
    public function assertSubjectFree(?string $subject, ?string $gatewayId, ?string $exceptId = null): void
    {
        if ($subject === null || $subject === '') {
            return;
        }

        $taken = ApiConsumer::query()
            ->where('jwt_subject', $subject)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($gatewayId !== null, fn ($q) => $q->where(fn ($w) => $w->where('gateway_id', $gatewayId)->orWhereNull('gateway_id')))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['jwt_subject' => __('api_product.consumer.subject_taken')]);
        }
    }
}
