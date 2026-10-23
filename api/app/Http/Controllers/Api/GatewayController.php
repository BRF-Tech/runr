<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\GatewayResource;
use App\Http\Resources\GatewayRouteResource;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Environments\EnvironmentResolver;
use App\Services\GatewayService;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\Residency\RegionPolicy;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GatewayController extends Controller
{
    public function __construct(private GatewayService $gatewayService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $gateways = Gateway::where('user_id', $request->user()->id)
            ->with(['routes.project', 'knativeRegion'])
            ->get();

        return GatewayResource::collection($gateways);
    }

    /**
     * The caller's gateway in a region, created when there is none (one per member and region):
     * a gateway for the routes to the environments that run in another region than the
     * caller's projects (docs/environments.md#gateway-routes).
     */
    #[OpenApiResponse(200, 'The caller\'s gateway in the region (it had one)', type: 'array{data: GatewayResource}')]
    #[OpenApiResponse(201, 'The caller\'s new gateway in the region', type: 'array{data: GatewayResource}')]
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Gateway::class);
        $data = $request->validate([
            'knative_region_id' => 'required|string|exists:knative_regions,id',
        ]);

        $region = KnativeRegion::query()->findOrFail($data['knative_region_id']);
        if ($region->is_demo || ! $region->kubeconfig) {
            throw ValidationException::withMessages(['knative_region_id' => __('api_product.gateway.region_unusable', ['region' => $region->name])]);
        }
        // Data residency (docs/supply-chain.md): a gateway serves the region's workloads
        app(RegionPolicy::class)->authorize($region, RegionPolicy::ENVIRONMENT, field: 'knative_region_id');

        $existing = Gateway::query()->where('user_id', $request->user()->id)->where('knative_region_id', $region->id)->exists();
        $gateway = $this->gatewayService->findOrCreateForRegion($request->user(), $region);

        return (new GatewayResource($gateway->load(['routes.project', 'knativeRegion'])))->response()->setStatusCode($existing ? 200 : 201);
    }

    public function show(Gateway $gateway): GatewayResource
    {
        Gate::authorize('view', $gateway);
        $gateway->load(['routes.project', 'knativeRegion']);

        return new GatewayResource($gateway);
    }

    /**
     * Gateway settings: optional Traefik rate limit for the function endpoints behind this
     * gateway (`rate_limit: {average, burst}` requests per second per client IP, `null` = off);
     * the public developer page (`developer_page`, `description`, and `developer_page_metrics`:
     * the routes' latency and success rate on it, docs/api-product.md).
     */
    public function update(Request $request, Gateway $gateway): GatewayResource
    {
        Gate::authorize('update', $gateway);

        if (! $request->hasAny(['rate_limit', 'developer_page', 'description', 'developer_page_metrics'])) {
            throw ValidationException::withMessages(['rate_limit' => __('validation.present', ['attribute' => 'rate limit'])]);
        }

        $data = $request->validate([
            'rate_limit' => 'nullable|array',
            'rate_limit.average' => 'required_with:rate_limit|integer|min:1|max:100000',
            'rate_limit.burst' => 'nullable|integer|min:1|max:100000',
            'developer_page' => 'sometimes|boolean',
            'description' => 'sometimes|nullable|string|max:5000',
            'developer_page_metrics' => 'sometimes|boolean',
        ]);

        $page = array_intersect_key($data, array_flip(['developer_page', 'description', 'developer_page_metrics']));
        if ($page !== []) {
            $gateway->update($page);
        }
        if ($request->exists('rate_limit')) {
            $gateway = $this->gatewayService->updateRateLimit($gateway, $data['rate_limit'] ?? null);
        }
        $gateway->load(['routes.project', 'knativeRegion']);

        return new GatewayResource($gateway);
    }

    /** The gateway's routes, each as the gateway shows it. */
    #[OpenApiResponse(200, 'The routes of the gateway', type: 'array{data: list<GatewayRouteResource>}')]
    public function routes(Gateway $gateway): JsonResponse
    {
        Gate::authorize('view', $gateway);
        $host = $this->gatewayService->publicHost($gateway);
        $routes = [];
        foreach ($gateway->routes()->with('project')->get() as $route) {
            /** @var GatewayRoute $route */
            $routes[] = GatewayRouteResource::onHost($route, $host);
        }

        return response()->json(['data' => $routes]);
    }

    /**
     * A route to a project's own workload, or with `environment` (a name or id) to one of its
     * environments (docs/environments.md#gateway-routes). The gateway must be in the region the
     * workload runs in; a project environment has one route, a path is used once per gateway.
     * The route's API product settings come with it, validated as on a change (`auth_mode`,
     * `jwt`, `gateway_only`): a key or JWT route reaches the region with its check in front of
     * it, never public for a moment (docs/api-product.md#routes).
     */
    #[OpenApiResponse(201, 'The new route, as the gateway shows it', type: 'array{data: GatewayRouteResource}')]
    public function addRoute(Request $request, Gateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|ulid|exists:projects,id',
            'environment' => 'nullable|string|max:64',
            'path' => 'required|string|max:255|regex:/^\/[a-zA-Z0-9\/_-]*$/',
            'methods' => 'nullable|array',
            'methods.*' => 'string|in:GET,POST,PUT,PATCH,DELETE',
            // the API product settings: the rules of updateRoute() (inline, so the API document has them)
            'auth_mode' => 'sometimes|string|in:'.implode(',', GatewayRoute::AUTH_MODES),
            'jwt' => 'sometimes|nullable|array',
            'jwt.jwks_url' => 'required_with:jwt|string|max:2048|url:http,https',
            'jwt.issuer' => 'nullable|string|max:255',
            'jwt.audience' => 'nullable|string|max:255',
            'jwt.subject_claim' => 'nullable|string|max:64|regex:/^[A-Za-z0-9_.:-]+$/',
            'jwt.require_consumer' => 'nullable|boolean',
            'gateway_only' => 'sometimes|boolean',
        ]);

        $project = Project::findOrFail($data['project_id']);
        Gate::authorize('route', [$gateway, $project]);
        $environment = EnvironmentResolver::resolve($project, $data['environment'] ?? null);

        $this->assertPathFree($gateway, $data['path']);
        $settings = $this->settings($data);
        $this->assertJwt($settings['auth_mode'] ?? GatewayRoute::AUTH_PUBLIC, $settings['jwt_config'] ?? null);
        if (! empty($settings['gateway_only'])) {
            // the route as it will be: the workload it leads to
            $draft = new GatewayRoute(['project_id' => $project->getKey(), 'environment_id' => $environment?->getKey()]);
            $draft->setRelation('project', $project);
            $this->assertGatewayOnly($gateway, $draft);
        }

        $route = $this->gatewayService->addRoute(
            $gateway,
            $project,
            $data['path'],
            $data['methods'] ?? null,
            $environment,
            $settings,
        );
        if ($route->gateway_only) {
            $this->gatewayService->applyGatewayOnly($route, true);
        }

        // endpoint_type is the project's own workload's (production's) endpoint
        if ($environment === null) {
            $project->update(['endpoint_type' => 'gateway']);
        }

        return (new GatewayRouteResource($route->load('project')))->response()->setStatusCode(201);
    }

    /**
     * Path and methods; the API product settings of the route (docs/api-product.md):
     * `auth_mode` public | key | jwt, `jwt` {jwks_url, issuer, audience, subject_claim,
     * require_consumer} for jwt, `gateway_only` (the own URL of the workload the route leads to —
     * the project's, or an environment's — stops serving). A path another route of the gateway
     * has is refused (422).
     */
    public function updateRoute(Request $request, Gateway $gateway, GatewayRoute $route): GatewayRouteResource
    {
        abort_unless((string) $route->gateway_id === (string) $gateway->id, 404);
        Gate::authorize('route', [$gateway, $route->project]);
        $data = $request->validate([
            'path' => 'sometimes|string|max:255|regex:/^\/[a-zA-Z0-9\/_-]*$/',
            'methods' => 'nullable|array',
            'methods.*' => 'string|in:GET,POST,PUT,PATCH,DELETE',
            // the API product settings: the rules of addRoute()
            'auth_mode' => 'sometimes|string|in:'.implode(',', GatewayRoute::AUTH_MODES),
            'jwt' => 'sometimes|nullable|array',
            'jwt.jwks_url' => 'required_with:jwt|string|max:2048|url:http,https',
            'jwt.issuer' => 'nullable|string|max:255',
            'jwt.audience' => 'nullable|string|max:255',
            'jwt.subject_claim' => 'nullable|string|max:64|regex:/^[A-Za-z0-9_.:-]+$/',
            'jwt.require_consumer' => 'nullable|boolean',
            'gateway_only' => 'sometimes|boolean',
        ]);

        if (isset($data['path'])) {
            $this->assertPathFree($gateway, $data['path'], $route);
        }
        $settings = $this->settings($data);
        $data = array_diff_key($data, array_flip(['auth_mode', 'jwt', 'gateway_only'])) + $settings;
        $this->assertJwt($data['auth_mode'] ?? $route->auth_mode, array_key_exists('jwt_config', $data) ? $data['jwt_config'] : $route->jwt_config);

        $gatewayOnly = $data['gateway_only'] ?? null;
        if ($gatewayOnly === true && ! $route->gateway_only) {
            $this->assertGatewayOnly($gateway, $route);
        }

        $changesVisibility = $gatewayOnly !== null && $gatewayOnly !== (bool) $route->gateway_only;
        $route = $this->gatewayService->updateRoute($route, $data);
        if ($changesVisibility) {
            $this->gatewayService->applyGatewayOnly($route, (bool) $gatewayOnly);
        }

        return new GatewayRouteResource($route->load('project'));
    }

    public function removeRoute(Gateway $gateway, GatewayRoute $route): JsonResponse
    {
        abort_unless((string) $route->gateway_id === (string) $gateway->id, 404);
        Gate::authorize('route', [$gateway, $route->project]);
        $project = $route->project;
        $forEnvironment = $route->forEnvironment();
        $this->gatewayService->removeRoute($route);

        // production's endpoint goes back to its own URL; an environment's route leaves it alone
        if (! $forEnvironment) {
            $project->update(['endpoint_type' => 'subdomain']);
        }

        return response()->json(null, 204);
    }

    /**
     * The API product settings a request names, as the route stores them: auth_mode,
     * jwt_config (from `jwt`; null clears it) and gateway_only.
     *
     * @return array{auth_mode?: string, jwt_config?: array<string, mixed>|null, gateway_only?: bool}
     */
    private function settings(array $data): array
    {
        $settings = array_intersect_key($data, array_flip(['auth_mode', 'gateway_only']));
        if (array_key_exists('jwt', $data)) {
            $settings['jwt_config'] = $data['jwt'];
        }

        return $settings;
    }

    /** A JWT route verifies tokens with its issuer's JWKS: it needs the URL. */
    private function assertJwt(?string $mode, ?array $jwt): void
    {
        if ($mode === GatewayRoute::AUTH_JWT && empty($jwt['jwks_url'] ?? null)) {
            throw ValidationException::withMessages(['jwt.jwks_url' => __('api_product.route.jwks_required')]);
        }
    }

    /** A path is used once per gateway ($except: the route whose path changes). */
    private function assertPathFree(Gateway $gateway, string $path, ?GatewayRoute $except = null): void
    {
        $path = '/'.ltrim($path, '/');
        $taken = GatewayRoute::query()->where('gateway_id', $gateway->id)->where('path', $path)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['path' => __('api_product.route.path_taken', ['path' => $path])]);
        }
    }

    /**
     * "Only through the gateway" for the workload the route leads to: refused while the workload
     * has custom domains (they would get around the gateway), and for a global project's own
     * workload (it is served on its global host name, which this would close).
     */
    private function assertGatewayOnly(Gateway $gateway, GatewayRoute $route): void
    {
        // the custom domains of the workload the route leads to (an environment has its own)
        if (($route->target() ?? $route->project)->domains()->exists()) {
            throw ValidationException::withMessages(['gateway_only' => __('api_product.gateway_only.has_domains')]);
        }
        if (! $route->forEnvironment() && ($reason = app(GlobalProjectService::class)->gatewayRefusal($route->project, $gateway, true))) {
            throw ValidationException::withMessages(['gateway_only' => [$reason]]);
        }
    }
}
