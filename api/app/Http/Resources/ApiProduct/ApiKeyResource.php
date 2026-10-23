<?php

namespace App\Http\Resources\ApiProduct;

use App\Models\ApiProduct\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An API key without its secret: name, visible prefix, scopes, plan, expiry, status. The key
 * itself is only in the answer that created or rotated it ("key").
 *
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ApiKey $key */
        $key = $this->resource;

        return [
            'id' => $key->id,
            'consumer_id' => $key->consumer_id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            /** @var list<array{route_id: string, methods: list<string>|null}>|null null: every route the consumer may call */
            'scopes' => $key->scopes,
            'plan_id' => $key->plan_id,
            /** @var "active"|"expired"|"revoked" */
            'status' => $key->status(),
            /** @var string|null */
            'expires_at' => $key->expires_at?->toISOString(),
            /** @var string|null */
            'revoked_at' => $key->revoked_at?->toISOString(),
            /** @var string|null */
            'last_used_at' => $key->last_used_at?->toISOString(),
            'rotated_from_id' => $key->rotated_from_id,
            /** @var string|null */
            'created_at' => $key->created_at?->toISOString(),
        ];
    }
}
