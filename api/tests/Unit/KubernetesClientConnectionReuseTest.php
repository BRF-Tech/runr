<?php

namespace Tests\Unit;

use App\Services\KubernetesClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Every call to a region's API server opened a new TCP + TLS connection (a new Guzzle handler per
 * request, a new CA file per client): two to three round trips per call, and a deploy with its
 * readiness checks makes a dozen calls one after the other. The clients of a process share one
 * cURL handler — whose handles keep their connections — and the same certificate files per API
 * server and credentials, renewed after KubernetesClient::IDLE_SECONDS without a call.
 */
class KubernetesClientConnectionReuseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ReflectionProperty(KubernetesClient::class, 'transports'))->setValue(null, []);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(KubernetesClient::class, 'transports'))->setValue(null, []);
        parent::tearDown();
    }

    private function kubeconfig(string $server, string $token = 't'): string
    {
        return Yaml::dump([
            'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
            'clusters' => [['name' => 'c', 'cluster' => ['server' => $server, 'certificate-authority-data' => base64_encode("CA of {$server}")]]],
            'users' => [['name' => 'u', 'user' => ['token' => $token]]],
            'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
        ], 10, 2);
    }

    private function pending(KubernetesClient $client): PendingRequest
    {
        return (fn () => $this->request())->call($client);
    }

    private function handlerOf(PendingRequest $request): mixed
    {
        return (fn () => $this->handler)->call($request);
    }

    public function test_the_clients_of_one_api_server_share_a_handler_and_the_ca_file(): void
    {
        $a = new KubernetesClient($this->kubeconfig('https://region-a.test:6443'));
        $b = new KubernetesClient($this->kubeconfig('https://region-a.test:6443'));
        $other = new KubernetesClient($this->kubeconfig('https://region-b.test:6443'));
        $otherUser = new KubernetesClient($this->kubeconfig('https://region-a.test:6443', 'someone-else'));

        $first = $this->pending($a);
        $handler = $this->handlerOf($first);
        $this->assertIsCallable($handler, 'a handler of its own (keeps its connections), not a new one per request');
        $this->assertSame($handler, $this->handlerOf($this->pending($a)), 'the next call of the client');
        $this->assertSame($handler, $this->handlerOf($this->pending($b)), 'another client of the same API server');
        $ca = $first->getOptions()['verify'];
        $this->assertFileExists($ca);
        $this->assertSame('CA of https://region-a.test:6443', file_get_contents($ca));
        $this->assertSame($ca, $this->pending($b)->getOptions()['verify'], 'cURL reuses a connection only for the same CA file');

        $this->assertNotSame($handler, $this->handlerOf($this->pending($other)), 'another API server');
        $this->assertNotSame($handler, $this->handlerOf($this->pending($otherUser)), 'other credentials');
    }

    public function test_an_idle_api_server_gets_a_new_handler_and_its_files_go(): void
    {
        $client = new KubernetesClient($this->kubeconfig('https://region-a.test:6443'));
        $first = $this->pending($client);
        $handler = $this->handlerOf($first);
        $ca = $first->getOptions()['verify'];

        $this->travel(KubernetesClient::IDLE_SECONDS - 1)->seconds();
        $this->assertSame($handler, $this->handlerOf($this->pending($client)), 'in use: kept');

        $this->travel(KubernetesClient::IDLE_SECONDS + 1)->seconds();
        $renewed = $this->pending($client);
        $this->assertNotSame($handler, $this->handlerOf($renewed), 'idle connections may have been dropped on the way');
        $this->assertFileDoesNotExist($ca);
        $this->assertFileExists($renewed->getOptions()['verify']);

        // a temp directory cleaner removed the CA file: written again
        unlink($renewed->getOptions()['verify']);
        $this->assertFileExists($this->pending($client)->getOptions()['verify']);
    }

    public function test_requests_still_go_through_the_fake_with_the_credentials(): void
    {
        Http::fake(['*' => Http::response(['kind' => 'Secret', 'metadata' => ['name' => 'orders-secret-env']])]);
        $client = new KubernetesClient($this->kubeconfig('https://region-a.test:6443'));

        $this->assertSame('orders-secret-env', $client->getResourceStatus('v1', 'Secret', 'orders-secret-env', 'fn')['metadata']['name']);
        $this->assertSame('orders-secret-env', $client->getResourceStatus('v1', 'Secret', 'orders-secret-env', 'fn')['metadata']['name']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://region-a.test:6443/api/v1/namespaces/fn/secrets/orders-secret-env'
            && $request->header('Authorization') === ['Bearer t']);
    }
}
