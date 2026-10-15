<?php

namespace App\Services;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Services\Concerns\GatewayManifestTrait;
use Illuminate\Support\Facades\Log;

class GatewayService
{
    use GatewayManifestTrait;

    public function findOrCreateForRegion(User $user, KnativeRegion $region): Gateway
    {
        return Gateway::firstOrCreate(
            ['user_id' => $user->id, 'knative_region_id' => $region->id],
            ['subdomain' => $user->username, 'status' => 'active'],
        );
    }

    public function addRoute(Gateway $gateway, Project $project, string $path, ?array $methods = null): GatewayRoute
    {
        $path = '/'.ltrim($path, '/');
        $maxOrder = $gateway->routes()->max('sort_order') ?? 0;

        $route = GatewayRoute::create([
            'gateway_id' => $gateway->id,
            'project_id' => $project->id,
            'path' => $path,
            'methods' => $methods,
            'sort_order' => $maxOrder + 1,
        ]);

        $this->syncToCluster($gateway->fresh(['routes.project']));

        return $route;
    }

    public function updateRoute(GatewayRoute $route, array $data): GatewayRoute
    {
        if (isset($data['path'])) {
            $data['path'] = '/'.ltrim($data['path'], '/');
        }

        $route->update($data);

        $this->syncToCluster($route->gateway->fresh(['routes.project']));

        return $route->fresh();
    }

    /**
     * Set (average/burst requests per second) or clear (null) the gateway's Traefik rate limit.
     *
     * @param  array{average: int, burst?: int|null}|null  $rateLimit
     */
    public function updateRateLimit(Gateway $gateway, ?array $rateLimit): Gateway
    {
        $gateway->update([
            'rate_limit_average' => $rateLimit['average'] ?? null,
            'rate_limit_burst' => $rateLimit ? ($rateLimit['burst'] ?? null) : null,
        ]);

        $this->syncToCluster($gateway->fresh(['routes.project']));

        return $gateway->fresh();
    }

    public function removeRoute(GatewayRoute $route): void
    {
        $gateway = $route->gateway;
        $route->delete();

        $this->syncToCluster($gateway->fresh(['routes.project']));
    }

    public function syncToCluster(Gateway $gateway): void
    {
        $region = $gateway->knativeRegion;

        if (! $region || ! $region->kubeconfig || $region->is_demo) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;

            // Apply StripPrefix middlewares for each route
            foreach ($gateway->routes as $route) {
                if ($route->strip_prefix) {
                    $middleware = $this->buildStripPrefixMiddleware($route, $namespace);
                    $k8s->applyResource($middleware, $namespace);
                }
            }

            // Optional per-gateway rate limit (must exist before the IngressRoute references it)
            $rateLimit = $this->buildRateLimitMiddleware($gateway, $namespace);
            if ($rateLimit) {
                $k8s->applyResource($rateLimit, $namespace);
            }

            // Apply IngressRoute
            $ingressRoute = $this->buildIngressRoute($gateway);
            $k8s->applyResource($ingressRoute, $namespace);

            // Rate limit switched off: remove the Middleware once no route references it
            if (! $rateLimit) {
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->rateLimitMiddlewareName($gateway), $namespace);
            }

            $gateway->update(['status' => 'active']);

            Log::info('Gateway synced', [
                'gateway' => $gateway->id,
                'routes' => $gateway->routes->count(),
            ]);
        } catch (\Throwable $e) {
            $gateway->update(['status' => 'error']);

            Log::error('Gateway sync failed', [
                'gateway' => $gateway->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function teardown(Gateway $gateway): void
    {
        $region = $gateway->knativeRegion;

        if (! $region || ! $region->kubeconfig || $region->is_demo) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;

            // Delete IngressRoute
            $k8s->deleteResource('traefik.io/v1alpha1', 'IngressRoute', "gw-{$gateway->id}", $namespace);

            // Delete middlewares
            foreach ($gateway->routes as $route) {
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', "gw-strip-{$route->id}", $namespace);
            }
            $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->rateLimitMiddlewareName($gateway), $namespace);
        } catch (\Throwable $e) {
            Log::error('Gateway teardown failed', [
                'gateway' => $gateway->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
