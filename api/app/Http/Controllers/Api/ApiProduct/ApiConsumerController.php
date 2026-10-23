<?php

namespace App\Http\Controllers\Api\ApiProduct;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\ApiProduct\ApiConsumerResource;
use App\Models\ApiProduct\ApiConsumer;
use App\Models\Gateway;
use App\Services\ApiProduct\ApiProductAccess;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Consumers of the API product (docs/api-product.md#consumers): of a gateway (gateway_id) or of the
 * organization (no gateway_id, accepted on every gateway; api_products.manage).
 *
 * @tags API product
 */
class ApiConsumerController extends Controller
{
    public function __construct(private readonly ApiProductAccess $access) {}

    /**
     * With gateway_id: the gateway's consumers and the organization's. Without: the consumers of
     * every gateway the caller sees, and the organization's for api_products.manage.
     */
    #[OpenApiResponse(200, 'The consumers (at most 1000)', type: 'array{data: list<ApiConsumerResource>}')]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gateway_id' => 'nullable|string|size:26',
            'status' => 'nullable|string|in:active,suspended',
            'q' => 'nullable|string|max:120',
        ]);
        $user = $request->user();

        $query = ApiConsumer::query()->with(['plan', 'keys', 'gateway'])->orderBy('name');
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

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $data['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('external_id', 'like', $term));
        }

        return response()->json(['data' => ApiConsumerResource::collection($query->limit(1000)->get())->resolve()]);
    }

    #[BodyParameter('name', required: true, type: 'string')]
    #[BodyParameter('gateway_id', 'The gateway; left out: a consumer of the organization, accepted on every gateway (api_products.manage)', type: 'string|null')]
    #[BodyParameter('email', 'Contact', type: 'string|null')]
    #[BodyParameter('external_id', 'Its id in the caller\'s own systems (billing, CRM)', type: 'string|null')]
    #[BodyParameter('jwt_subject', 'The JWT subject that identifies it on JWT routes (unique per gateway)', type: 'string|null')]
    #[BodyParameter('plan_id', 'Its plan (rate limits and quotas)', type: 'string|null')]
    #[BodyParameter('status', 'active (default) or suspended', type: '"active"|"suspended"|null')]
    #[BodyParameter('metadata', 'Up to 50 keys of the caller\'s own', type: 'array<string, mixed>|null')]
    #[OpenApiResponse(201, 'The new consumer', type: 'array{data: ApiConsumerResource}')]
    public function store(Request $request): JsonResponse
    {
        // in a variable: the API document's generator cannot evaluate rules() + [...] (#[BodyParameter])
        $rules = $this->rules() + [
            'gateway_id' => 'nullable|string|size:26',
            'name' => 'required|string|max:120',
        ];
        $data = $request->validate($rules);

        $gateway = ! empty($data['gateway_id']) ? Gateway::query()->findOrFail($data['gateway_id']) : null;
        Gate::authorize('create', [ApiConsumer::class, $gateway]);

        $this->access->assertPlanUsable($data['plan_id'] ?? null, $gateway?->id);
        $this->access->assertSubjectFree($data['jwt_subject'] ?? null, $gateway?->id);

        $consumer = ApiConsumer::query()->create([
            'gateway_id' => $gateway?->id,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'jwt_subject' => $data['jwt_subject'] ?? null,
            'plan_id' => $data['plan_id'] ?? null,
            'status' => $data['status'] ?? ApiConsumer::STATUS_ACTIVE,
            'metadata' => $data['metadata'] ?? null,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['data' => (new ApiConsumerResource($consumer->load(['plan', 'keys'])))->resolve($request)], 201);
    }

    #[OpenApiResponse(200, 'The consumer', type: 'array{data: ApiConsumerResource}')]
    public function show(Request $request, ApiConsumer $apiConsumer): JsonResponse
    {
        Gate::authorize('view', $apiConsumer);

        return response()->json(['data' => (new ApiConsumerResource($apiConsumer->load(['plan', 'keys'])))->resolve($request)]);
    }

    /** Name, contact, external id, JWT subject, plan, status (suspended: every key answers 403). */
    #[BodyParameter('name', type: 'string')]
    #[BodyParameter('email', 'Contact', type: 'string|null')]
    #[BodyParameter('external_id', 'Its id in the caller\'s own systems (billing, CRM)', type: 'string|null')]
    #[BodyParameter('jwt_subject', 'The JWT subject that identifies it on JWT routes (unique per gateway)', type: 'string|null')]
    #[BodyParameter('plan_id', 'Its plan (rate limits and quotas); null: none', type: 'string|null')]
    #[BodyParameter('status', 'active or suspended (every key answers 403)', type: '"active"|"suspended"|null')]
    #[BodyParameter('metadata', 'Up to 50 keys of the caller\'s own', type: 'array<string, mixed>|null')]
    #[OpenApiResponse(200, 'The consumer', type: 'array{data: ApiConsumerResource}')]
    public function update(Request $request, ApiConsumer $apiConsumer): JsonResponse
    {
        Gate::authorize('update', $apiConsumer);
        // in a variable: the API document's generator cannot evaluate rules() + [...] (#[BodyParameter])
        $rules = $this->rules() + ['name' => 'sometimes|string|max:120'];
        $data = $request->validate($rules);

        if (array_key_exists('plan_id', $data)) {
            $this->access->assertPlanUsable($data['plan_id'], $apiConsumer->gateway_id);
        }
        if (array_key_exists('jwt_subject', $data)) {
            $this->access->assertSubjectFree($data['jwt_subject'], $apiConsumer->gateway_id, $apiConsumer->id);
        }
        if (array_key_exists('status', $data) && $data['status'] === null) {
            unset($data['status']);
        }

        $apiConsumer->update($data);

        return response()->json(['data' => (new ApiConsumerResource($apiConsumer->fresh(['plan', 'keys'])))->resolve($request)]);
    }

    /** The consumer and its keys go; its usage stays in the reports. */
    public function destroy(ApiConsumer $apiConsumer): JsonResponse
    {
        Gate::authorize('delete', $apiConsumer);
        $apiConsumer->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'email' => 'nullable|email|max:255',
            'external_id' => 'nullable|string|max:191',
            'jwt_subject' => 'nullable|string|max:191',
            'plan_id' => 'nullable|string|size:26',
            'status' => 'nullable|string|in:active,suspended',
            'metadata' => 'nullable|array|max:50',
        ];
    }
}
