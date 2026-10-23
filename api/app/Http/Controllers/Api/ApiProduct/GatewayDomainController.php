<?php

namespace App\Http\Controllers\Api\ApiProduct;

use App\Http\Controllers\Api\Controller;
use App\Http\Resources\GatewayResource;
use App\Models\Gateway;
use App\Rules\CustomDomain;
use App\Services\ApiProduct\GatewayDomains;
use App\Services\GatewayService;
use App\Services\Helpers\DomainName;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The gateway's own domain (docs/api-product.md#custom-domain): requested, proven by a TXT record
 * and a CNAME / address pointing at the gateway, then served. An unverified name is never routed.
 *
 * @tags API product
 */
class GatewayDomainController extends Controller
{
    public function __construct(
        private readonly GatewayDomains $domains,
        private readonly GatewayService $gateways,
    ) {}

    /** The name waits for verification; the TXT record to create is in custom_domain_pending. */
    #[OpenApiResponse(200, 'The gateway with the domain waiting for verification (custom_domain_pending)', type: 'array{data: GatewayResource}')]
    #[OpenApiResponse(422, 'The domain cannot be used', type: 'array{message: string, errors: array<string, list<string>>}')]
    public function store(Request $request, Gateway $gateway): JsonResponse
    {
        Gate::authorize('update', $gateway);
        if (is_string($request->input('domain'))) {
            $request->merge(['domain' => DomainName::normalize($request->input('domain'))]);
        }
        $data = $request->validate(['domain' => ['bail', 'required', 'string', 'max:253', new CustomDomain]]);

        if ($problem = $this->domains->problem($gateway, $data['domain'])) {
            return response()->json(['message' => $problem, 'errors' => ['domain' => [$problem]]], 422);
        }

        $this->domains->request($gateway, $data['domain']);

        return $this->respond($gateway);
    }

    #[OpenApiResponse(200, 'The domain is verified and routed', type: 'array{message: string, data: GatewayResource}')]
    #[OpenApiResponse(422, 'Not verified yet: why, and the gateway', type: 'array{message: string, data: GatewayResource}')]
    public function verify(Gateway $gateway): JsonResponse
    {
        Gate::authorize('update', $gateway);

        $problem = $this->domains->verify($gateway);
        if ($problem !== null) {
            return $this->respond($gateway, $problem, 422);
        }

        $this->gateways->syncToCluster($gateway->fresh(['routes.project']));

        return $this->respond($gateway, __('api_product.domain.active'));
    }

    #[OpenApiResponse(200, 'The gateway without its own domain', type: 'array{data: GatewayResource}')]
    public function destroy(Gateway $gateway): JsonResponse
    {
        Gate::authorize('update', $gateway);

        $routed = $gateway->custom_domain !== null;
        $this->domains->remove($gateway);
        if ($routed) {
            $this->gateways->syncToCluster($gateway->fresh(['routes.project']));
        }

        return $this->respond($gateway);
    }

    private function respond(Gateway $gateway, ?string $message = null, int $status = 200): JsonResponse
    {
        $data = (new GatewayResource($gateway->fresh(['routes.project', 'knativeRegion'])))->resolve();

        return response()->json(array_filter(['message' => $message, 'data' => $data], fn ($v) => $v !== null), $status);
    }
}
