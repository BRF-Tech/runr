<?php

namespace Tests\Feature\ApiProduct;

use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Project;
use App\Models\User;
use App\Services\ApiProduct\GatewayAuthToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * Authentication per gateway route (docs/api-product.md#routes): key / JWT routes get a Traefik
 * forwardAuth Middleware to the region's gateway-auth (installed with them) ahead of the prefix
 * strip; back to public the Middleware goes; "only through the gateway" makes the project's
 * Knative Service cluster-local. Fail closed: while gateway-auth is not installed or not ready the
 * route's Middleware asks the status responder (503 auth_unavailable), never the function.
 */
class GatewayRouteAuthTest extends TestCase
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
        // a ready gateway-auth unless a test says otherwise (the fail-closed tests below)
        $this->gatewayAuthDeployment(true);
    }

    private function patchRoute(array $body): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$this->rt->id}", $body);
    }

    public function test_key_mode_installs_gateway_auth_and_asks_it_before_the_prefix_is_stripped(): void
    {
        $this->patchRoute(['auth_mode' => 'key'])->assertOk()->assertJsonPath('data.auth_mode', 'key');

        $secret = $this->k8sCall('PATCH', '/namespaces/fn/secrets/runr-gateway-auth');
        $this->assertNotNull($secret, 'the gateway-auth Secret is applied');
        $this->assertSame('https://runr.example.com', base64_decode($secret['body']['data']['RUNR_API_URL']));
        $this->assertSame(app(GatewayAuthToken::class)->token($this->gw->knative_region_id), base64_decode($secret['body']['data']['RUNR_TOKEN']));
        $this->assertArrayNotHasKey('REDIS_URL', $secret['body']['data'], 'no Redis in the region');

        $deployment = $this->k8sCall('PATCH', '/namespaces/fn/deployments/runr-gateway-auth');
        $this->assertSame(1, $deployment['body']['spec']['replicas'], 'one pod with in-memory counters');
        $this->assertSame('ghcr.io/brf-tech/runr/gateway-auth:1.0.0', $deployment['body']['spec']['template']['spec']['containers'][0]['image']);
        $this->assertNotNull($this->k8sCall('PATCH', '/namespaces/fn/services/runr-gateway-auth'));

        $middleware = $this->k8sCall('PATCH', "/namespaces/fn/middlewares/gw-auth-{$this->rt->id}");
        $this->assertNotNull($middleware);
        $forward = $middleware['body']['spec']['forwardAuth'];
        $this->assertSame("http://runr-gateway-auth.fn.svc.cluster.local/auth/{$this->rt->id}", $forward['address']);
        $this->assertFalse($forward['trustForwardHeader']);
        $this->assertSame(['Authorization', 'X-Api-Key'], $forward['authRequestHeaders']);
        // the key never reaches the function; a caller cannot forge the consumer
        foreach (['X-Runr-Consumer', 'X-Api-Key', 'Authorization'] as $header) {
            $this->assertContains($header, $forward['authResponseHeaders']);
        }

        $ingress = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame([
            ['name' => "gw-auth-{$this->rt->id}", 'namespace' => 'fn'],
            ['name' => "gw-strip-{$this->rt->id}", 'namespace' => 'fn'],
            // then the Host rewrite to the project's public host
            ['name' => "gw-host-{$this->rt->id}", 'namespace' => 'fn'],
        ], $ingress['body']['spec']['routes'][0]['middlewares']);

        // the region's gateway-auth serves this organization now
        $scope = GatewayAuthScope::query()->where('knative_region_id', $this->gw->knative_region_id)->first();
        $this->assertSame('_', $scope?->scope);
    }

    public function test_the_ip_rate_limit_stays_in_front_of_the_auth_check(): void
    {
        $this->gw->update(['rate_limit_average' => 10]);
        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $ingress = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame(
            ["gw-ratelimit-{$this->gw->id}", "gw-auth-{$this->rt->id}", "gw-strip-{$this->rt->id}", "gw-host-{$this->rt->id}"],
            array_column($ingress['body']['spec']['routes'][0]['middlewares'], 'name'),
        );
    }

    public function test_a_region_with_redis_runs_more_pods_sharing_its_counters(): void
    {
        $this->gw->knativeRegion->forceFill([
            'redis_mode' => 'managed', 'knative_redis_status' => 'ready',
            'redis_address' => 'redis.runr-redis:6379', 'redis_password' => 's3cr3t/+',
        ])->save();
        config(['api_product.replicas' => 2]);

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $secret = $this->k8sCall('PATCH', '/namespaces/fn/secrets/runr-gateway-auth');
        $this->assertSame('redis://:s3cr3t%2F%2B@redis.runr-redis:6379', base64_decode($secret['body']['data']['REDIS_URL']));
        $this->assertSame(2, $this->k8sCall('PATCH', '/namespaces/fn/deployments/runr-gateway-auth')['body']['spec']['replicas']);
    }

    public function test_jwt_mode_needs_a_jwks_url_and_keeps_the_token_for_the_function(): void
    {
        $this->patchRoute(['auth_mode' => 'jwt'])->assertStatus(422)->assertJsonValidationErrors('jwt.jwks_url');
        $this->patchRoute(['auth_mode' => 'jwt', 'jwt' => ['jwks_url' => 'not a url']])->assertStatus(422)->assertJsonValidationErrors('jwt.jwks_url');

        $this->patchRoute(['auth_mode' => 'jwt', 'jwt' => ['jwks_url' => 'https://id.example.com/.well-known/jwks.json', 'issuer' => 'https://id.example.com', 'audience' => 'orders']])
            ->assertOk();

        $route = $this->rt->fresh();
        $this->assertSame('jwt', $route->auth_mode);
        $this->assertSame('https://id.example.com/.well-known/jwks.json', $route->jwt_config['jwks_url']);

        $forward = $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth'];
        $this->assertNotContains('Authorization', $forward['authResponseHeaders'], 'the function may read the token');
        $this->assertContains('X-Runr-Subject', $forward['authResponseHeaders']);

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/gateways/{$this->gw->id}")
            ->assertJsonPath('data.routes.0.auth_mode', 'jwt')
            ->assertJsonPath('data.routes.0.jwt.subject_claim', 'sub')
            ->assertJsonPath('data.routes.0.jwt.audience', 'orders');
    }

    public function test_back_to_public_removes_the_routes_forward_auth(): void
    {
        $this->rt->update(['auth_mode' => 'key']);
        $this->k8sResponses['GET /namespaces/fn/middlewares'] = [200, ['items' => [
            ['metadata' => ['name' => "gw-auth-{$this->rt->id}"]],
        ]]];

        $this->patchRoute(['auth_mode' => 'public'])->assertOk();

        $this->assertNotNull($this->k8sCall('DELETE', "/middlewares/gw-auth-{$this->rt->id}"));
        $ingress = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame([['name' => "gw-strip-{$this->rt->id}", 'namespace' => 'fn'], ['name' => "gw-host-{$this->rt->id}", 'namespace' => 'fn']], $ingress['body']['spec']['routes'][0]['middlewares']);
    }

    public function test_gateway_only_makes_the_knative_service_cluster_local_and_back(): void
    {
        $this->patchRoute(['gateway_only' => true])->assertOk();

        $patch = $this->k8sCall('PATCH', "/apis/serving.knative.dev/v1/namespaces/fn/services/{$this->proj->slug}");
        $this->assertSame('cluster-local', $patch['body']['metadata']['labels']['networking.knative.dev/visibility']);
        $this->assertTrue($this->rt->fresh()->gateway_only);

        $this->patchRoute(['gateway_only' => false])->assertOk();
        $patch = $this->k8sCall('PATCH', "/apis/serving.knative.dev/v1/namespaces/fn/services/{$this->proj->slug}");
        $this->assertNull($patch['body']['metadata']['labels']['networking.knative.dev/visibility']);
    }

    public function test_gateway_only_is_refused_while_the_project_has_custom_domains(): void
    {
        $this->proj->domains()->create(['domain' => 'orders.example.com', 'status' => 'active', 'verification_token' => 'x', 'tls_mode' => 'none']);

        $this->patchRoute(['gateway_only' => true])->assertStatus(422)->assertJsonValidationErrors('gateway_only');
        $this->assertFalse((bool) $this->rt->fresh()->gateway_only);
    }

    public function test_removing_a_gateway_only_route_opens_the_project_url_again(): void
    {
        $this->rt->update(['gateway_only' => true]);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/gateways/{$this->gw->id}/routes/{$this->rt->id}")->assertNoContent();

        $patch = $this->k8sCall('PATCH', "/apis/serving.knative.dev/v1/namespaces/fn/services/{$this->proj->slug}");
        $this->assertNull($patch['body']['metadata']['labels']['networking.knative.dev/visibility']);
    }

    public function test_only_who_may_route_the_project_changes_its_authentication(): void
    {
        $other = $this->userWithRole('developer');

        $this->actingAs($other, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$this->rt->id}", ['auth_mode' => 'key'])->assertForbidden();
        $this->assertSame('public', $this->rt->fresh()->auth_mode ?? 'public');
    }

    public function test_a_ready_gateway_auth_makes_the_gateway_active(): void
    {
        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $forward = $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth'];
        $this->assertStringContainsString('runr-gateway-auth.fn.svc', $forward['address']);
        $this->assertSame('active', $this->gw->fresh()->status);
        $this->assertNull($this->gw->fresh()->status_message);
        $this->assertNull($this->k8sCall('PATCH', '/deployments/runr-status-responder'), 'no responder needed');
    }

    /**
     * Fail closed: a route switched to key while gateway-auth has no ready pod yet refuses its calls
     * (forwardAuth to the status responder: 503 auth_unavailable) — it never stays public.
     */
    public function test_a_route_refuses_its_calls_until_gateway_auth_is_ready(): void
    {
        $this->gatewayAuthDeployment(false);

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $middleware = $this->k8sCall('PATCH', "/namespaces/fn/middlewares/gw-auth-{$this->rt->id}")['body'];
        $this->assertSame('http://runr-status-responder.knative-serving.svc.cluster.local:8080/auth-unavailable', $middleware['spec']['forwardAuth']['address']);
        $this->assertSame(['X-Forwarded-Uri'], $middleware['spec']['forwardAuth']['authRequestHeaders'], 'the caller\'s key never reaches the responder');
        $this->assertSame('refusing', $middleware['metadata']['labels']['runr/gateway-auth-state']);

        // the responder that answers 503, in the serving namespace (outside the project quota)
        $configMap = $this->k8sCall('PATCH', '/namespaces/knative-serving/configmaps/runr-status-responder');
        $this->assertStringContainsString('auth_unavailable', $configMap['body']['data']['server.js']);
        $this->assertNotNull($this->k8sCall('PATCH', '/namespaces/knative-serving/deployments/runr-status-responder'));

        // the IngressRoute is the new one, the route behind gw-auth: nothing reaches the function unchecked
        $ingress = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame("gw-auth-{$this->rt->id}", $ingress['body']['spec']['routes'][0]['middlewares'][0]['name']);

        $gateway = $this->gw->fresh();
        $this->assertSame('pending', $gateway->status);
        $this->assertStringContainsString('503 auth_unavailable', $gateway->status_message);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/gateways/{$this->gw->id}")
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_message', $gateway->status_message);
    }

    public function test_a_gateway_auth_that_never_gets_ready_shows_an_error(): void
    {
        $this->gatewayAuthDeployment(false, 900);

        $this->patchRoute(['auth_mode' => 'jwt', 'jwt' => ['jwks_url' => 'https://id.example.com/jwks']])->assertOk();

        $this->assertStringEndsWith('/auth-unavailable', $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth']['address']);
        $this->assertSame('error', $this->gw->fresh()->status);
        $this->assertStringContainsString('not ready', $this->gw->fresh()->status_message);
    }

    public function test_a_failed_install_refuses_instead_of_leaving_the_route_public(): void
    {
        $this->k8sResponses['PATCH /namespaces/fn/deployments/runr-gateway-auth'] = [500, ['message' => 'exceeded quota: runr-quota']];

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $this->assertStringEndsWith('/auth-unavailable', $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth']['address']);
        $ingress = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}");
        $this->assertNotNull($ingress, 'the previous (public) IngressRoute is replaced');
        $this->assertSame("gw-auth-{$this->rt->id}", $ingress['body']['spec']['routes'][0]['middlewares'][0]['name']);

        $gateway = $this->gw->fresh();
        $this->assertSame('error', $gateway->status);
        $this->assertStringContainsString('could not be installed', $gateway->status_message);
    }

    public function test_a_new_key_route_on_a_gateway_whose_auth_is_down_refuses(): void
    {
        // the gateway already has a key route and its gateway-auth went down
        $this->rt->update(['auth_mode' => 'key']);
        $this->gatewayAuthDeployment(false, 3600);
        $billing = $this->route($this->gw, $this->project($this->gw->knativeRegion, 'billing'), '/billing');

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$billing->id}", ['auth_mode' => 'key'])->assertOk();

        foreach ([$this->rt->id, $billing->id] as $id) {
            $this->assertStringEndsWith('/auth-unavailable', $this->k8sCall('PATCH', "/middlewares/gw-auth-{$id}")['body']['spec']['forwardAuth']['address'], $id);
        }
        $this->assertSame('error', $this->gw->fresh()->status);
    }

    public function test_when_the_ingress_route_cannot_be_applied_the_previous_one_is_removed(): void
    {
        $this->k8sResponses["PATCH /namespaces/fn/ingressroutes/gw-{$this->gw->id}"] = [500, ['message' => 'admission webhook denied']];

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        $this->assertNotNull($this->k8sCall('DELETE', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}"), 'no previous public rules stay');
        $gateway = $this->gw->fresh();
        $this->assertSame('error', $gateway->status);
        $this->assertStringContainsString('removed there', $gateway->status_message);
    }

    public function test_a_middleware_that_cannot_be_applied_leaves_the_route_unserved_and_shows_it(): void
    {
        $this->k8sResponses["PATCH /namespaces/fn/middlewares/gw-auth-{$this->rt->id}"] = [500, ['message' => 'etcd timeout']];

        $this->patchRoute(['auth_mode' => 'key'])->assertOk();

        // the IngressRoute references gw-auth-<route>: without it Traefik does not serve the route
        $ingress = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame("gw-auth-{$this->rt->id}", $ingress['body']['spec']['routes'][0]['middlewares'][0]['name']);
        $this->assertSame('error', $this->gw->fresh()->status);
        $this->assertStringContainsString('/orders', $this->gw->fresh()->status_message);
    }
}
