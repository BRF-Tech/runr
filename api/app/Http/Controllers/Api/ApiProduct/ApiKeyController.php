<?php

namespace App\Http\Controllers\Api\ApiProduct;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\ApiProduct\ApiKeyResource;
use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Services\ApiProduct\ApiKeys;
use App\Services\ApiProduct\ApiProductAccess;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API keys of a consumer (docs/api-product.md#api-keys). Creating and rotating answer with the
 * key itself ("key") — the only time RunR has it; it is never stored, logged or shown again.
 *
 * @tags API product
 */
class ApiKeyController extends Controller
{
    private const METHODS = 'GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS';

    /** The API document's shape of a new key: ApiKeyResource with the key itself (only in this answer) */
    private const NEW_KEY_TYPE = 'array{data: array{id: string, consumer_id: string, name: string, prefix: string, scopes: list<array{route_id: string, methods: list<string>|null}>|null, plan_id: string|null, status: "active"|"expired"|"revoked", expires_at: string|null, revoked_at: string|null, last_used_at: string|null, rotated_from_id: string|null, created_at: string|null, key: string}}';

    /** The API document's shape of the scopes of the body */
    private const SCOPES_TYPE = 'list<array{route_id: string, methods?: list<"GET"|"POST"|"PUT"|"PATCH"|"DELETE"|"HEAD"|"OPTIONS">|null}>|null';

    public function __construct(
        private readonly ApiKeys $keys,
        private readonly ApiProductAccess $access,
    ) {}

    #[OpenApiResponse(200, 'The consumer\'s keys, newest first, without their secrets', type: 'array{data: list<ApiKeyResource>}')]
    public function index(ApiConsumer $apiConsumer): JsonResponse
    {
        Gate::authorize('view', $apiConsumer);

        $keys = $apiConsumer->keys()->orderByDesc('created_at')->get();

        return response()->json(['data' => ApiKeyResource::collection($keys)->resolve()]);
    }

    /**
     * scopes: [{route_id, methods}] (null: every route the consumer may call); plan_id overrides the
     * consumer's plan for this key; expires_at in the future.
     */
    #[BodyParameter('name', required: true, type: 'string')]
    #[BodyParameter('scopes', 'The routes (and methods, default all) the key may call; null: every route the consumer may call', type: self::SCOPES_TYPE)]
    #[BodyParameter('plan_id', 'A plan for this key instead of the consumer\'s', type: 'string|null')]
    #[BodyParameter('expires_at', 'In the future', type: 'string|null', format: 'date-time')]
    #[OpenApiResponse(201, 'The new key with the key itself (key), shown only in this answer', type: self::NEW_KEY_TYPE)]
    public function store(Request $request, ApiConsumer $apiConsumer): JsonResponse
    {
        Gate::authorize('update', $apiConsumer);
        $this->upperCaseMethods($request);
        // in a variable: the API document's generator cannot evaluate rules() + [...] (#[BodyParameter])
        $rules = $this->rules() + ['name' => 'required|string|max:120'];
        $data = $request->validate($rules);
        $this->access->assertPlanUsable($data['plan_id'] ?? null, $apiConsumer->gateway_id);

        [$key, $plain] = $this->keys->issue($apiConsumer, $data, $request->user()?->getAuthIdentifier());

        $response = response()->json(['data' => (new ApiKeyResource($key))->resolve($request) + ['key' => $plain]], 201);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[BodyParameter('name', type: 'string')]
    #[BodyParameter('scopes', 'The routes (and methods, default all) the key may call; null: every route the consumer may call', type: self::SCOPES_TYPE)]
    #[BodyParameter('plan_id', 'A plan for this key instead of the consumer\'s', type: 'string|null')]
    #[BodyParameter('expires_at', 'In the future', type: 'string|null', format: 'date-time')]
    #[OpenApiResponse(200, 'The key', type: 'array{data: ApiKeyResource}')]
    public function update(Request $request, ApiKey $apiKey): JsonResponse
    {
        $consumer = $apiKey->consumer;
        Gate::authorize('update', $consumer);
        $this->upperCaseMethods($request);
        // in a variable: the API document's generator cannot evaluate rules() + [...] (#[BodyParameter])
        $rules = $this->rules() + ['name' => 'sometimes|string|max:120'];
        $data = $request->validate($rules);

        if (array_key_exists('plan_id', $data)) {
            $this->access->assertPlanUsable($data['plan_id'], $consumer->gateway_id);
        }
        if (array_key_exists('scopes', $data)) {
            $data['scopes'] = $this->keys->normalizeScopes($consumer, $data['scopes']);
        }

        $apiKey->update($data);

        return response()->json(['data' => (new ApiKeyResource($apiKey->fresh()))->resolve($request)]);
    }

    /**
     * A new key with the same scopes, plan and expiry; the old one keeps working for
     * overlap_minutes (default 1440, 0 = revoked now) so callers switch without an outage.
     */
    #[OpenApiResponse(201, 'The new key with the key itself (key), shown only in this answer', type: self::NEW_KEY_TYPE)]
    public function rotate(Request $request, ApiKey $apiKey): JsonResponse
    {
        Gate::authorize('update', $apiKey->consumer);
        $data = $request->validate(['overlap_minutes' => 'nullable|integer|min:0|max:43200']);

        if (! $apiKey->isUsable()) {
            return response()->json(['message' => __('api_product.key.not_usable')], 422);
        }

        $overlap = (int) ($data['overlap_minutes'] ?? config('api_product.rotation_overlap_minutes', 1440));
        [$key, $plain] = $this->keys->rotate($apiKey, $overlap, $request->user()?->getAuthIdentifier());

        $response = response()->json(['data' => (new ApiKeyResource($key))->resolve($request) + ['key' => $plain]], 201);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** The key stops working within the region's sync interval (seconds). */
    #[OpenApiResponse(200, 'The revoked key', type: 'array{data: ApiKeyResource}')]
    public function revoke(Request $request, ApiKey $apiKey): JsonResponse
    {
        Gate::authorize('update', $apiKey->consumer);

        return response()->json(['data' => (new ApiKeyResource($this->keys->revoke($apiKey)))->resolve($request)]);
    }

    /** A scope's methods in any case ("get"): validated and stored upper-case. */
    private function upperCaseMethods(Request $request): void
    {
        $scopes = $request->input('scopes');
        if (! is_array($scopes)) {
            return;
        }
        foreach ($scopes as $i => $scope) {
            if (is_array($scope) && is_array($scope['methods'] ?? null)) {
                $scopes[$i]['methods'] = array_map(fn ($m) => is_string($m) ? strtoupper($m) : $m, $scope['methods']);
            }
        }
        $request->merge(['scopes' => $scopes]);
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'scopes' => 'nullable|array|min:1|max:200',
            'scopes.*.route_id' => 'required|string|size:26',
            'scopes.*.methods' => 'nullable|array|min:1',
            'scopes.*.methods.*' => 'string|in:'.self::METHODS,
            'plan_id' => 'nullable|string|size:26',
            'expires_at' => 'nullable|date|after:now',
        ];
    }
}
