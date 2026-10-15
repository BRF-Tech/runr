<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\GatewayResource;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Project;
use App\Services\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

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

    public function show(Gateway $gateway): GatewayResource
    {
        Gate::authorize('view', $gateway);
        $gateway->load(['routes.project', 'knativeRegion']);

        return new GatewayResource($gateway);
    }

    /**
     * Gateway settings: optional Traefik rate limit for the function endpoints behind this
     * gateway (`rate_limit: {average, burst}` requests per second per client IP, `null` = off).
     */
    public function update(Request $request, Gateway $gateway): GatewayResource
    {
        Gate::authorize('update', $gateway);

        $data = $request->validate([
            'rate_limit' => 'present|nullable|array',
            'rate_limit.average' => 'required_with:rate_limit|integer|min:1|max:100000',
            'rate_limit.burst' => 'nullable|integer|min:1|max:100000',
        ]);

        $gateway = $this->gatewayService->updateRateLimit($gateway, $data['rate_limit'] ?? null);
        $gateway->load(['routes.project', 'knativeRegion']);

        return new GatewayResource($gateway);
    }

    public function routes(Gateway $gateway): JsonResponse
    {
        Gate::authorize('view', $gateway);
        $routes = $gateway->routes()->with('project')->get();

        return response()->json(['data' => $routes]);
    }

    public function addRoute(Request $request, Gateway $gateway): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|ulid|exists:projects,id',
            'path' => 'required|string|max:255|regex:/^\/[a-zA-Z0-9\/_-]*$/',
            'methods' => 'nullable|array',
            'methods.*' => 'string|in:GET,POST,PUT,PATCH,DELETE',
        ]);

        $project = Project::findOrFail($data['project_id']);
        Gate::authorize('route', [$gateway, $project]);

        $route = $this->gatewayService->addRoute(
            $gateway,
            $project,
            $data['path'],
            $data['methods'] ?? null,
        );

        $project->update(['endpoint_type' => 'gateway']);

        return response()->json(['data' => $route->load('project')], 201);
    }

    public function updateRoute(Request $request, Gateway $gateway, GatewayRoute $route): JsonResponse
    {
        abort_unless((string) $route->gateway_id === (string) $gateway->id, 404);
        Gate::authorize('route', [$gateway, $route->project]);
        $data = $request->validate([
            'path' => 'sometimes|string|max:255|regex:/^\/[a-zA-Z0-9\/_-]*$/',
            'methods' => 'nullable|array',
            'methods.*' => 'string|in:GET,POST,PUT,PATCH,DELETE',
        ]);

        $route = $this->gatewayService->updateRoute($route, $data);

        return response()->json(['data' => $route->load('project')]);
    }

    public function removeRoute(Gateway $gateway, GatewayRoute $route): JsonResponse
    {
        abort_unless((string) $route->gateway_id === (string) $gateway->id, 404);
        Gate::authorize('route', [$gateway, $route->project]);
        $project = $route->project;
        $this->gatewayService->removeRoute($route);

        $project->update(['endpoint_type' => 'subdomain']);

        return response()->json(null, 204);
    }
}
