<?php

namespace Tests\Feature\ApiProduct;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Services\GatewayService;
use App\Services\KubernetesClient;
use App\Services\Manifests\GatewayAuthManifests;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\GatewayAuthStep;
use App\Services\RegionReconcile\Steps\GatewayRoutesStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\ApiProduct\Concerns\BuildsGateways;
use Tests\TestCase;

/**
 * region:reconcile and the gateways (docs/api-product.md#region-requirements-and-operation): the
 * gateway-auth Deployment is applied again when its spec changed, not only its image
 * (GatewayAuthStep), and every gateway whose IngressRoute does not carry the routing revision this
 * release builds — one of an earlier release — is rebuilt without waiting for a deploy
 * (GatewayRoutesStep).
 */
class GatewayReconcileStepsTest extends TestCase
{
    use ActsWithRoles, BuildsGateways, RefreshDatabase;

    private KnativeRegion $reg;

    private Gateway $gw;

    private GatewayRoute $rt;

    protected function setUp(): void
    {
        parent::setUp();
        config(['api_product.callback_url' => 'https://runr.example.com']);

        $this->reg = $this->region();
        $this->gw = $this->gateway($this->userWithRole('owner', ['username' => 'jane']), $this->reg);
        $this->rt = $this->route($this->gw, $this->project($this->reg));
        $this->fakeCluster();
    }

    private function cluster(): ClusterGroup
    {
        return new ClusterGroup('test', [new ReconcileTarget($this->reg->fresh(), 'central')], new KubernetesClient($this->reg->kubeconfig));
    }

    /** The gateway's IngressRoute in the region, with the routing revision $revision (null: none). */
    private function appliedIngressRoute(?string $revision): void
    {
        $labels = ['runr/gateway-id' => $this->gw->id] + ($revision !== null ? [GatewayService::ROUTING_LABEL => $revision] : []);
        $this->k8sResponses["GET /namespaces/fn/ingressroutes/gw-{$this->gw->id}"] = [200, ['metadata' => ['name' => "gw-{$this->gw->id}", 'labels' => $labels]]];
    }

    public function test_the_routes_step_runs_after_gateway_auth(): void
    {
        $steps = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        $this->assertContains('gateway-routes', $steps);
        $this->assertGreaterThan(array_search('gateway-auth', $steps, true), array_search('gateway-routes', $steps, true));
    }

    public function test_a_gateway_of_an_earlier_release_is_rebuilt(): void
    {
        $this->appliedIngressRoute(null);

        $this->assertSame(["would rebuild the routes of gateway jane ({$this->gw->id})"], (new GatewayRoutesStep)->run($this->cluster(), true));
        $this->assertNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"), 'a dry run writes nothing');

        $this->assertSame(["rebuilt the routes of gateway jane ({$this->gw->id})"], (new GatewayRoutesStep)->run($this->cluster(), false));

        $ingress = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}")['body'];
        $this->assertNotEmpty($ingress['metadata']['labels'][GatewayService::ROUTING_LABEL]);
        // the routes of this release: the Host rewrite per route
        $this->assertSame("gw-host-{$this->rt->id}", last($ingress['spec']['routes'][0]['middlewares'])['name']);
        $this->assertNotNull($this->k8sCall('PATCH', "/namespaces/fn/middlewares/gw-host-{$this->rt->id}"));
    }

    public function test_a_current_gateway_is_left_alone(): void
    {
        $revision = app(GatewayService::class)->routing(new KubernetesClient($this->reg->kubeconfig), $this->gw->fresh(['routes.project']))['revision'];
        $this->appliedIngressRoute($revision);

        $this->assertSame([], (new GatewayRoutesStep)->run($this->cluster(), false));
        $this->assertNull($this->k8sCall('PATCH', "/ingressroutes/gw-{$this->gw->id}"));
    }

    public function test_a_key_route_of_an_earlier_release_gets_its_check_and_the_metering_proxy(): void
    {
        $this->rt->update(['auth_mode' => 'key']);
        $this->gatewayAuthDeployment(true);
        $this->gatewayAuthProxy(true);
        $this->appliedIngressRoute('an-earlier-revision');

        (new GatewayRoutesStep)->run($this->cluster(), false);

        $rule = $this->k8sCall('PATCH', "/namespaces/fn/ingressroutes/gw-{$this->gw->id}")['body']['spec']['routes'][0];
        $this->assertSame("gw-auth-{$this->rt->id}", $rule['middlewares'][0]['name']);
        $this->assertSame(GatewayAuthManifests::PROXY_NAME, $rule['services'][0]['name']);
    }

    public function test_gateway_auth_is_applied_again_when_its_spec_changed_with_the_same_image(): void
    {
        $this->rt->update(['auth_mode' => 'key']);
        // a Deployment of an earlier release: the image of this one, no spec revision
        $this->k8sResponses['GET /namespaces/fn/deployments/runr-gateway-auth'] = [200, [
            'metadata' => ['name' => 'runr-gateway-auth', 'generation' => 1],
            'spec' => ['template' => ['spec' => ['containers' => [['image' => 'ghcr.io/brf-tech/runr/gateway-auth:1.0.0']]]]],
        ]];

        $lines = (new GatewayAuthStep)->run($this->cluster(), false);

        $this->assertSame(['gateway-auth in fn: Deployment, Services and metering proxy of this release'], $lines);
        $this->assertNotNull($this->k8sCall('PATCH', '/namespaces/fn/services/runr-gateway-auth-proxy'));
        $deployment = $this->k8sCall('PATCH', '/namespaces/fn/deployments/runr-gateway-auth')['body'];
        $revision = $deployment['metadata']['annotations'][GatewayAuthManifests::SPEC_ANNOTATION];

        // applied: the next run finds it current
        $this->calls = [];
        $this->k8sResponses['GET /namespaces/fn/deployments/runr-gateway-auth'][1]['metadata']['annotations'] = [GatewayAuthManifests::SPEC_ANNOTATION => $revision];
        $this->assertSame([], (new GatewayAuthStep)->run($this->cluster(), false));
        $this->assertNull($this->k8sCall('PATCH', '/namespaces/fn/deployments/runr-gateway-auth'));
    }
}
