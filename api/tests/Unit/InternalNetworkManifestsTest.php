<?php

namespace Tests\Unit;

use App\Services\Helpers\PrivateIngress;
use App\Services\InternalNetwork\InternalNetworkManifests as M;
use App\Services\KnativeInstallService;
use PHPUnit\Framework\TestCase;

/**
 * The objects of the internal network (docs/internal-network.md): a project's policy metadata,
 * its runr.internal route and the allowlist route of its public host; the authz and the
 * entry-point Middlewares of a Serving namespace; CoreDNS's runr.internal rewrite.
 */
class InternalNetworkManifestsTest extends TestCase
{
    private const COREFILE = <<<'COREFILE'
.:53 {
    errors
    health {
       lameduck 5s
    }
    ready
    kubernetes cluster.local in-addr.arpa ip6.arpa {
       pods insecure
       fallthrough in-addr.arpa ip6.arpa
       ttl 30
    }
    prometheus :9153
    forward . /etc/resolv.conf
    cache 30
    loop
    reload
    loadbalance
}

COREFILE;

    public function test_the_policy_of_a_project_is_metadata_of_its_knative_service(): void
    {
        $meta = M::projectMetadata('orders.acme', ['shop.acme', 'billing.globex'], 'allowlist');

        $this->assertSame(['runr.dev/internal-network' => 'managed'], $meta['labels']);
        $this->assertSame([
            'runr.dev/internal-name' => 'orders.acme',
            'runr.dev/internal-org' => 'acme',
            'runr.dev/internal-callers' => 'shop.acme,billing.globex',
            'runr.dev/internal-kind' => 'project',
            'runr.dev/access-mode' => 'allowlist',
        ], $meta['annotations']);

        $model = M::modelMetadata('m-embed.acme', ['chat.acme']);
        $this->assertSame('model', $model['annotations']['runr.dev/internal-kind']);
        $this->assertSame('chat.acme', $model['annotations']['runr.dev/internal-callers']);
        $this->assertArrayNotHasKey('runr.dev/access-mode', $model['annotations']);
    }

    public function test_the_internal_host_routes_on_the_private_entry_point_to_the_knative_route(): void
    {
        $route = M::internalRoute('fn', 'orders-acme', 'orders.acme', ['runr/project-id' => 'p1']);

        $this->assertSame('runr-internal-orders-acme', $route['metadata']['name']);
        $this->assertSame('p1', $route['metadata']['labels']['runr/project-id']);
        $this->assertSame([PrivateIngress::ENTRYPOINT], $route['spec']['entryPoints'], 'never on web / websecure');
        $this->assertSame('Host(`orders.acme.runr.internal`)', $route['spec']['routes'][0]['match']);
        $this->assertSame([['name' => 'fn-orders-acme-rule-0-path-0-wrr@knative', 'kind' => 'TraefikService']], $route['spec']['routes'][0]['services']);
    }

    public function test_access_mode_allowlist_puts_an_ip_allow_list_above_the_public_route(): void
    {
        $middleware = M::allowListMiddleware('fn', 'shop', ['203.0.113.0/24', '198.51.100.7']);
        $this->assertSame('runr-access-shop', $middleware['metadata']['name']);
        $this->assertSame(['sourceRange' => ['203.0.113.0/24', '198.51.100.7']], $middleware['spec']['ipAllowList']);

        // behind a load balancer the region trusts: the client is the first untrusted address
        $behind = M::allowListMiddleware('fn', 'shop', ['203.0.113.0/24'], ['10.0.0.0/8']);
        $this->assertSame(['excludedIPs' => ['10.0.0.0/8']], $behind['spec']['ipAllowList']['ipStrategy']);

        [$web, $secure] = M::accessRoutes('fn', 'shop', 'shop.eu.runr.test');
        // a router with TLS answers HTTPS only: on web it would leave the host to Knative's route
        $this->assertSame([['web'], 'runr-access-shop'], [$web['spec']['entryPoints'], $web['metadata']['name']]);
        $this->assertArrayNotHasKey('tls', $web['spec']);
        $this->assertSame([['websecure'], 'runr-access-shop-tls'], [$secure['spec']['entryPoints'], $secure['metadata']['name']]);
        $this->assertArrayHasKey('tls', $secure['spec']);
        $rule = $secure['spec']['routes'][0];
        $this->assertSame($web['spec']['routes'], $secure['spec']['routes']);
        $this->assertSame('Host(`shop.eu.runr.test`)', $rule['match']);
        $this->assertSame(M::ACCESS_ROUTE_PRIORITY, $rule['priority']);
        $this->assertGreaterThan(0, $rule['priority'], 'above Knative\'s own route of the host');
        $this->assertLessThan(999999, $rule['priority'], 'below the region\'s status block');
        $this->assertSame([['name' => 'runr-access-shop', 'namespace' => 'fn']], $rule['middlewares']);
        $this->assertSame('fn-shop-rule-0-path-0-wrr@knative', $rule['services'][0]['name']);
    }

    public function test_a_custom_domain_of_an_allowlist_project_checks_the_client_first_and_skips_the_second_hop(): void
    {
        $route = ['spec' => ['routes' => [[
            'match' => 'Host(`shop.example.com`)',
            'middlewares' => [['name' => 'runr-domain-x', 'namespace' => 'fn']],
            'services' => [['name' => 'traefik', 'namespace' => 'knative-serving', 'port' => 80]],
        ]]]];

        $rule = M::restrictRoute($route, 'fn', 'shop')['spec']['routes'][0];

        $this->assertSame([['name' => 'runr-access-shop', 'namespace' => 'fn'], ['name' => 'runr-domain-x', 'namespace' => 'fn']], $rule['middlewares']);
        $this->assertSame([['name' => 'fn-shop-rule-0-path-0-wrr@knative', 'kind' => 'TraefikService']], $rule['services']);
    }

    public function test_the_authz_runs_twice_never_both_down_and_reads_its_own_configuration_only(): void
    {
        $objects = collect(M::authz('knative-serving', 'ghcr.io/brf-tech/runr/agent:latest'))->keyBy('kind');

        $deployment = $objects['Deployment'];
        $this->assertSame(2, $deployment['spec']['replicas']);
        $this->assertSame(0, $deployment['spec']['strategy']['rollingUpdate']['maxUnavailable']);
        $container = $deployment['spec']['template']['spec']['containers'][0];
        $this->assertContains(['name' => 'RUNR_AGENT_MODE', 'value' => 'authz'], $container['env']);
        $this->assertSame(M::AUTHZ_PORT, $container['ports'][0]['containerPort']);
        $this->assertSame(1, $objects['PodDisruptionBudget']['spec']['minAvailable']);

        // Knative Services and pods (a caller without a token is known by its pod) cluster-wide,
        // read only; its own ConfigMap and Secret only
        $this->assertSame(['get', 'list', 'watch'], $objects['ClusterRole']['rules'][0]['verbs']);
        $this->assertSame(['services'], $objects['ClusterRole']['rules'][0]['resources']);
        $this->assertSame([['apiGroups' => [''], 'resources' => ['pods'], 'verbs' => ['get', 'list', 'watch']]], array_slice($objects['ClusterRole']['rules'], 1));
        $this->assertSame([M::CONFIG], $objects['Role']['rules'][0]['resourceNames']);
        $this->assertSame(['get'], $objects['Role']['rules'][0]['verbs']);
    }

    public function test_the_private_entry_point_asks_the_authz_and_drops_the_token_header(): void
    {
        [$forwardAuth, $strip] = M::entryPointMiddlewareManifests('knative-serving');

        $this->assertSame('http://runr-internal-auth.knative-serving.svc.cluster.local:8090/authz', $forwardAuth['spec']['forwardAuth']['address']);
        $this->assertSame(['X-Runr-Caller'], $forwardAuth['spec']['forwardAuth']['authResponseHeaders']);
        $this->assertFalse($forwardAuth['spec']['forwardAuth']['trustForwardHeader']);
        $this->assertSame(['X-Runr-Internal-Token' => ''], $strip['spec']['headers']['customRequestHeaders'], 'the callee never sees the caller\'s token');

        $this->assertSame([
            'knative-serving-runr-internal-auth@kubernetescrd',
            'knative-serving-runr-internal-strip@kubernetescrd',
        ], M::entryPointMiddlewares('knative-serving'), 'the ForwardAuth first, then the removal');
    }

    public function test_a_new_region_traefik_refers_to_the_entry_point_middlewares(): void
    {
        $service = (new \ReflectionClass(KnativeInstallService::class))->newInstanceWithoutConstructor();
        $values = (new \ReflectionMethod($service, 'traefikHelmValues'))->invoke($service, 'knative-serving', [], 600);

        $this->assertSame(M::entryPointMiddlewares('knative-serving'), $values['ports'][PrivateIngress::ENTRYPOINT]['http']['middlewares']);
        $this->assertArrayNotHasKey('middlewares', $values['ports']['web'], 'the public entry points stay as they are');
    }

    public function test_the_configuration_names_each_namespace_s_mode_and_the_cross_organization_switch(): void
    {
        $cm = M::configMap('knative-serving', ['fn' => 'enforce', 'legacy' => 'audit'], false);

        $this->assertSame(['cross_tenant' => 'false', 'mode.fn' => 'enforce', 'mode.legacy' => 'audit'], $cm['data']);
    }

    public function test_coredns_gets_the_rewrite_before_its_kubernetes_plugin_once(): void
    {
        $with = M::corefileWith(self::COREFILE, 'knative-serving');

        $this->assertNotNull($with);
        $rewrite = strpos($with, 'rewrite stop {');
        $this->assertNotFalse($rewrite);
        $this->assertLessThan(strpos($with, 'kubernetes cluster.local'), $rewrite, 'before the kubernetes plugin answers cluster names');
        $this->assertStringContainsString('    name regex ^[a-z0-9-]+\.[a-z0-9-]+\.runr\.internal\.$ traefik-internal.knative-serving.svc.cluster.local.', $with);
        $this->assertStringContainsString('    answer auto', $with);

        // applied again: the same Corefile, one rewrite
        $this->assertSame($with, M::corefileWith($with, 'knative-serving'));
        $this->assertSame(1, substr_count((string) M::corefileWith($with, 'knative-serving'), M::COREDNS_BEGIN));
        // another Serving namespace replaces it
        $this->assertStringContainsString('traefik-internal.other.svc', (string) M::corefileWith($with, 'other'));
        $this->assertSame(self::COREFILE, M::corefileWithout($with));

        $this->assertNull(M::corefileWith(".:53 {\n    forward . /etc/resolv.conf\n}\n", 'knative-serving'), 'no kubernetes plugin to put it before');
    }
}
