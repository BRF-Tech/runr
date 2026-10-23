<?php

namespace App\Http\Resources\ApiProduct;

use App\Models\ApiProduct\ApiPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiPlan
 */
class ApiPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ApiPlan $plan */
        $plan = $this->resource;

        return [
            'id' => $plan->id,
            'gateway_id' => $plan->gateway_id,
            'scope' => $plan->gateway_id ? 'gateway' : 'organization',
            'name' => $plan->name,
            'description' => $plan->description,
            'rate_per_second' => $plan->rate_per_second,
            'burst' => $plan->rate_per_second ? ($plan->burst ?: $plan->rate_per_second) : null,
            'rate_per_minute' => $plan->rate_per_minute,
            'quota_per_day' => $plan->quota_per_day,
            'quota_per_month' => $plan->quota_per_month,
            /** @var string|null */
            'created_at' => $plan->created_at?->toISOString(),
            /** @var string|null */
            'updated_at' => $plan->updated_at?->toISOString(),
        ];
    }
}
