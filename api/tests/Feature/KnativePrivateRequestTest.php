<?php

namespace Tests\Feature;

use App\Services\InternalNetwork\InternalNetwork;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsAiFixtures;
use Tests\TestCase;

/**
 * RunR's own calls to a Knative Service (shell wake-up and keep-alive, the model playground) go
 * through the API server to the service's cluster-local host on the region Traefik's private
 * entry point — the revision pods and the activator admit only Traefik and Knative.
 */
class KnativePrivateRequestTest extends TestCase
{
    use BuildsAiFixtures;

    private const PROXY = '/api/v1/namespaces/knative-serving/services/http:';

    public function test_the_request_goes_to_traefik_internal_with_the_cluster_local_host(): void
    {
        $this->fakeCluster([
            'GET '.self::PROXY.'traefik-internal:80/proxy/healthz' => ['ok' => true],
        ]);

        $response = (new KubernetesClient($this->fakeKubeconfig()))->knativePrivateRequest('GET', 'knative-serving', 'fn', 'shop', 'healthz');

        $this->assertTrue($response->successful());
        $call = $this->k8sCallsTo('GET', 'traefik-internal')[0];
        $this->assertSame(['shop.fn.svc.cluster.local'], $call['headers']['Host']);
        $this->assertSame([], $this->k8sCallsTo('GET', 'activator-service'));
    }

    public function test_a_region_not_reconciled_yet_is_called_on_traefiks_main_service(): void
    {
        $this->fakeCluster([
            // the API server knows no traefik-internal: region:reconcile has not run since the upgrade
            'GET '.self::PROXY.'traefik-internal:80/proxy/' => [['kind' => 'Status', 'code' => 404, 'reason' => 'NotFound', 'details' => ['name' => 'traefik-internal', 'kind' => 'services']], 404],
            'GET '.self::PROXY.'traefik:80/proxy/' => ['ok' => true],
        ]);

        $this->assertTrue((new KubernetesClient($this->fakeKubeconfig()))->knativePrivateRequest('GET', 'knative-serving', 'fn', 'shop')->successful());
        $this->assertSame(['shop.fn.svc.cluster.local'], $this->k8sCallsTo('GET', 'http:traefik:80')[0]['headers']['Host']);
    }

    public function test_traefiks_own_404_is_the_answer(): void
    {
        $this->fakeCluster([
            'GET '.self::PROXY.'traefik-internal:80/proxy/' => fn () => Http::response('404 page not found', 404),
        ]);

        $this->assertSame(404, (new KubernetesClient($this->fakeKubeconfig()))->knativePrivateRequest('GET', 'knative-serving', 'fn', 'gone')->status());
        $this->assertSame([], $this->k8sCallsTo('GET', 'http:traefik:80'));
    }

    public function test_the_shell_wake_up_and_keep_alive_take_the_private_route(): void
    {
        $this->fakeCluster([
            'GET /apis/serving.knative.dev/v1/namespaces/fn/configurations' => ['items' => [['status' => ['latestReadyRevisionName' => 'shop-00002']]]],
            'GET '.self::PROXY.'traefik-internal:80/proxy/' => ['ok' => true],
        ]);
        $k8s = new KubernetesClient($this->fakeKubeconfig());

        $this->assertTrue($k8s->wakeUpKnativeService('knative-serving', 'fn', 'shop'));

        $kubeconfig = tempnam(sys_get_temp_dir(), 'kc');
        file_put_contents($kubeconfig, $this->fakeKubeconfig());
        $this->assertSame(0, Artisan::call('shell:keep-alive', [
            '--kubeconfig' => $kubeconfig, '--serving-namespace' => 'knative-serving', '--namespace' => 'fn', '--service' => 'shop',
        ]));
        unlink($kubeconfig);

        $calls = $this->k8sCallsTo('GET', 'traefik-internal');
        $this->assertCount(2, $calls);
        foreach ($calls as $call) {
            $this->assertSame(['shop.fn.svc.cluster.local'], $call['headers']['Host']);
        }
        $this->assertSame([], $this->k8sCallsTo('GET', 'activator-service'));
        $this->assertSame([], $this->k8sCallsTo('GET', '-private'));
    }

    /** The ForwardAuth of the private entry point admits RunR's own short-lived token (docs/internal-network.md) */
    public function test_runrs_calls_carry_its_own_token_on_the_internal_network(): void
    {
        $this->fakeCluster([
            'GET '.self::PROXY.'traefik-internal:80/proxy/' => ['ok' => true],
        ]);
        $k8s = new KubernetesClient($this->fakeKubeconfig());

        $k8s->knativePrivateRequest('GET', 'knative-serving', 'fn', 'shop', '', [], null, 15, InternalNetwork::systemToken('01JREGION00000000000000000'));
        $k8s->knativePrivateRequest('GET', 'knative-serving', 'fn', 'shop');

        $kubeconfig = tempnam(sys_get_temp_dir(), 'kc');
        file_put_contents($kubeconfig, $this->fakeKubeconfig());
        Artisan::call('shell:keep-alive', [
            '--kubeconfig' => $kubeconfig, '--serving-namespace' => 'knative-serving', '--namespace' => 'fn', '--service' => 'shop',
            '--region' => '01JREGION00000000000000000',
        ]);
        unlink($kubeconfig);

        [$signed, $unsigned, $keepAlive] = $this->k8sCallsTo('GET', 'traefik-internal');
        $claims = InternalNetwork::verify('01JREGION00000000000000000', $signed['headers'][InternalNetwork::HEADER][0]);
        $this->assertSame(InternalNetwork::SYSTEM_SUBJECT, $claims['sub']);
        $this->assertArrayNotHasKey(InternalNetwork::HEADER, $unsigned['headers']);
        $this->assertSame(InternalNetwork::SYSTEM_SUBJECT, InternalNetwork::verify('01JREGION00000000000000000', $keepAlive['headers'][InternalNetwork::HEADER][0])['sub']);
    }
}
