<?php

namespace App\Http\Resources;

use App\Models\GatewayRoute;
use App\Models\ProjectEnvironment;
use App\Services\ApiProduct\GatewayAuthConfig;
use App\Services\GatewayService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gateway route as every answer shows it — a gateway's routes, the route listing, and the
 * answers of adding and changing a route: where it leads, its URL and its API product settings
 * (docs/api-product.md#routes).
 *
 * @mixin GatewayRoute
 */
class GatewayRouteResource extends JsonResource
{
    /** The host the gateway publishes its routes on; false: looked up from the route's gateway. */
    private string|false|null $publicHost = false;

    /**
     * The route of a gateway whose public host (GatewayService::publicHost) is known: a gateway
     * looks it up once for all its routes.
     */
    public static function onHost(GatewayRoute $route, ?string $publicHost): self
    {
        $resource = new self($route);
        $resource->publicHost = $publicHost;

        return $resource;
    }

    public function toArray(Request $request): array
    {
        /** @var GatewayRoute $route */
        $route = $this->resource;
        $host = $this->publicHost !== false ? $this->publicHost : app(GatewayService::class)->publicHost($route->gateway);
        $project = $route->relationLoaded('project') ? $route->project : null;

        return [
            'id' => (string) $route->id,
            'path' => $route->path,
            /** @var list<string>|null */
            'methods' => $route->methods,
            'project_id' => (string) $route->project_id,
            /** @var array{id: string, name: string, slug: string}|null */
            'project' => $project ? [
                'id' => (string) $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
            ] : null,
            // the environment the route leads to (null: the project's own workload, docs/environments.md)
            /** @var string|null */
            'environment_id' => $route->forEnvironment() ? (string) $route->environment_id : null,
            /** @var string|null */
            'environment' => $route->forEnvironment() ? ProjectEnvironment::query()->whereKey($route->environment_id)->value('name') : null,
            // on the gateway's custom domain once it is verified
            /** @var string|null */
            'url' => $host ? "https://{$host}{$route->path}" : null,
            /** @var "public"|"key"|"jwt" */
            'auth_mode' => $route->auth_mode ?: GatewayRoute::AUTH_PUBLIC,
            /** @var array{jwks_url: string, issuer: string|null, audience: string|null, subject_claim: string, require_consumer: bool}|null */
            'jwt' => $route->auth_mode === GatewayRoute::AUTH_JWT ? GatewayAuthConfig::jwt($route) : null,
            'gateway_only' => (bool) $route->gateway_only,
        ];
    }
}
