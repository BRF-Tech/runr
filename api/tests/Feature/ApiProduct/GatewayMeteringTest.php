<?php

namespace Tests\Feature\ApiProduct;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Project;
use App\Models\User;
use App\Services\GatewayService;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * Metering of key / JWT routes (docs/api-product.md#usage): once gateway-auth's metering proxy takes
 * calls, a route's rule hands its calls to the proxy (Service runr-gateway-auth-proxy) after every
 * middleware, its check hands the proxy a ticket (?meter=1), and a direct route — access mode
 * allowlist, "only through the gateway" — names its wrr service in a header the gateway's
 * upstream IngressRoute on web matches. Until the proxy is ready, or while a pod of an earlier
 * image still answers the checks, the routes go on unmetered as before.
 */
class GatewayMeteringTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private User $owner;

    private Gateway $gw;

    private GatewayRoute $rt;

    private Project $proj;

    protected function setUp(): void
    {
        parent::setUp();
        config(['api_product.callback_url' => 'https://runr.example.com']);

        $this->owner = $this->userWithRole('owner', ['username' => 'jane']);
        $region = $this->region();
        $this->proj = $this->project($region);
        $this->gw = $this->gateway($this->owner, $region);
        $this->rt = $this->route($this->gw, $this->proj);
        $this->fakeCluster();
        $this->gatewayAuthDeployment(true);
    }

    private function patchRoute(array $body, ?GatewayRoute $route = null): TestResponse
    {
        $route ??= $this->rt;

        return $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$route->id}", $body);
    }

    private function ingressRoute(string $name): ?array
    {
        return $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/{$name}")['body'] ?? null;
    }

    public function test_gateway_auth_runs_its_metering_proxy(): void
    {
        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $deployment = $this->k8sCall('PATCH', '/namespaces/fn/deployments/runr-gateway-auth')['body'];
        $container = $deployment['spec']['template']['spec']['containers'][0];
        $env = collect($container['env'])->pluck('value', 'name');
        $this->assertSame('http://traefik.knative-serving.svc.cluster.local', $env['UPSTREAM_URL']);
        $this->assertSame(':8081', $env['PROXY_LISTEN']);
        $this->assertContains(['name' => 'proxy', 'containerPort' => 8081], $container['ports']);
        $this->assertNotEmpty($deployment['metadata']['annotations']['runr.sh/spec-revision'] ?? null);

        // ready pods only, and only pods with the proxy port
        $service = $this->k8sCall('PATCH', '/namespaces/fn/services/runr-gateway-auth-proxy')['body'];
        $this->assertSame('proxy', $service['spec']['ports'][0]['targetPort']);
        $this->assertArrayNotHasKey('publishNotReadyAddresses', $service['spec']);

        // a caller cannot send a ticket of its own: Traefik replaces it with the check's (or none)
        $forward = $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth'];
        $this->assertContains('X-Runr-Gateway-Ticket', $forward['authResponseHeaders']);
    }

    public function test_routes_go_on_unmetered_until_the_proxy_takes_calls(): void
    {
        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $rule = $this->ingressRoute("gw-{$this->gw->id}")['spec']['routes'][0];
        $this->assertSame([['name' => 'traefik', 'namespace' => 'knative-serving', 'port' => 80]], $rule['services']);
        $this->assertStringEndsWith("/auth/{$this->rt->id}", $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth']['address']);
        $this->assertFalse(Cache::get(GatewayService::meteredCacheKey($this->gw->id)));
    }

    public function test_a_key_route_hands_its_calls_to_the_metering_proxy(): void
    {
        $this->gatewayAuthProxy(true);
        $public = $this->route($this->gw, $this->project($this->gw->knativeRegion, 'docs'), '/docs');

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $rules = collect($this->ingressRoute("gw-{$this->gw->id}")['spec']['routes'])->keyBy(fn ($r) => str_contains($r['match'], '/orders') ? 'key' : 'public');
        // after the check, the prefix strip and the Host rewrite: the proxy hands the call on unchanged
        $this->assertSame([['name' => 'runr-gateway-auth-proxy', 'namespace' => 'fn', 'port' => 80]], $rules['key']['services']);
        $this->assertSame(["gw-auth-{$this->rt->id}", "gw-strip-{$this->rt->id}", "gw-host-{$this->rt->id}"], array_column($rules['key']['middlewares'], 'name'));
        $this->assertStringEndsWith("/auth/{$this->rt->id}?meter=1", $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth']['address']);
        // a public route has no consumer: it is not metered
        $this->assertSame('traefik', $rules['public']['services'][0]['name']);
        // no direct route: no upstream IngressRoute
        $this->assertNull($this->ingressRoute("gw-{$this->gw->id}-upstream"));
        $this->assertNotNull($this->k8sCall('DELETE', "/ingressroutes/gw-{$this->gw->id}-upstream"));
        $this->assertTrue(Cache::get(GatewayService::meteredCacheKey($this->gw->id)));
        $this->assertNotNull($public);
    }

    public function test_a_metered_gateway_only_route_reaches_its_service_through_the_upstream_route(): void
    {
        $this->gatewayAuthProxy(true);

        $this->patchRoute(['auth_mode' => 'key', 'gateway_only' => true])->assertOk();

        $rule = $this->ingressRoute("gw-{$this->gw->id}")['spec']['routes'][0];
        $this->assertSame('runr-gateway-auth-proxy', $rule['services'][0]['name']);
        $this->assertSame("gw-up-{$this->rt->id}", last($rule['middlewares'])['name']);

        $header = $this->k8sCall('PATCH', "/namespaces/fn/middlewares/gw-up-{$this->rt->id}")['body']['spec']['headers']['customRequestHeaders'];
        $token = $header['X-Runr-Gateway-Upstream'];
        $this->assertStringStartsWith($this->rt->id.'.', $token);
        $this->assertGreaterThan(30, strlen($token) - strlen($this->rt->id), 'a value nobody outside RunR knows');

        $upstream = $this->ingressRoute("gw-{$this->gw->id}-upstream");
        $this->assertSame(['web'], $upstream['spec']['entryPoints']);
        $upstreamRule = $upstream['spec']['routes'][0];
        $this->assertSame("Header(`X-Runr-Gateway-Upstream`, `{$token}`)", $upstreamRule['match']);
        $this->assertSame(200000, $upstreamRule['priority']);
        $this->assertSame([['name' => "fn-{$this->proj->slug}-rule-0-path-0-wrr@knative", 'kind' => 'TraefikService']], $upstreamRule['services']);
        $this->assertSame("gw-{$this->gw->id}-upstream", $upstreamRule['middlewares'][0]['name']);
        // the header goes before the function sees it
        $clean = $this->k8sCall('PATCH', "/namespaces/fn/middlewares/gw-{$this->gw->id}-upstream")['body'];
        $this->assertSame(['X-Runr-Gateway-Upstream' => ''], $clean['spec']['headers']['customRequestHeaders']);
    }

    public function test_an_allowlist_route_keeps_its_ip_check_on_the_first_hop_when_metered(): void
    {
        $this->proj->forceFill(['access_mode' => Project::ACCESS_ALLOWLIST, 'allowed_cidrs' => ['203.0.113.0/24']])->saveQuietly();
        $this->gatewayAuthProxy(true);

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $rule = $this->ingressRoute("gw-{$this->gw->id}")['spec']['routes'][0];
        $this->assertSame("runr-access-{$this->proj->slug}", $rule['middlewares'][0]['name'], 'the client address is checked first');
        $this->assertSame("gw-up-{$this->rt->id}", last($rule['middlewares'])['name']);
        $this->assertSame('runr-gateway-auth-proxy', $rule['services'][0]['name']);
        $this->assertNotNull($this->ingressRoute("gw-{$this->gw->id}-upstream"));
    }

    public function test_upstream_objects_go_when_the_route_is_public_again(): void
    {
        $this->gatewayAuthProxy(true);
        $this->rt->update(['auth_mode' => 'key', 'gateway_only' => true]);
        $this->k8sResponses['GET /namespaces/fn/middlewares'] = [200, ['items' => [
            ['metadata' => ['name' => "gw-up-{$this->rt->id}"]],
            ['metadata' => ['name' => "gw-{$this->gw->id}-upstream"]],
        ]]];

        $this->patchRoute(['auth_mode' => 'public'])->assertOk();

        $this->assertNotNull($this->k8sCall('DELETE', "/ingressroutes/gw-{$this->gw->id}-upstream"));
        $this->assertNotNull($this->k8sCall('DELETE', "/middlewares/gw-up-{$this->rt->id}"));
        $this->assertNotNull($this->k8sCall('DELETE', "/middlewares/gw-{$this->gw->id}-upstream"));
    }

    public function test_routes_stay_unmetered_while_a_pod_of_an_earlier_image_answers_checks(): void
    {
        $this->gatewayAuthProxy(true, oldPod: true);

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $this->assertSame('traefik', $this->ingressRoute("gw-{$this->gw->id}")['spec']['routes'][0]['services'][0]['name']);
    }

    public function test_several_methods_are_one_method_matcher_each(): void
    {
        $this->rt->update(['methods' => ['GET', 'post']]);

        app(GatewayService::class)->syncToCluster($this->gw->fresh(['routes.project']));

        $match = $this->ingressRoute("gw-{$this->gw->id}")['spec']['routes'][0]['match'];
        $this->assertStringEndsWith(' && (Method(`GET`) || Method(`POST`))', $match);

        $this->rt->update(['methods' => ['DELETE']]);
        app(GatewayService::class)->syncToCluster($this->gw->fresh(['routes.project']));
        $this->assertStringEndsWith(' && Method(`DELETE`)', $this->ingressRoute("gw-{$this->gw->id}")['spec']['routes'][0]['match']);
    }

    public function test_the_ingress_route_carries_its_routing_revision(): void
    {
        $service = app(GatewayService::class);
        $service->syncToCluster($this->gw->fresh(['routes.project']));
        $first = $this->ingressRoute("gw-{$this->gw->id}")['metadata']['labels'][GatewayService::ROUTING_LABEL] ?? null;
        $this->assertNotNull($first);

        // the same routing: the same revision
        $this->assertSame($first, $service->routing(new KubernetesClient($this->gw->knativeRegion->kubeconfig), $this->gw->fresh(['routes.project']))['revision']);

        // a key route that is metered now: another one
        $this->rt->update(['auth_mode' => 'key']);
        $this->gatewayAuthProxy(true);
        $this->assertNotSame($first, $service->routing(new KubernetesClient($this->gw->knativeRegion->kubeconfig), $this->gw->fresh(['routes.project']))['revision']);
    }
}
