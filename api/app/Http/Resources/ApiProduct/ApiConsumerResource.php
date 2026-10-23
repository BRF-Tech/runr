<?php

namespace App\Http\Resources\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * A consumer with its plan and key counts. Its contact (email, metadata) only for those who may
 * manage it — the owner of another gateway sees an organization consumer's name, not its contact.
 *
 * @mixin ApiConsumer
 */
class ApiConsumerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ApiConsumer $consumer */
        $consumer = $this->resource;
        $manage = $request->user() !== null && Gate::forUser($request->user())->allows('update', $consumer);

        return [
            'id' => $consumer->id,
            'gateway_id' => $consumer->gateway_id,
            'scope' => $consumer->gateway_id ? 'gateway' : 'organization',
            'name' => $consumer->name,
            'email' => $manage ? $consumer->email : null,
            'external_id' => $consumer->external_id,
            'jwt_subject' => $consumer->jwt_subject,
            'plan_id' => $consumer->plan_id,
            'plan' => $consumer->relationLoaded('plan') && $consumer->plan
                ? ['id' => $consumer->plan->id, 'name' => $consumer->plan->name] : null,
            /** @var "active"|"suspended" */
            'status' => $consumer->status,
            /** @var array<string, mixed>|null only for those who may manage the consumer */
            'metadata' => $manage ? ($consumer->metadata ?? (object) []) : null,
            'keys' => $consumer->relationLoaded('keys') ? [
                'total' => $consumer->keys->count(),
                'active' => $consumer->keys->filter(fn (ApiKey $k) => $k->isUsable())->count(),
            ] : null,
            /** @var bool */
            'can_manage' => $manage,
            /** @var string|null */
            'created_at' => $consumer->created_at?->toISOString(),
            /** @var string|null */
            'updated_at' => $consumer->updated_at?->toISOString(),
        ];
    }
}
