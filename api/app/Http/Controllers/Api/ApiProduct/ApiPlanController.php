<?php

namespace App\Http\Controllers\Api\ApiProduct;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\ApiProduct\ApiPlanResource;
use App\Models\ApiProduct\ApiPlan;
use App\Models\Gateway;
use App\Services\ApiProduct\ApiProductAccess;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Plans of the API product (docs/api-product.md#plans): rate limits (per second with burst, per
 * minute) and quotas (per day, per calendar month, UTC). Of a gateway, or of the organization (no
 * gateway_id; api_products.manage). A plan a consumer or an active key uses cannot be deleted.
 *
 * @tags API product
 */
class ApiPlanController extends Controller
{
    public function __construct(private readonly ApiProductAccess $access) {}

    #[OpenApiResponse(200, 'The plans', type: 'array{data: list<ApiPlanResource>}')]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['gateway_id' => 'nullable|string|size:26']);
        $user = $request->user();

        $query = ApiPlan::query()->orderBy('name');
        if (! empty($data['gateway_id'])) {
            $gateway = Gateway::query()->findOrFail($data['gateway_id']);
            Gate::authorize('view', $gateway);
            $query->where(fn ($q) => $q->where('gateway_id', $gateway->id)->orWhereNull('gateway_id'));
        } else {
            $visible = $this->access->visibleGatewayIds($user);
            $organization = $this->access->managesOrganization($user);
            $query->where(function ($q) use ($visible, $organization) {
                $q->whereIn('gateway_id', $visible);
                if ($organization) {
                    $q->orWhereNull('gateway_id');
                }
            });
        }

        return response()->json(['data' => ApiPlanResource::collection($query->get())->resolve()]);
    }

    #[BodyParameter('name', 'Unique per gateway (or in the organization)', required: true, type: 'string')]
    #[BodyParameter('gateway_id', 'The gateway; left out: a plan of the organization', type: 'string|null')]
    #[BodyParameter('description', type: 'string|null')]
    #[BodyParameter('rate_per_second', 'Token bucket rate, requests per second; null: none', type: 'int|null')]
    #[BodyParameter('burst', 'Requests at once of the token bucket (default: the rate; needs rate_per_second, at least it)', type: 'int|null')]
    #[BodyParameter('rate_per_minute', 'Sliding window, requests per minute; null: none', type: 'int|null')]
    #[BodyParameter('quota_per_day', 'Requests per calendar day (UTC); null: none', type: 'int|null')]
    #[BodyParameter('quota_per_month', 'Requests per calendar month (UTC); null: none', type: 'int|null')]
    #[OpenApiResponse(201, 'The new plan', type: 'array{data: ApiPlanResource}')]
    public function store(Request $request): JsonResponse
    {
        // in a variable: the API document's generator cannot evaluate rules() + [...] (#[BodyParameter])
        $rules = $this->rules() + [
            'gateway_id' => 'nullable|string|size:26',
            'name' => 'required|string|max:100',
        ];
        $data = $request->validate($rules);

        $gateway = ! empty($data['gateway_id']) ? Gateway::query()->findOrFail($data['gateway_id']) : null;
        Gate::authorize('create', [ApiPlan::class, $gateway]);
        $this->assertNameFree($data['name'], $gateway?->id);
        $this->assertBurst($data);

        $plan = ApiPlan::query()->create($data + [
            'gateway_id' => $gateway?->id,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['data' => (new ApiPlanResource($plan))->resolve($request)], 201);
    }

    #[BodyParameter('name', 'Unique per gateway (or in the organization)', type: 'string')]
    #[BodyParameter('description', type: 'string|null')]
    #[BodyParameter('rate_per_second', 'Token bucket rate, requests per second; null: none', type: 'int|null')]
    #[BodyParameter('burst', 'Requests at once of the token bucket (default: the rate; needs rate_per_second, at least it)', type: 'int|null')]
    #[BodyParameter('rate_per_minute', 'Sliding window, requests per minute; null: none', type: 'int|null')]
    #[BodyParameter('quota_per_day', 'Requests per calendar day (UTC); null: none', type: 'int|null')]
    #[BodyParameter('quota_per_month', 'Requests per calendar month (UTC); null: none', type: 'int|null')]
    #[OpenApiResponse(200, 'The plan', type: 'array{data: ApiPlanResource}')]
    public function update(Request $request, ApiPlan $apiPlan): JsonResponse
    {
        Gate::authorize('update', $apiPlan);
        // in a variable: the API document's generator cannot evaluate rules() + [...] (#[BodyParameter])
        $rules = $this->rules() + ['name' => 'sometimes|string|max:100'];
        $data = $request->validate($rules);

        if (isset($data['name'])) {
            $this->assertNameFree($data['name'], $apiPlan->gateway_id, $apiPlan->id);
        }
        // no per-second rate any more: no burst either
        if (array_key_exists('rate_per_second', $data) && $data['rate_per_second'] === null) {
            $data['burst'] = null;
        }
        $this->assertBurst($data + $apiPlan->only(['rate_per_second', 'burst']));

        $apiPlan->update($data);

        return response()->json(['data' => (new ApiPlanResource($apiPlan->fresh()))->resolve($request)]);
    }

    public function destroy(ApiPlan $apiPlan): JsonResponse
    {
        Gate::authorize('delete', $apiPlan);

        if ($apiPlan->inUse()) {
            return response()->json(['message' => __('api_product.plan.in_use')], 422);
        }
        $apiPlan->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'description' => 'nullable|string|max:500',
            'rate_per_second' => 'nullable|integer|min:1|max:100000',
            'burst' => 'nullable|integer|min:1|max:1000000',
            'rate_per_minute' => 'nullable|integer|min:1|max:10000000',
            'quota_per_day' => 'nullable|integer|min:1|max:1000000000000',
            'quota_per_month' => 'nullable|integer|min:1|max:10000000000000',
        ];
    }

    private function assertNameFree(string $name, ?string $gatewayId, ?string $exceptId = null): void
    {
        $taken = ApiPlan::query()
            ->where('name', $name)
            ->when($gatewayId === null, fn ($q) => $q->whereNull('gateway_id'), fn ($q) => $q->where('gateway_id', $gatewayId))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => __('api_product.plan.name_taken')]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function assertBurst(array $data): void
    {
        $rate = $data['rate_per_second'] ?? null;
        $burst = $data['burst'] ?? null;
        if ($burst === null) {
            return;
        }
        if ($rate === null) {
            throw ValidationException::withMessages(['burst' => __('api_product.plan.burst_without_rate')]);
        }
        if ((int) $burst < (int) $rate) {
            throw ValidationException::withMessages(['burst' => __('api_product.plan.burst_below_rate')]);
        }
    }
}
