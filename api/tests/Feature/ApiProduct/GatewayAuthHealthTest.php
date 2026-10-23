<?php

namespace Tests\Feature\ApiProduct;

use App\Jobs\ApiProduct\GatewayAuthHealthJob;
use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Services\ApiProduct\GatewayAuthDeployer;
use App\Services\GatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * GatewayAuthHealthJob (docs/api-product.md#when-gateway-auth-is-not-ready): refusing key / JWT
 * routes open once gateway-auth is ready; open ones refuse again when it is not.
 */
class GatewayAuthHealthTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private Gateway $gw;

    private GatewayRoute $rt;

    protected function setUp(): void
    {
        parent::setUp();
        config(['api_product.callback_url' => 'https://runr.example.com']);

        $owner = $this->userWithRole('owner');
        $region = $this->region();
        $this->gw = $this->gateway($owner, $region);
        $this->rt = $this->route($this->gw, $this->project($region), '/orders', ['auth_mode' => 'key']);
        GatewayAuthScope::query()->create(['knative_region_id' => $region->id, 'scope' => '_', 'revision' => 1]);
        $this->fakeCluster();
    }

    private function runJob(): void
    {
        (new GatewayAuthHealthJob)->handle(app(GatewayService::class), app(GatewayAuthDeployer::class));
    }

    private function middlewareAddress(): ?string
    {
        return $this->k8sCall('PATCH', "/middlewares/gw-auth-{$this->rt->id}")['body']['spec']['forwardAuth']['address'] ?? null;
    }

    public function test_refusing_routes_open_once_gateway_auth_is_ready(): void
    {
        $this->gw->update(['status' => 'pending', 'status_message' => 'starting']);
        $this->gatewayAuthDeployment(true);

        $this->runJob();

        $this->assertStringContainsString('runr-gateway-auth.fn.svc', (string) $this->middlewareAddress());
        $this->assertSame('active', $this->gw->fresh()->status);
        $this->assertNull($this->gw->fresh()->status_message);
    }

    public function test_open_routes_refuse_when_gateway_auth_stops_being_ready(): void
    {
        $this->gw->update(['status' => 'active']);
        $this->gatewayAuthDeployment(false, 7200);

        $this->runJob();

        $this->assertStringEndsWith('/auth-unavailable', (string) $this->middlewareAddress());
        $this->assertSame('error', $this->gw->fresh()->status);
    }

    public function test_a_steady_gateway_is_left_alone(): void
    {
        $this->gw->update(['status' => 'active']);
        $this->gatewayAuthDeployment(true);
        $this->gatewayAuthProxy(true);
        Cache::put(GatewayService::meteredCacheKey($this->gw->id), true);

        $this->runJob();

        $this->assertNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"), 'no sync while nothing changed');
        $this->assertCount(3, $this->calls, 'one Deployment and two EndpointSlice reads for the region');
    }

    public function test_routes_go_through_the_metering_proxy_once_it_takes_calls(): void
    {
        $this->gw->update(['status' => 'active']);
        $this->gatewayAuthDeployment(true);
        // the last sync was before the proxy was there (an upgrade's rollout)
        Cache::put(GatewayService::meteredCacheKey($this->gw->id), false);
        $this->gatewayAuthProxy(true);

        $this->runJob();

        $ingress = $this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}");
        $this->assertSame([['name' => 'runr-gateway-auth-proxy', 'namespace' => 'fn', 'port' => 80]], $ingress['body']['spec']['routes'][0]['services']);
        $this->assertStringEndsWith("/auth/{$this->rt->id}?meter=1", (string) $this->middlewareAddress());
        $this->assertTrue(Cache::get(GatewayService::meteredCacheKey($this->gw->id)));
    }

    public function test_routes_stay_unmetered_while_a_pod_of_an_earlier_image_answers_checks(): void
    {
        $this->gw->update(['status' => 'active']);
        $this->gatewayAuthDeployment(true);
        Cache::put(GatewayService::meteredCacheKey($this->gw->id), false);
        // the new pod's proxy is ready, an old pod (no ticket for the proxy) still answers checks
        $this->gatewayAuthProxy(true, oldPod: true);

        $this->runJob();

        $this->assertNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"), 'nothing to sync while the rollout runs');
    }

    public function test_gateways_without_key_or_jwt_routes_are_not_checked(): void
    {
        $this->rt->update(['auth_mode' => 'public']);

        $this->runJob();

        $this->assertSame([], $this->calls);
    }
}
