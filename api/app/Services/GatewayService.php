<?php

namespace App\Services;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Services\ApiProduct\GatewayAuthDeployer;
use App\Services\ApiProduct\GatewayAuthRevision;
use App\Services\CertManager\CertManagerService;
use App\Services\Concerns\GatewayManifestTrait;
use App\Services\Environments\DeployTarget;
use App\Services\Environments\EnvironmentScope;
use App\Services\GlobalProjects\GlobalProjectService;
use App\Services\Manifests\GatewayAuthManifests;
use App\Support\SchemaColumns;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GatewayService
{
    use GatewayManifestTrait;

    // State of the API product of a gateway (applyAuth()): no key / JWT route; gateway-auth checks
    // them; it is starting (they refuse); it failed (they refuse, the gateway shows the error)
    public const AUTH_NONE = 'none';

    public const AUTH_READY = 'ready';

    public const AUTH_PENDING = 'pending';

    public const AUTH_FAILED = 'failed';

    /** How long gateway-auth may take to get ready before a gateway shows an error. */
    private const AUTH_START_SECONDS = 300;

    public function findOrCreateForRegion(User $user, KnativeRegion $region): Gateway
    {
        return Gateway::firstOrCreate(
            ['user_id' => $user->id, 'knative_region_id' => $region->id],
            ['subdomain' => $user->username, 'status' => 'active'],
        );
    }

    /**
     * A route of the gateway to the project's own workload, or to one of its environments
     * ($environment, docs/environments.md#gateway-routes): the route hands requests to the
     * environment's Knative Service <workload>. The gateway must be in the region the workload
     * runs in (its rules hand requests to that region's Traefik), and a project environment has
     * one route.
     *
     * $settings: the route's API product settings (auth_mode, jwt_config, gateway_only) — stored
     * with the route, so its first sync already puts a key or JWT route behind its forwardAuth
     * Middleware (applied before the IngressRoute that references it): the route is never public
     * for a moment. A path or workload another request took in the meantime (the unique indexes)
     * is refused like the checks before.
     *
     * @param  array{auth_mode?: string, jwt_config?: array<string, mixed>|null, gateway_only?: bool}  $settings
     *
     * @throws ValidationException
     */
    public function addRoute(Gateway $gateway, Project $project, string $path, ?array $methods = null, ?ProjectEnvironment $environment = null, array $settings = []): GatewayRoute
    {
        $path = '/'.ltrim($path, '/');
        $environment = $environment !== null && ! $environment->is_primary ? $environment : null;
        $target = DeployTarget::environment(EnvironmentScope::base($project), $environment);
        $name = $environment->name ?? ProjectEnvironment::PRIMARY_NAME;

        $taken = GatewayRoute::query()->where('project_id', $project->getKey());
        if (SchemaColumns::has(new GatewayRoute, 'environment_id')) {
            $environment === null ? $taken->whereNull('environment_id') : $taken->where('environment_id', $environment->getKey());
        }
        if ($taken->exists()) {
            throw ValidationException::withMessages([($environment ? 'environment' : 'project_id') => __('api_product.route.taken', ['environment' => $name])]);
        }
        // a global project's own workload runs in each of its regions: a gateway of any of them
        // hands requests to that region's copy (docs/global-projects.md#gateways)
        if ($environment === null && $target->isGlobal()) {
            if ($reason = app(GlobalProjectService::class)->gatewayRefusal($target, $gateway)) {
                throw ValidationException::withMessages(['project_id' => $reason]);
            }
        } elseif ($target->knative_region_id !== null && (string) $target->knative_region_id !== (string) $gateway->knative_region_id) {
            throw ValidationException::withMessages([($environment ? 'environment' : 'project_id') => __('api_product.route.region_mismatch', [
                'environment' => $name,
                'region' => $target->knativeRegion->name ?? (string) $target->knative_region_id,
            ])]);
        }

        $maxOrder = $gateway->routes()->max('sort_order') ?? 0;

        try {
            // in a transaction of its own (a savepoint inside the caller's): on PostgreSQL a refused
            // insert aborts the transaction around it, the savepoint keeps the caller's usable
            $route = DB::transaction(fn () => GatewayRoute::create([
                'gateway_id' => $gateway->id,
                'project_id' => $project->id,
                'path' => $path,
                'methods' => $methods,
                'sort_order' => $maxOrder + 1,
            ] + array_intersect_key($settings, array_flip(['auth_mode', 'jwt_config', 'gateway_only']))
              + ($environment !== null ? ['environment_id' => $environment->getKey()] : [])));
        } catch (UniqueConstraintViolationException $e) {
            throw $this->takenError($e, $path, $environment ? 'environment' : 'project_id', $name);
        }

        $this->syncToCluster($gateway->fresh(['routes.project']));

        return $route;
    }

    /**
     * A unique index refused the route — another request took the path on the gateway, or the
     * workload's route, between the check and the write: the same 422 as the check.
     */
    private function takenError(UniqueConstraintViolationException $e, string $path, string $field, string $environment): ValidationException
    {
        // the driver's message names the index or its columns (not the SQL, which names every
        // column): PostgreSQL (16, 17) and MySQL the index gateway_routes_gateway_id_path_unique
        // (PostgreSQL also "Key (gateway_id, path)=…"), SQLite the columns gateway_routes.path. Not
        // just "path": PostgreSQL's DETAIL carries the key's values, which another index's could hold
        $message = (string) ($e->errorInfo[2] ?? '');
        if (str_contains($message, 'gateway_id_path') || str_contains($message, '(gateway_id, path)') || str_contains($message, 'gateway_routes.path')) {
            return ValidationException::withMessages(['path' => __('api_product.route.path_taken', ['path' => $path])]);
        }

        return ValidationException::withMessages([$field => __('api_product.route.taken', ['environment' => $environment])]);
    }

    /**
     * Every gateway with a route of the project — to its own workload or to one of its
     * environments — synced again (e.g. its access mode changed).
     */
    public function syncProjectGateways(Project $project): void
    {
        $ids = GatewayRoute::query()->where('project_id', EnvironmentScope::base($project)->getKey())->distinct()->pluck('gateway_id');
        foreach (Gateway::query()->whereIn('id', $ids)->with('routes.project')->get() as $gateway) {
            $this->syncToCluster($gateway);
        }
    }

    /**
     * Remove the routes that lead to the project's own workload ($environment null) or to one of
     * its environments — the environment or the project is deleted; their gateways are synced
     * without them.
     *
     * @return list<string> routes whose removal failed
     */
    public function removeRoutesOf(Project $project, ?ProjectEnvironment $environment = null): array
    {
        $environment = $environment !== null && ! $environment->is_primary ? $environment : null;
        $routes = GatewayRoute::query()->where('project_id', EnvironmentScope::base($project)->getKey());
        if (SchemaColumns::has(new GatewayRoute, 'environment_id')) {
            $environment === null ? $routes->whereNull('environment_id') : $routes->where('environment_id', $environment->getKey());
        } elseif ($environment !== null) {
            return [];
        }

        $failures = [];
        foreach ($routes->with(['gateway', 'project'])->get() as $route) {
            try {
                $this->removeRoute($route);
            } catch (\Throwable $e) {
                $failures[] = "gateway route {$route->path}: {$e->getMessage()}";
            }
        }

        return $failures;
    }

    /**
     * Change the route's fields and sync its gateway. A path another route of the gateway took
     * (the unique index) is refused with a 422.
     *
     * @throws ValidationException
     */
    public function updateRoute(GatewayRoute $route, array $data): GatewayRoute
    {
        if (isset($data['path'])) {
            $data['path'] = '/'.ltrim($data['path'], '/');
        }

        try {
            // a savepoint, as in addRoute()
            DB::transaction(fn () => $route->update($data));
        } catch (UniqueConstraintViolationException $e) {
            $route->refresh();

            throw $this->takenError($e, (string) ($data['path'] ?? $route->path), 'path', ProjectEnvironment::PRIMARY_NAME);
        }

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
        if ($route->gateway_only) {
            // the project's own URL serves again
            $this->applyGatewayOnly($route, false);
        }
        $route->delete();

        $this->syncToCluster($gateway->fresh(['routes.project']));

        // after the IngressRoute stopped referencing it
        $region = $gateway->knativeRegion;
        if ($region && $region->kubeconfig && ! $region->is_demo) {
            try {
                (new KubernetesClient($region->kubeconfig))->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->hostMiddlewareName($route), $region->project_namespace ?? $region->name);
            } catch (\Throwable $e) {
                Log::warning('Gateway route Middleware not removed', ['route' => $route->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * "Only through the gateway" (docs/api-product.md#only-through-the-gateway): the project's
     * Knative Service becomes cluster-local, so its own <slug>.<domain> URL stops serving and callers
     * cannot get around the route's keys. KnativeManifestTrait renders the label on every deploy;
     * this patches the running Service right away. Gateway routes reach a cluster-local Service on
     * the region Traefik's private entrypoint. A route to an environment does it to the
     * environment's Service <workload> in its region: its own URL stops serving, production's stays.
     *
     * @return bool false when the region could not be updated (the next deploy applies it)
     */
    public function applyGatewayOnly(GatewayRoute $route, bool $on): bool
    {
        $project = $route->target();
        if ($project === null) {
            return true;
        }
        $region = $project->knativeRegion;
        if (! $region || ! $region->kubeconfig || $region->is_demo) {
            return true;
        }
        // a queue-triggered Service is cluster-local anyway
        if (! $on && app(ProjectDomainService::class)->triggerType($project) === 'queue') {
            return true;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;
            if (! $k8s->getResourceStatus('serving.knative.dev/v1', 'Service', $project->slug, $namespace)) {
                return true; // not deployed: the first deploy renders it
            }

            return $k8s->mergePatch('serving.knative.dev/v1', 'Service', $project->slug, $namespace, [
                'metadata' => ['labels' => ['networking.knative.dev/visibility' => $on ? 'cluster-local' : null]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Gateway-only visibility not applied', ['route' => $route->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * What the gateway's routes look like in the region — read-only: the public host of every
     * route's workload, whether its key / JWT routes go through gateway-auth's metering proxy, and
     * the routing revision: a hash of every routing object RunR would apply (the IngressRoutes, the
     * strip / Host / upstream / rate-limit Middlewares, which routes are checked and metered). The
     * gateway's IngressRoute carries it (ROUTING_LABEL); region:reconcile syncs a gateway whose
     * IngressRoute does not (GatewayRoutesStep), e.g. one an earlier release built.
     *
     * $move: a domain move that just completed (PublicHostMove) — the routes follow it before
     * Knative names the new hosts in the services' status.
     *
     * @return array{publicHosts: array<string, string>, metered: bool, revision: string}
     */
    public function routing(KubernetesClient $k8s, Gateway $gateway, ?PublicHostMove $move = null): array
    {
        $region = $gateway->knativeRegion;
        $namespace = $region->project_namespace ?? $region->name;
        $served = PublicHostRewrites::servedDomains($k8s, $region->knative_serving_namespace ?: 'knative-serving');

        // the public host of every route's workload (a project without one, e.g. never deployed,
        // is left out of the routes)
        $publicHosts = [];
        foreach ($gateway->routes as $route) {
            // the project's own workload, or the environment's the route leads to
            $workload = $this->routeWorkload($route);
            if ($workload === null) {
                Log::warning('Gateway route left out: its environment is gone', ['gateway' => $gateway->id, 'route' => $route->id]);

                continue;
            }
            // access mode internal: no public route, the gateway leaves it out
            if ($workload->accessMode() === Project::ACCESS_INTERNAL) {
                continue;
            }

            // a global project's copy in the gateway's region: its host there (docs/global-projects.md#gateways)
            if ($workload->isGlobal() && ! $route->forEnvironment() && (string) $workload->knative_region_id !== (string) $region->getKey()) {
                $workload = (clone $workload)->setRelation('knativeRegion', $region);
                $workload->setAttribute('knative_region_id', $region->getKey());
            }

            try {
                $publicHost = PublicHostRewrites::lookup($k8s, $namespace, $workload->slug, app(ProjectDomainService::class)->defaultHost($workload), $served);
            } catch (RuntimeException $e) {
                Log::warning('Gateway route left out', ['gateway' => $gateway->id, 'route' => $route->id, 'error' => $e->getMessage()]);

                continue;
            }
            $publicHosts[(string) $route->id] = $move ? $move->map($workload->slug, $publicHost) : $publicHost;
        }

        // key / JWT routes go through the metering proxy once a pod of it is ready; until then
        // their calls go on unmetered (GatewayAuthHealthJob syncs them when it is)
        $metered = $gateway->routes->contains(fn (GatewayRoute $route) => $route->requiresAuth())
            && app(GatewayAuthDeployer::class)->proxyReady($k8s, $namespace);

        return [
            'publicHosts' => $publicHosts,
            'metered' => $metered,
            'revision' => $this->routingRevision($gateway, $namespace, $publicHosts, $metered),
        ];
    }

    /** Label of the gateway's IngressRoute: the routing revision it was built with (routing()). */
    public const ROUTING_LABEL = 'runr.dev/routing-revision';

    /** Changes when a release changes how gateway routes are built: every gateway is rebuilt. */
    private const ROUTING_SCHEME = 'v2-metered';

    /** @param  array<string, string>  $publicHosts */
    private function routingRevision(Gateway $gateway, string $namespace, array $publicHosts, bool $metered): string
    {
        $objects = [
            'scheme' => self::ROUTING_SCHEME,
            'route' => $this->buildIngressRoute($gateway, $publicHosts, $metered)['spec'],
            'upstream' => $this->buildUpstreamIngressRoute($gateway, $publicHosts, $metered)['spec'] ?? null,
            'rate_limit' => $this->buildRateLimitMiddleware($gateway, $namespace)['spec'] ?? null,
            'custom_domain' => $this->customDomainHost($gateway),
            'metered' => $metered,
            'routes' => [],
        ];
        foreach ($gateway->routes as $route) {
            $objects['routes'][(string) $route->id] = [
                'strip' => $route->strip_prefix ? $this->buildStripPrefixMiddleware($route, $namespace)['spec'] : null,
                'host' => isset($publicHosts[(string) $route->id]) ? $this->buildHostMiddleware($route, $namespace, $publicHosts[(string) $route->id])['spec'] : null,
                'auth' => $route->requiresAuth(),
            ];
        }

        return substr(hash('sha256', (string) json_encode($objects, JSON_UNESCAPED_SLASHES)), 0, 20);
    }

    /**
     * The routing revision the gateway's IngressRoute carries in the region (null: none — not
     * there, or built by an earlier release).
     */
    public function appliedRoutingRevision(KubernetesClient $k8s, Gateway $gateway): ?string
    {
        $region = $gateway->knativeRegion;
        $route = $k8s->getResourceStatus('traefik.io/v1alpha1', 'IngressRoute', "gw-{$gateway->id}", $region->project_namespace ?? $region->name);

        return $route['metadata']['labels'][self::ROUTING_LABEL] ?? null;
    }

    /** Cache key: whether the gateway's last sync sent its key / JWT routes through the proxy. */
    public static function meteredCacheKey(string $gatewayId): string
    {
        return "gateway:metered:{$gatewayId}";
    }

    public function syncToCluster(Gateway $gateway, ?PublicHostMove $move = null): void
    {
        $region = $gateway->knativeRegion;

        if (! $region || ! $region->kubeconfig || $region->is_demo) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $namespace = $region->project_namespace ?? $region->name;

            $routing = $this->routing($k8s, $gateway, $move);
            $publicHosts = $routing['publicHosts'];
            $metered = $routing['metered'];

            // StripPrefix middlewares for each route, and its Host rewrite to the public host of
            // its workload (the project's, or the environment's it leads to)
            foreach ($gateway->routes as $route) {
                if ($route->strip_prefix) {
                    $middleware = $this->buildStripPrefixMiddleware($route, $namespace);
                    $k8s->applyResource($middleware, $namespace);
                }
                if (isset($publicHosts[(string) $route->id])) {
                    $k8s->applyResource($this->buildHostMiddleware($route, $namespace, $publicHosts[(string) $route->id]), $namespace);
                }
            }

            // Optional per-gateway rate limit (must exist before the IngressRoute references it)
            $rateLimit = $this->buildRateLimitMiddleware($gateway, $namespace);
            if ($rateLimit) {
                $k8s->applyResource($rateLimit, $namespace);
            }

            // API product: the region's gateway-auth and the forwardAuth Middlewares of key / JWT
            // routes, before the IngressRoute references them. Fail closed: while gateway-auth is
            // not installed or not ready, those Middlewares make the routes answer 503
            // auth_unavailable (applyAuth()); the gateway shows why.
            $auth = $this->applyAuth($k8s, $gateway, $region, $namespace, $metered);

            // Metered direct routes: their upstream header and the upstream IngressRoute on web
            $direct = $this->meteredDirectRoutes($gateway, $publicHosts, $metered);
            foreach ($direct as $route) {
                $k8s->applyResource($this->buildUpstreamMiddleware($route, $namespace), $namespace);
            }
            $upstream = $this->buildUpstreamIngressRoute($gateway, $publicHosts, $metered);
            if ($upstream) {
                $k8s->applyResource($this->buildUpstreamCleanMiddleware($gateway, $namespace), $namespace);
                $k8s->applyResource($upstream, $namespace);
            }

            // Apply IngressRoute
            $ingressRoute = $this->buildIngressRoute($gateway, $publicHosts, $metered);
            $ingressRoute['metadata']['labels'][self::ROUTING_LABEL] = $routing['revision'];
            if (! $k8s->applyResource($ingressRoute, $namespace) && $auth['state'] !== self::AUTH_NONE) {
                // the rules in the region may still serve a route that now asks for a key: they go
                $k8s->deleteResource('traefik.io/v1alpha1', 'IngressRoute', "gw-{$gateway->id}", $namespace);
                throw new RuntimeException(__('api_product.auth_service.ingress_failed'));
            }

            // The gateway's custom domain: its own IngressRoute (and certificate), or none
            $this->syncCustomDomain($k8s, $gateway, $namespace, $publicHosts, $metered);

            // Rate limit switched off: remove the Middleware once no route references it
            if (! $rateLimit) {
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->rateLimitMiddlewareName($gateway), $namespace);
            }

            // forwardAuth Middlewares of routes that are public again (or gone), and the upstream
            // objects no metered direct route uses any more
            $this->removeStaleAuthMiddlewares($k8s, $gateway, $namespace);
            $this->removeStaleUpstream($k8s, $gateway, $namespace, $direct, $upstream !== null);
            Cache::put(self::meteredCacheKey((string) $gateway->id), $metered, now()->addDay());

            $gateway->update([
                'status' => match ($auth['state']) {
                    self::AUTH_PENDING => 'pending',
                    self::AUTH_FAILED => 'error',
                    default => 'active',
                },
                'status_message' => $auth['message'],
            ]);

            Log::info('Gateway synced', [
                'gateway' => $gateway->id,
                'routes' => $gateway->routes->count(),
            ]);
        } catch (\Throwable $e) {
            $gateway->update(['status' => 'error', 'status_message' => mb_substr($e->getMessage(), 0, 1000)]);

            Log::error('Gateway sync failed', [
                'gateway' => $gateway->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The custom domain of the gateway (gateways.custom_domain): an IngressRoute with the gateway's
     * routes on that host, TLS as a project custom domain in cert-manager mode — a Certificate
     * from the region's issuer while its cert-manager is ready (CertManagerService::forDomains) —
     * else the region's default certificate. Without a custom domain they are removed.
     *
     * @param  array<string, string>  $publicHosts  route id → public host of its project (buildIngressRoute())
     */
    private function syncCustomDomain(KubernetesClient $k8s, Gateway $gateway, string $namespace, array $publicHosts, bool $metered = false): void
    {
        if ($this->customDomainHost($gateway) === null) {
            $this->deleteCustomDomain($k8s, $gateway, $namespace);

            return;
        }

        // the region's cert-manager (installed with Serving) and its issuer, as for a project's
        // custom domains (CertManagerService::forDomains)
        $secret = null;
        $certManager = app(CertManagerService::class)->forDomains($gateway->knativeRegion);
        $issuer = $certManager['available'] ? $certManager['issuer'] : null;
        // an operator's own issuer on a cert-manager RunR has not looked at: the cluster decides
        if ($issuer && ($certManager['status'] === CertManagerService::STATUS_READY || $k8s->hasApiVersion('cert-manager.io/v1'))) {
            $certificate = $this->buildCustomDomainCertificate($gateway, $namespace, $issuer);
            if ($certificate && $k8s->applyResource($certificate, $namespace)) {
                $secret = $this->customDomainRouteName($gateway);
            }
        }

        $route = $this->buildCustomDomainIngressRoute($gateway, $secret, $publicHosts, $metered);
        if ($route) {
            $k8s->applyResource($route, $namespace);
        }
    }

    private function deleteCustomDomain(KubernetesClient $k8s, Gateway $gateway, string $namespace): void
    {
        $name = $this->customDomainRouteName($gateway);
        $k8s->deleteResource('traefik.io/v1alpha1', 'IngressRoute', $name, $namespace);
        $k8s->deleteResource('cert-manager.io/v1', 'Certificate', $name, $namespace);
        $k8s->deleteResource('v1', 'Secret', $name, $namespace);
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

            // The custom domain's IngressRoute, Certificate and its Secret (404 = none)
            $this->deleteCustomDomain($k8s, $gateway, $namespace);

            // Delete middlewares
            // the metered direct routes' upstream IngressRoute and its Middleware
            $k8s->deleteResource('traefik.io/v1alpha1', 'IngressRoute', $this->upstreamRouteName($gateway), $namespace);
            $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->upstreamRouteName($gateway), $namespace);

            foreach ($gateway->routes as $route) {
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', "gw-strip-{$route->id}", $namespace);
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->hostMiddlewareName($route), $namespace);
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', GatewayAuthManifests::middlewareName($route), $namespace);
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->upstreamMiddlewareName($route), $namespace);
            }
            $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $this->rateLimitMiddlewareName($gateway), $namespace);
            Cache::forget(self::meteredCacheKey((string) $gateway->id));
        } catch (\Throwable $e) {
            Log::error('Gateway teardown failed', [
                'gateway' => $gateway->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The region's gateway-auth and one forwardAuth Middleware gw-auth-<route> per key / JWT route
     * (GatewayAuthManifests). Fail closed: when gateway-auth could not be installed, or no pod of it
     * is ready (it has no configuration from RunR yet, it cannot start), the Middlewares ask the
     * region's status responder instead and the routes answer 503 auth_unavailable — never their
     * function without a check. GatewayAuthHealthJob opens them once gateway-auth is ready (and
     * closes them again when it stops being ready).
     *
     * $metered: the routes' calls go through gateway-auth's metering proxy — the check hands each
     * allowed call a ticket for it.
     *
     * @return array{state: string, message: string|null} state: none | ready | pending | failed
     */
    private function applyAuth(KubernetesClient $k8s, Gateway $gateway, KnativeRegion $region, string $namespace, bool $metered = false): array
    {
        $authRoutes = $gateway->routes->filter(fn (GatewayRoute $route) => $route->requiresAuth());
        if ($authRoutes->isEmpty()) {
            return ['state' => self::AUTH_NONE, 'message' => null];
        }

        // the region's gateway-auth serves this organization (route changes move its revision:
        // ApiProductServiceProvider)
        app(GatewayAuthRevision::class)->register((string) $region->id);

        $deployer = app(GatewayAuthDeployer::class);
        $state = self::AUTH_READY;
        $message = null;
        try {
            $deployer->apply($k8s, $region, $namespace);
            $readiness = $deployer->readiness($k8s, $namespace);
            if (! $readiness['ready']) {
                // a fresh install takes seconds; one that never gets ready is an error
                $stuck = $readiness['age'] !== null && $readiness['age'] > self::AUTH_START_SECONDS;
                $state = $stuck ? self::AUTH_FAILED : self::AUTH_PENDING;
                $message = __($stuck ? 'api_product.auth_service.not_ready' : 'api_product.auth_service.starting');
            }
        } catch (\Throwable $e) {
            $state = self::AUTH_FAILED;
            $message = __('api_product.auth_service.install_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
            Log::warning('gateway-auth not installed: key / JWT routes refuse their calls', ['gateway' => $gateway->id, 'error' => $e->getMessage()]);
        }

        $responder = null;
        if ($state !== self::AUTH_READY) {
            try {
                $responder = app(RegionStatusService::class)->ensureAuthResponder($region, $k8s);
            } catch (\Throwable $e) {
                // without the responder the routes keep asking gateway-auth: Traefik answers 500
                Log::warning('Status responder not ensured for refused gateway routes', ['gateway' => $gateway->id, 'error' => $e->getMessage()]);
            }
        }

        foreach ($authRoutes as $route) {
            $manifest = $state === self::AUTH_READY || $responder === null
                ? GatewayAuthManifests::forwardAuthMiddleware($route, $namespace, $metered)
                : GatewayAuthManifests::refuseMiddleware($route, $namespace, $responder);
            if (! $k8s->applyResource($manifest, $namespace)) {
                // a route whose Middleware is missing is not served at all (Traefik: 404)
                $state = self::AUTH_FAILED;
                $message ??= __('api_product.auth_service.middleware_failed', ['path' => $route->path]);
            }
        }

        return ['state' => $state, 'message' => $message];
    }

    /**
     * The upstream objects of metered direct routes no rule references any more: a route's header
     * Middleware, and the gateway's upstream IngressRoute and its Middleware without such a route.
     *
     * @param  list<GatewayRoute>  $direct  the metered direct routes now
     */
    private function removeStaleUpstream(KubernetesClient $k8s, Gateway $gateway, string $namespace, array $direct, bool $hasUpstream): void
    {
        if (! $hasUpstream) {
            $k8s->deleteResource('traefik.io/v1alpha1', 'IngressRoute', $this->upstreamRouteName($gateway), $namespace);
        }

        $wanted = array_map(fn (GatewayRoute $route) => $this->upstreamMiddlewareName($route), $direct);
        if ($hasUpstream) {
            $wanted[] = $this->upstreamRouteName($gateway);
        }

        $existing = $k8s->listResources('traefik.io/v1alpha1', 'Middleware', $namespace, [
            'runr/gateway-id' => (string) $gateway->id,
            'runr/component' => 'gateway-upstream',
        ])['items'] ?? [];
        foreach ($existing as $item) {
            $name = (string) ($item['metadata']['name'] ?? '');
            if ($name !== '' && ! in_array($name, $wanted, true)) {
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $name, $namespace);
            }
        }
    }

    private function removeStaleAuthMiddlewares(KubernetesClient $k8s, Gateway $gateway, string $namespace): void
    {
        $wanted = $gateway->routes->filter(fn (GatewayRoute $route) => $route->requiresAuth())
            ->map(fn (GatewayRoute $route) => GatewayAuthManifests::middlewareName($route))
            ->values()->all();

        $existing = $k8s->listResources('traefik.io/v1alpha1', 'Middleware', $namespace, [
            'runr/gateway-id' => (string) $gateway->id,
            'runr/component' => 'gateway-auth',
        ])['items'] ?? [];

        foreach ($existing as $item) {
            $name = (string) ($item['metadata']['name'] ?? '');
            if ($name !== '' && ! in_array($name, $wanted, true)) {
                $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', $name, $namespace);
            }
        }
    }
}
