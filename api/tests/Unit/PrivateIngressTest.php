<?php

namespace Tests\Unit;

use App\Services\HelmService;
use App\Services\Helpers\PrivateIngress;
use App\Services\Helpers\RequestTimeout;
use App\Services\KnativeInstallService;
use App\Services\Manifests\RegionNetworkPolicyManifests as P;
use PHPUnit\Framework\TestCase;

/**
 * The region Traefik serves cluster-local Knative routes on the private entry point internal,
 * which only the ClusterIP Service traefik-internal publishes — never on web / websecure, the
 * ports of the LoadBalancer; the NetworkPolicies leave Traefik and Knative the only way to the
 * project pods.
 */
class PrivateIngressTest extends TestCase
{
    private function traefikValues(): array
    {
        $service = (new \ReflectionClass(KnativeInstallService::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($service, 'traefikHelmValues'))->invoke($service, 'knative-serving', [], 600);
    }

    public function test_a_new_traefik_serves_cluster_local_routes_on_the_private_entry_point_only(): void
    {
        $values = $this->traefikValues();

        $internal = $values['ports']['internal'];
        $this->assertSame(8081, $internal['port']);
        $this->assertSame(['default' => false, 'internal' => true], $internal['expose'], 'not on the LoadBalancer Service');
        $this->assertSame(80, $internal['exposedPort'], 'the placeholders\' ExternalName is called on port 80');
        $this->assertSame('610s', $internal['transport']['respondingTimeouts']['readTimeout'], 'a queue sink waits as long as a public request');
        $this->assertSame(['internal' => ['type' => 'ClusterIP']], $values['service']['additionalServices']);
        $this->assertSame('LoadBalancer', $values['service']['type']);
        foreach (['web', 'websecure'] as $public) {
            $this->assertTrue($values['ports'][$public]['expose']['default']);
        }

        $args = $values['additionalArguments'];
        $this->assertContains('--providers.knative.privateentrypoints=internal', $args);
        $this->assertContains('--providers.knative.privateservice.name=traefik-internal', $args);
        $this->assertContains('--providers.knative.privateservice.namespace=knative-serving', $args);
        $this->assertContains('--providers.knative.publicentrypoints=web,websecure', $args);
        $this->assertNotContains('--providers.knative.privateentrypoints=web', $args);
        $this->assertContains('--entrypoints.websecure.http.tls.options=default', $args);

        // the values file the chart gets: the additional Service and the port as maps
        $this->assertStringContainsString("additionalServices:\n", HelmService::valuesYaml($values));
    }

    public function test_the_state_of_a_traefik_follows_its_arguments(): void
    {
        $address = '--entryPoints.internal.address=:8081/tcp';

        $this->assertSame(PrivateIngress::STATE_LEGACY, PrivateIngress::state(PrivateIngress::additionalArguments('knative-serving', PrivateIngress::STATE_LEGACY)));
        $this->assertSame(PrivateIngress::STATE_TRANSITIONAL, PrivateIngress::state([...PrivateIngress::additionalArguments('knative-serving', PrivateIngress::STATE_TRANSITIONAL), $address]));
        $this->assertSame(PrivateIngress::STATE_PRIVATE, PrivateIngress::state([...PrivateIngress::additionalArguments('knative-serving'), $address]));
        // what RunR installed before: web, its own Service as the load balancer
        $this->assertSame(PrivateIngress::STATE_LEGACY, PrivateIngress::state([
            '--providers.knative.privateservice.name=traefik', '--providers.knative.privateservice.namespace=knative-serving',
            '--providers.knative.privateentrypoints=web',
        ]));

        // the arguments without the chart's internal port, or another layout: not RunR's
        $this->assertNull(PrivateIngress::state(PrivateIngress::additionalArguments('knative-serving')));
        $this->assertNull(PrivateIngress::state(['--ping=true']));
        $this->assertNull(PrivateIngress::state([$address, '--providers.knative.privateservice.name=other', '--providers.knative.privateentrypoints=internal']));
    }

    public function test_the_upgrade_sets_the_port_the_service_and_the_arguments(): void
    {
        $set = PrivateIngress::helmSetJson('knative-serving', PrivateIngress::STATE_TRANSITIONAL, 300);

        $this->assertSame(['ports.internal', 'service.additionalServices.internal', 'additionalArguments'], array_keys($set));
        $this->assertSame('{"type":"ClusterIP"}', $set['service.additionalServices.internal']);
        $this->assertSame('310s', json_decode($set['ports.internal'], true)['transport']['respondingTimeouts']['writeTimeout']);
        $this->assertContains('--providers.knative.privateentrypoints=web,internal', json_decode($set['additionalArguments'], true));
    }

    public function test_the_private_load_balancer_of_a_kingress(): void
    {
        $this->assertSame('traefik.knative-serving.svc.cluster.local', PrivateIngress::privateLoadBalancer([
            'status' => ['privateLoadBalancer' => ['ingress' => [['domainInternal' => 'traefik.knative-serving.svc.cluster.local']]]],
        ]));
        $this->assertNull(PrivateIngress::privateLoadBalancer(['status' => []]));
        $this->assertSame('traefik-internal.knative-serving.svc.cluster.local', PrivateIngress::serviceHostname(PrivateIngress::SERVICE, 'knative-serving'));
    }

    public function test_the_private_entry_point_has_the_request_timeout_once_the_traefik_has_it(): void
    {
        $this->assertSame(['web', 'websecure'], RequestTimeout::entryPoints(['--entryPoints.web.address=:8000/tcp']));
        $this->assertSame(['web', 'websecure', 'internal'], RequestTimeout::entryPoints(['--entryPoints.internal.address=:8081/tcp']));

        $current = RequestTimeout::currentTraefik([
            '--entryPoints.web.transport.respondingTimeouts.readTimeout=310s', '--entryPoints.web.transport.respondingTimeouts.writeTimeout=310s',
            '--entryPoints.websecure.transport.respondingTimeouts.readTimeout=310s', '--entryPoints.websecure.transport.respondingTimeouts.writeTimeout=310s',
        ]);
        $this->assertTrue(RequestTimeout::traefikMatches($current, 310));
        $this->assertFalse(RequestTimeout::traefikMatches($current, 310, ['web', 'websecure', 'internal']), 'internal has none yet');
        $this->assertArrayHasKey('ports.internal.transport.respondingTimeouts.readTimeout', RequestTimeout::helmSetJson(300, ['web', 'websecure', 'internal']));
    }

    public function test_revision_pods_admit_only_traefik_the_activator_and_the_autoscaler(): void
    {
        $policy = P::revisions('fn', 'knative-serving');

        $this->assertSame(['name' => 'runr-knative-revisions', 'namespace' => 'fn'], array_intersect_key($policy['metadata'], ['name' => 1, 'namespace' => 1]));
        // revision pods only: CronJob runs, queue consumers and builds of the namespace are not selected
        $this->assertSame(['matchExpressions' => [['key' => 'serving.knative.dev/revision', 'operator' => 'Exists']]], $policy['spec']['podSelector']);
        $this->assertSame(['Ingress'], $policy['spec']['policyTypes']);
        $this->assertCount(1, $policy['spec']['ingress']);
        $from = $policy['spec']['ingress'][0]['from'];
        $this->assertSame([['app.kubernetes.io/name' => 'traefik'], ['app' => 'activator'], ['app' => 'autoscaler']], array_map(fn ($f) => $f['podSelector']['matchLabels'], $from));
        foreach ($from as $peer) {
            $this->assertSame(['kubernetes.io/metadata.name' => 'knative-serving'], $peer['namespaceSelector']['matchLabels']);
        }
        $this->assertArrayNotHasKey('ports', $policy['spec']['ingress'][0], 'all ports: queue-proxy, the autoscaler\'s metrics port');
    }

    public function test_the_activator_admits_only_traefik_and_the_autoscaler_and_keeps_its_metrics_port(): void
    {
        $policy = P::activator('knative-serving');

        $this->assertSame('knative-serving', $policy['metadata']['namespace']);
        $this->assertSame(['matchLabels' => ['app' => 'activator']], $policy['spec']['podSelector']);
        $this->assertSame([['podSelector' => ['matchLabels' => ['app.kubernetes.io/name' => 'traefik']]], ['podSelector' => ['matchLabels' => ['app' => 'autoscaler']]]], $policy['spec']['ingress'][0]['from']);
        $this->assertSame([['ports' => [['protocol' => 'TCP', 'port' => 'metrics']]]], array_slice($policy['spec']['ingress'], 1));
    }

    public function test_a_policy_as_the_api_server_returns_it_is_up_to_date(): void
    {
        $wanted = P::revisions('fn', 'knative-serving');
        $stored = json_decode(json_encode($wanted), true);
        $stored['spec'] = array_reverse($stored['spec'], true);
        $stored['metadata']['resourceVersion'] = '42';

        $this->assertTrue(P::upToDate($stored, $wanted));
        $this->assertFalse(P::upToDate(null, $wanted));
        $stored['spec']['ingress'][0]['from'] = array_slice($stored['spec']['ingress'][0]['from'], 0, 2);
        $this->assertFalse(P::upToDate($stored, $wanted), 'the autoscaler missing');
    }
}
