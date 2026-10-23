<?php

namespace App\Http\Resources;

use App\Services\ApiProduct\DeveloperPage;
use App\Services\ApiProduct\GatewayDomains;
use App\Services\GatewayService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GatewayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The default host is built from the region's function domain, as the IngressRoute and the
        // project's endpoint URL are (GatewayManifestTrait); the routes answer on the custom domain
        // when the gateway has one
        $gateways = app(GatewayService::class);
        $host = $gateways->gatewayHost($this->resource);
        $publicHost = $gateways->publicHost($this->resource);

        return [
            'id' => $this->id,
            'subdomain' => $this->subdomain,
            'custom_domain' => $this->custom_domain,
            // API product (docs/api-product.md): a requested domain waiting for its DNS records
            'custom_domain_pending' => $this->resource->custom_domain_pending ? $this->pendingDomain() : null,
            /** @var string|null */
            'custom_domain_verified_at' => $this->resource->custom_domain_verified_at?->toISOString(),
            'status' => $this->status,
            // pending / error: why — e.g. gateway-auth not ready, its key and JWT routes answer 503
            'status_message' => $this->resource->status_message,
            'knative_region_id' => $this->knative_region_id,
            'region' => $this->whenLoaded('knativeRegion', fn () => [
                'id' => $this->resource->knativeRegion->id,
                'name' => $this->resource->knativeRegion->name,
            ]),
            'host' => $host,
            'rate_limit' => $this->rate_limit_average ? [
                'average' => (int) $this->rate_limit_average,
                'burst' => (int) ($this->rate_limit_burst ?: $this->rate_limit_average),
            ] : null,
            'developer_page' => (bool) $this->resource->developer_page,
            'developer_page_key' => $this->resource->developer_page ? DeveloperPage::keyFor($this->resource) : null,
            'description' => $this->resource->description,
            'developer_page_metrics' => (bool) $this->resource->developer_page_metrics,
            /** @var list<array{id: string, path: string, methods: list<string>|null, project_id: string, project: array{id: string, name: string, slug: string}|null, environment_id: string|null, environment: string|null, url: string|null, auth_mode: "public"|"key"|"jwt", jwt: array{jwks_url: string, issuer: string|null, audience: string|null, subject_claim: string, require_consumer: bool}|null, gateway_only: bool}> */
            'routes' => $this->whenLoaded('routes', fn () => $this->resource->routes
                ->map(fn ($route) => GatewayRouteResource::onHost($route, $publicHost)->resolve($request))
                ->values()->all()),
            'created_at' => $this->created_at,
        ];
    }

    /** @return array{domain: string, verification: array{type: string, name: string, value: string}, dns_targets: list<array{type: string, name: string, value: string}>} */
    private function pendingDomain(): array
    {
        $domains = app(GatewayDomains::class);
        $domain = (string) $this->resource->custom_domain_pending;

        return [
            'domain' => $domain,
            'verification' => [
                'type' => 'TXT',
                'name' => $domains->challengeName($domain),
                'value' => $domains->challengeValue($this->resource),
            ],
            /** @var list<array{type: string, name: string, value: string}> */
            'dns_targets' => array_map(
                fn ($t) => ['type' => $t['type'], 'name' => $domain, 'value' => $t['value']],
                $domains->dnsTargets($this->resource),
            ),
        ];
    }
}
