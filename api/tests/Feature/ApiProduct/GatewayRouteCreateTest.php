<?php

namespace Tests\Feature\ApiProduct;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EmulatesConcurrentWrites;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * A route created with its API product settings (docs/api-product.md#routes): POST takes
 * auth_mode, jwt and gateway_only as PATCH does, and a key or JWT route reaches the region with its
 * forwardAuth Middleware in front of it from the first sync — never public for a moment. The
 * answers of POST and PATCH are the route as the gateway shows it (url, jwt); a path in use is a
 * 422, also when another request took it between the check and the write.
 */
class GatewayRouteCreateTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, EmulatesConcurrentWrites, RefreshDatabase;

    private User $owner;

    private Gateway $gw;

    private Project $orders;

    private Project $billing;

    protected function setUp(): void
    {
        parent::setUp();
        config(['api_product.callback_url' => 'https://runr.example.com']);

        $this->owner = $this->userWithRole('owner', ['username' => 'jane']);
        // its function domain: the gateway's host jane.fn.example.com
        $region = $this->region('fn', ['knative_settings' => ['knative_domain' => 'fn.example.com']]);
        $this->orders = $this->project($region);
        $this->billing = $this->project($region, 'billing');
        $this->gw = $this->gateway($this->owner, $region);
        $this->fakeCluster();
        $this->gatewayAuthDeployment(true);
    }

    private function addRoute(array $body): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson("/api/gateways/{$this->gw->id}/routes", $body);
    }

    /** The index of the first call of $method whose path ends with $suffix (null: none). */
    private function firstCall(string $method, string $suffix): ?int
    {
        foreach ($this->calls as $i => $call) {
            if ($call['method'] === $method && str_ends_with($call['path'], $suffix)) {
                return $i;
            }
        }

        return null;
    }

    public function test_a_key_route_reaches_the_region_behind_its_check_in_the_first_sync(): void
    {
        $id = $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'key'])
            ->assertCreated()
            ->assertJsonPath('data.auth_mode', 'key')
            ->json('data.id');

        $this->assertSame('key', GatewayRoute::query()->findOrFail($id)->auth_mode, 'stored with the route, not set after it');

        // the first IngressRoute the region gets already sends the route through gw-auth ...
        $ingress = $this->firstCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}");
        $this->assertNotNull($ingress, 'the route is synced');
        $this->assertSame("gw-auth-{$id}", $this->calls[$ingress]['body']['spec']['routes'][0]['middlewares'][0]['name']);
        // ... and that Middleware exists before it (Traefik never serves the route without it)
        $middleware = $this->firstCall('PATCH', "/namespaces/fn/middlewares/gw-auth-{$id}");
        $this->assertNotNull($middleware);
        $this->assertLessThan($ingress, $middleware);
        $this->assertStringContainsString("runr-gateway-auth.fn.svc.cluster.local/auth/{$id}", $this->calls[$middleware]['body']['spec']['forwardAuth']['address']);

        // one sync: no public IngressRoute at any point
        foreach ($this->calls as $call) {
            if ($call['method'] === 'PATCH' && str_ends_with($call['path'], "/ingressroutes/gw-{$this->gw->id}")) {
                $this->assertContains("gw-auth-{$id}", array_column($call['body']['spec']['routes'][0]['middlewares'], 'name'));
            }
        }
    }

    public function test_a_jwt_route_is_created_with_its_settings_and_validated_like_a_change(): void
    {
        $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'jwt'])
            ->assertStatus(422)->assertJsonValidationErrors('jwt.jwks_url');
        $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'jwt', 'jwt' => ['jwks_url' => 'not a url']])
            ->assertStatus(422)->assertJsonValidationErrors('jwt.jwks_url');
        $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'oauth'])
            ->assertStatus(422)->assertJsonValidationErrors('auth_mode');
        $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'jwt', 'jwt' => ['jwks_url' => 'https://id.example.com/jwks', 'subject_claim' => 'no spaces']])
            ->assertStatus(422)->assertJsonValidationErrors('jwt.subject_claim');
        $this->assertSame(0, GatewayRoute::query()->count(), 'a refused route is not created');
        $this->assertNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"));

        $route = $this->addRoute([
            'project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'jwt',
            'jwt' => ['jwks_url' => 'https://id.example.com/jwks', 'audience' => 'orders', 'require_consumer' => true],
        ])->assertCreated()
            ->assertJsonPath('data.jwt.jwks_url', 'https://id.example.com/jwks')
            ->assertJsonPath('data.jwt.audience', 'orders')
            ->assertJsonPath('data.jwt.subject_claim', 'sub')
            ->assertJsonPath('data.jwt.require_consumer', true)
            ->json('data');

        $ingress = $this->firstCall('PATCH', "/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame("gw-auth-{$route['id']}", $this->calls[$ingress]['body']['spec']['routes'][0]['middlewares'][0]['name']);
        $forward = $this->k8sCall('PATCH', "/middlewares/gw-auth-{$route['id']}")['body']['spec']['forwardAuth'];
        $this->assertContains('X-Runr-Subject', $forward['authResponseHeaders']);
    }

    public function test_a_route_created_while_gateway_auth_is_not_ready_refuses_its_calls(): void
    {
        $this->gatewayAuthDeployment(false);

        $id = $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'key'])->assertCreated()->json('data.id');

        $ingress = $this->firstCall('PATCH', "/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame("gw-auth-{$id}", $this->calls[$ingress]['body']['spec']['routes'][0]['middlewares'][0]['name']);
        $middleware = $this->firstCall('PATCH', "/middlewares/gw-auth-{$id}");
        $this->assertLessThan($ingress, $middleware);
        $this->assertStringEndsWith('/auth-unavailable', $this->calls[$middleware]['body']['spec']['forwardAuth']['address']);
        $this->assertSame('pending', $this->gw->fresh()->status);
    }

    public function test_a_route_without_settings_stays_public(): void
    {
        $id = $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders'])
            ->assertCreated()
            ->assertJsonPath('data.auth_mode', 'public')
            ->assertJsonPath('data.jwt', null)
            ->assertJsonPath('data.gateway_only', false)
            ->json('data.id');

        $ingress = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}");
        $this->assertNotContains("gw-auth-{$id}", array_column($ingress['body']['spec']['routes'][0]['middlewares'], 'name'));
        $this->assertNull($this->k8sCall('PATCH', "/middlewares/gw-auth-{$id}"));
    }

    public function test_gateway_only_comes_with_the_new_route(): void
    {
        $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'auth_mode' => 'key', 'gateway_only' => true])
            ->assertCreated()
            ->assertJsonPath('data.gateway_only', true);

        $patch = $this->k8sCall('PATCH', "/apis/serving.knative.dev/v1/namespaces/fn/services/{$this->orders->slug}");
        $this->assertSame('cluster-local', $patch['body']['metadata']['labels']['networking.knative.dev/visibility']);
    }

    public function test_gateway_only_is_refused_on_creation_while_the_project_has_custom_domains(): void
    {
        $this->billing->domains()->create(['domain' => 'billing.example.com', 'status' => 'active', 'verification_token' => 'x', 'tls_mode' => 'none']);

        $this->addRoute(['project_id' => $this->billing->id, 'path' => '/billing', 'gateway_only' => true])
            ->assertStatus(422)->assertJsonValidationErrors('gateway_only');
        $this->assertSame(0, GatewayRoute::query()->count());
    }

    public function test_the_answers_of_post_and_patch_are_the_route_as_the_gateway_shows_it(): void
    {
        $created = $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders', 'methods' => ['GET']])->assertCreated()->json('data');
        $this->assertSame('https://jane.fn.example.com/orders', $created['url']);

        $shown = $this->actingAs($this->owner, 'sanctum')->getJson("/api/gateways/{$this->gw->id}")->assertOk()->json('data.routes.0');
        $this->assertSame($shown, $created);
        $this->assertSame([$shown], $this->actingAs($this->owner, 'sanctum')->getJson("/api/gateways/{$this->gw->id}/routes")->json('data'));

        $changed = $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$created['id']}", [
            'path' => '/orders-v2', 'auth_mode' => 'jwt', 'jwt' => ['jwks_url' => 'https://id.example.com/jwks'],
        ])->assertOk()
            ->assertJsonPath('data.url', 'https://jane.fn.example.com/orders-v2')
            ->assertJsonPath('data.jwt.jwks_url', 'https://id.example.com/jwks')
            ->json('data');
        $this->assertSame($this->actingAs($this->owner, 'sanctum')->getJson("/api/gateways/{$this->gw->id}")->json('data.routes.0'), $changed);
    }

    public function test_a_path_in_use_is_refused_on_creation_and_change(): void
    {
        $orders = $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders'])->assertCreated()->json('data.id');
        $this->addRoute(['project_id' => $this->billing->id, 'path' => '/orders'])
            ->assertStatus(422)->assertJsonValidationErrors(['path' => 'The gateway already has a route on /orders.']);

        $billing = $this->addRoute(['project_id' => $this->billing->id, 'path' => '/billing'])->assertCreated()->json('data.id');
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$billing}", ['path' => '/orders'])
            ->assertStatus(422)->assertJsonValidationErrors(['path' => 'The gateway already has a route on /orders.']);
        $this->assertSame('/billing', GatewayRoute::query()->findOrFail($billing)->path);

        // its own path again is no conflict
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$orders}", ['path' => '/orders'])->assertOk();
    }

    public function test_a_path_another_request_took_after_the_check_is_a_422_not_a_500(): void
    {
        // another request writes /orders between the check and the insert (the last query before
        // it reads the gateway's highest sort order)
        $this->afterQuery('max("sort_order")', fn () => $this->insertRoute($this->orders, '/orders'));

        $this->addRoute(['project_id' => $this->billing->id, 'path' => '/orders'])
            ->assertStatus(422)->assertJsonValidationErrors(['path' => 'The gateway already has a route on /orders.']);
        $this->assertSame([$this->orders->id], GatewayRoute::query()->where('path', '/orders')->pluck('project_id')->all());
    }

    public function test_the_project_s_route_another_request_wrote_after_the_check_is_a_422(): void
    {
        // another request routes the project's own workload on another path in the meantime: the
        // partial unique index over the routes without an environment refuses the second one
        $this->afterQuery('max("sort_order")', fn () => $this->insertRoute($this->orders, '/orders-1'));

        $this->addRoute(['project_id' => $this->orders->id, 'path' => '/orders-2'])
            ->assertStatus(422)->assertJsonValidationErrors(['project_id' => 'The production environment of the project already has a gateway route']);
        $this->assertSame(['/orders-1'], GatewayRoute::query()->where('project_id', $this->orders->id)->pluck('path')->all());
    }

    public function test_a_path_change_racing_another_request_is_a_422_not_a_500(): void
    {
        $billing = $this->addRoute(['project_id' => $this->billing->id, 'path' => '/billing'])->assertCreated()->json('data.id');
        // another request takes /shop after the path check
        $this->afterQuery('"path" = ?', fn () => $this->insertRoute($this->orders, '/shop'));

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/gateways/{$this->gw->id}/routes/{$billing}", ['path' => '/shop'])
            ->assertStatus(422)->assertJsonValidationErrors(['path' => 'The gateway already has a route on /shop.']);
        $this->assertSame('/billing', GatewayRoute::query()->findOrFail($billing)->path);
    }

    /** A route written past the API, as a concurrent request would. */
    private function insertRoute(Project $project, string $path): void
    {
        DB::table('gateway_routes')->insert([
            'id' => (string) Str::ulid(), 'gateway_id' => $this->gw->id, 'project_id' => $project->id, 'path' => $path,
            'strip_prefix' => true, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
