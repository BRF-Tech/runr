<?php

namespace Tests\Unit\ApiProduct;

use App\Models\ApiProduct\ApiUsageHour;
use App\Models\GatewayRoute;
use App\Services\ApiProduct\DeveloperPage;
use App\Services\Manifests\GatewayAuthManifests;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The region objects of the API product (GatewayAuthManifests) and the small pure helpers.
 */
class GatewayAuthManifestsTest extends TestCase
{
    private function route(string $mode): GatewayRoute
    {
        $route = new GatewayRoute;
        $route->forceFill(['id' => '01JROUTEAAAAAAAAAAAAAAAAAA', 'gateway_id' => '01JGATEWAYAAAAAAAAAAAAAAA', 'auth_mode' => $mode]);

        return $route;
    }

    public function test_key_routes_strip_the_key_from_the_upstream_request(): void
    {
        $spec = GatewayAuthManifests::forwardAuthMiddleware($this->route('key'), 'fn')['spec']['forwardAuth'];

        $this->assertSame('http://runr-gateway-auth.fn.svc.cluster.local/auth/01JROUTEAAAAAAAAAAAAAAAAAA', $spec['address']);
        // the ticket header too: a caller's own never reaches the metering proxy
        $this->assertSame(['X-Runr-Consumer', 'X-Runr-Consumer-External', 'X-Runr-Key', 'X-Runr-Subject', 'X-Runr-Gateway-Ticket', 'X-Api-Key', 'Authorization'], $spec['authResponseHeaders']);
    }

    public function test_a_metered_route_asks_for_a_ticket(): void
    {
        $spec = GatewayAuthManifests::forwardAuthMiddleware($this->route('key'), 'fn', true)['spec']['forwardAuth'];

        $this->assertSame('http://runr-gateway-auth.fn.svc.cluster.local/auth/01JROUTEAAAAAAAAAAAAAAAAAA?meter=1', $spec['address']);
    }

    public function test_the_metering_proxy_reaches_ready_pods_with_its_port_only(): void
    {
        $service = GatewayAuthManifests::proxyService('fn');
        $this->assertSame('runr-gateway-auth-proxy', $service['metadata']['name']);
        $this->assertSame([['name' => 'http', 'port' => 80, 'targetPort' => 'proxy']], $service['spec']['ports']);
        $this->assertArrayNotHasKey('publishNotReadyAddresses', $service['spec']);

        $container = GatewayAuthManifests::deployment('fn', 'img', 1, 'rev', 10, 30, GatewayAuthManifests::upstreamUrl('knative-serving'))['spec']['template']['spec']['containers'][0];
        $this->assertContains(['name' => 'UPSTREAM_URL', 'value' => 'http://traefik.knative-serving.svc.cluster.local'], $container['env']);
        $this->assertContains(['name' => 'proxy', 'containerPort' => 8081], $container['ports']);
    }

    public function test_jwt_routes_keep_the_token_for_the_function(): void
    {
        $spec = GatewayAuthManifests::forwardAuthMiddleware($this->route('jwt'), 'fn')['spec']['forwardAuth'];

        $this->assertSame(GatewayAuthManifests::UPSTREAM_HEADERS, $spec['authResponseHeaders']);
    }

    public function test_a_refusing_route_asks_the_responder_under_the_same_name(): void
    {
        $route = $this->route('key');
        $open = GatewayAuthManifests::forwardAuthMiddleware($route, 'fn');
        $refuse = GatewayAuthManifests::refuseMiddleware($route, 'fn', 'http://runr-status-responder.knative-serving.svc.cluster.local:8080/auth-unavailable');

        $this->assertSame($open['metadata']['name'], $refuse['metadata']['name'], 'the IngressRoute does not change between the states');
        $this->assertSame('http://runr-status-responder.knative-serving.svc.cluster.local:8080/auth-unavailable', $refuse['spec']['forwardAuth']['address']);
        $this->assertNotContains('Authorization', $refuse['spec']['forwardAuth']['authRequestHeaders'], 'no key or token to the responder');
        $this->assertNotSame([], $refuse['spec']['forwardAuth']['authRequestHeaders'], 'an empty list would send every header');
        $this->assertSame('gateway-auth', $refuse['metadata']['labels']['runr/component'], 'found and removed like the open one');
    }

    public function test_starting_pods_answer_themselves(): void
    {
        $this->assertTrue(GatewayAuthManifests::service('fn')['spec']['publishNotReadyAddresses']);
    }

    public function test_the_deployment_runs_hardened_and_restarts_with_its_secret(): void
    {
        $deployment = GatewayAuthManifests::deployment('fn', 'registry.test/gateway-auth:1.0.0', 2, 'rev-1', 10, 30);
        $pod = $deployment['spec']['template'];
        $container = $pod['spec']['containers'][0];

        $this->assertSame('rev-1', $pod['metadata']['annotations']['runr.sh/config-revision']);
        $this->assertFalse($pod['spec']['automountServiceAccountToken']);
        $this->assertTrue($pod['spec']['securityContext']['runAsNonRoot']);
        $this->assertTrue($container['securityContext']['readOnlyRootFilesystem']);
        $this->assertSame(['ALL'], $container['securityContext']['capabilities']['drop']);
        $this->assertSame([['secretRef' => ['name' => 'runr-gateway-auth']]], $container['envFrom']);
        $this->assertSame('/readyz', $container['readinessProbe']['httpGet']['path']);
        // the metering proxy streams the routes' calls through the pod
        $this->assertSame(['cpu' => '1', 'memory' => '256Mi'], $container['resources']['limits']);
        $this->assertNotEmpty($deployment['metadata']['annotations'][GatewayAuthManifests::SPEC_ANNOTATION]);
        $this->assertContains(['name' => 'SYNC_SECONDS', 'value' => '10'], $container['env']);
    }

    public function test_usage_buckets_are_unique_per_hour_and_dimension(): void
    {
        $hour = Carbon::parse('2026-10-21 10:00:00', 'UTC');
        $a = ApiUsageHour::bucket($hour, 'gw', 'r', 'c', 'k', null);

        $this->assertSame('2026102110|gw|r|c|k|-', $a);
        $this->assertNotSame($a, ApiUsageHour::bucket($hour->copy()->addHour(), 'gw', 'r', 'c', 'k', null));
        $this->assertNotSame(ApiUsageHour::bucket($hour, 'gw', 'r', null, null, 'alice'), ApiUsageHour::bucket($hour, 'gw', 'r', null, null, 'bob'));
        $this->assertLessThanOrEqual(191, strlen(ApiUsageHour::bucket($hour, str_repeat('G', 26), str_repeat('R', 26), str_repeat('C', 26), str_repeat('K', 26), str_repeat('s', 191))));
    }

    public function test_developer_page_keys(): void
    {
        $this->assertSame([null, '01JGATEWAYAAAAAAAAAAAAAAAA'], DeveloperPage::parseKey('_.01JGATEWAYAAAAAAAAAAAAAAAA'));
        $this->assertSame(['acme', '01JGATEWAYAAAAAAAAAAAAAAAA'], DeveloperPage::parseKey('acme.01JGATEWAYAAAAAAAAAAAAAAAA'));
        $this->assertNull(DeveloperPage::parseKey('acme'));
        $this->assertNull(DeveloperPage::parseKey('../etc.01JGATEWAYAAAAAAAAAAAAAAAA'));
    }
}
