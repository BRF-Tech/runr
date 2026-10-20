<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use App\Models\KnativeRegion;
use App\Services\Helpers\AgentToken;
use App\Services\Helpers\RegionCallbackHosts;
use App\Services\Helpers\RunrClusterIdentity;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Host header protection as in production (TrustHosts does nothing in unit tests and the local
 * environment, so these tests run in the production environment). RunR answers for the console's
 * host; on the region callbacks (/api/internal/…) also for the addresses it hands to the regions.
 *
 * Helm, the region being RunR's own cluster: the agent gets ws://runr-gateway.<ns>.svc.cluster.local
 * and calls http://runr-gateway.<ns>.svc.cluster.local/api/internal/observability/agent/{id}/…;
 * the gateway passes that host on as X-Forwarded-Host. Before 0.5.8 every such call got
 * 400 "Bad request." (Untrusted Host), so uptime checks with location "regions" never ran there.
 */
class TrustedHostsTest extends TestCase
{
    use FakesObservabilityCluster, RefreshDatabase;

    private const GATEWAY_POD = '10.42.0.20';   // runr-gateway pod (REMOTE_ADDR at the API)

    private const IN_CLUSTER = 'runr-gateway.runr.svc.cluster.local';

    private const SA_FILES = ['namespace', 'token', 'ca.crt'];

    /** Service account volume of the rotation tests, laid out as the kubelet writes it */
    private ?string $saVolume = null;

    private int $saGeneration = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
        Queue::fake();

        config([
            'app.url' => 'https://console.example.com',
            'knative.agent.runr_namespace' => 'runr',
            'knative.agent.serviceaccount_dir' => sys_get_temp_dir().'/runr-no-sa-'.uniqid(),
            'knative.agent.ws_url' => null,
            'observability.replay.ingest_url' => '',
            'workflows.public_api_url' => '',
        ]);
        $this->app['env'] = 'production';
    }

    protected function tearDown(): void
    {
        // Symfony keeps the trusted host patterns in a static
        Request::setTrustedHosts([]);
        $this->removeServiceAccountVolume();

        parent::tearDown();
    }

    /** A region whose agent holds a token: [region, Authorization header] */
    private function agentRegion(array $settings = []): array
    {
        $region = $this->obsRegion($settings === [] ? [] : ['knative_settings' => $settings]);
        $token = AgentToken::generate();
        $region->forceFill(['agent_token_hash' => AgentToken::hash($token)])->save();

        return [$region, ['Authorization' => 'Bearer '.$token]];
    }

    private function checks(string $host, KnativeRegion $region, array $auth): TestResponse
    {
        return $this->getJson("http://{$host}/api/internal/observability/agent/{$region->id}/uptime-checks", $auth);
    }

    /** Through the gateway: the in-cluster peer passes the host it was called with as X-Forwarded-Host */
    private function viaGateway(string $host, string $path, array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::GATEWAY_POD])
            ->getJson('http://runr-api'.$path, $headers + ['X-Forwarded-Host' => $host]);
    }

    /**
     * A service account volume as the kubelet writes it: `namespace`, `token` and `ca.crt` are links
     * into `..data`, a link to the directory of the current generation.
     */
    private function serviceAccountVolume(): string
    {
        $dir = sys_get_temp_dir().'/runr-sa-'.uniqid();
        mkdir("{$dir}/..gen-0", 0700, true);
        foreach (['namespace' => 'runr', 'token' => 'sa-token-0', 'ca.crt' => 'ca'] as $file => $content) {
            file_put_contents("{$dir}/..gen-0/{$file}", $content);
            symlink("..data/{$file}", "{$dir}/{$file}");
        }
        symlink('..gen-0', "{$dir}/..data");

        $this->saGeneration = 0;

        return $this->saVolume = $dir;
    }

    /**
     * Renews the volume as the kubelet does with every token rotation (about every 48 minutes): the
     * next generation's directory, `..data` pointed at it, the old directory deleted. From another
     * process, like the kubelet: PHP clears its own realpath cache when it renames or deletes a file.
     */
    private function rotateServiceAccountVolume(): void
    {
        $old = '..gen-'.$this->saGeneration;
        $new = '..gen-'.(++$this->saGeneration);
        $rotate = <<<'PHP'
            [, $dir, $old, $new, $token] = $argv;
            mkdir("{$dir}/{$new}", 0700);
            foreach (['namespace' => 'runr', 'token' => $token, 'ca.crt' => 'ca'] as $file => $content) {
                file_put_contents("{$dir}/{$new}/{$file}", $content);
            }
            symlink($new, "{$dir}/..data_tmp");
            rename("{$dir}/..data_tmp", "{$dir}/..data");
            foreach (['namespace', 'token', 'ca.crt'] as $file) {
                unlink("{$dir}/{$old}/{$file}");
            }
            rmdir("{$dir}/{$old}");
            PHP;

        (new Process([PHP_BINARY, '-r', $rotate, '--', (string) $this->saVolume, $old, $new, 'sa-token-'.$this->saGeneration]))->mustRun();
    }

    private function removeServiceAccountVolume(): void
    {
        if ($this->saVolume === null) {
            return;
        }

        $dir = $this->saVolume;
        $generation = '..gen-'.$this->saGeneration;
        foreach (self::SA_FILES as $file) {
            @unlink("{$dir}/{$generation}/{$file}");
            @unlink("{$dir}/{$file}");
        }
        @rmdir("{$dir}/{$generation}");
        @unlink("{$dir}/..data");
        @rmdir($dir);
        $this->saVolume = null;
    }

    public function test_the_framework_trust_hosts_middleware_is_replaced(): void
    {
        $kernel = app(Kernel::class);

        $this->assertTrue($kernel->hasMiddleware(TrustHosts::class));
        $this->assertFalse($kernel->hasMiddleware(\Illuminate\Http\Middleware\TrustHosts::class));
    }

    public function test_the_agent_of_runrs_own_cluster_reaches_its_callbacks_through_the_in_cluster_gateway(): void
    {
        [$region, $auth] = $this->agentRegion();
        $path = "/api/internal/observability/agent/{$region->id}/uptime-checks";

        $this->checks('console.example.com', $region, $auth)->assertOk();
        $this->checks(self::IN_CLUSTER, $region, $auth)->assertOk()->assertJsonStructure(['checks']);
        $this->viaGateway(self::IN_CLUSTER, $path, $auth)->assertOk()->assertJsonStructure(['checks']);

        $this->postJson('http://'.self::IN_CLUSTER."/api/internal/observability/agent/{$region->id}/uptime-results", ['results' => []], $auth)
            ->assertStatus(202);
        // the host only lets the request in: the agent's token is still required
        $this->checks(self::IN_CLUSTER, $region, ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        // replay captures of the function pods of that cluster go to the same address
        $this->postJson('http://'.self::IN_CLUSTER.'/api/internal/observability/replay/garbage', ['method' => 'GET'])->assertNotFound();
    }

    public function test_every_other_route_keeps_to_the_console_host(): void
    {
        $this->getJson('http://console.example.com/api/public/status-pages/nope')->assertNotFound();
        $this->getJson('http://'.self::IN_CLUSTER.'/api/public/status-pages/nope')->assertStatus(400);
        $this->viaGateway(self::IN_CLUSTER, '/api/public/status-pages/nope')->assertStatus(400);
        $this->get('http://'.self::IN_CLUSTER.'/up')->assertStatus(400);
    }

    public function test_a_host_runr_did_not_hand_out_is_refused_on_the_callbacks_too(): void
    {
        [$region, $auth] = $this->agentRegion();

        $this->checks('evil.example', $region, $auth)->assertStatus(400);
        $this->checks('runr-gateway.other.svc.cluster.local', $region, $auth)->assertStatus(400);
        $this->checks(self::IN_CLUSTER.'.evil.example', $region, $auth)->assertStatus(400);
        $this->viaGateway('evil.example', "/api/internal/observability/agent/{$region->id}/uptime-checks", $auth)->assertStatus(400);
    }

    public function test_the_in_cluster_host_follows_the_gateway_settings(): void
    {
        config([
            'knative.agent.runr_namespace' => 'platform',
            'knative.agent.gateway_service' => 'gw',
            'knative.agent.gateway_port' => 8080,
            'knative.agent.cluster_domain' => 'k8s.local',
        ]);
        [$region, $auth] = $this->agentRegion();

        $this->checks('gw.platform.svc.k8s.local:8080', $region, $auth)->assertOk();
        $this->checks(self::IN_CLUSTER, $region, $auth)->assertStatus(400);

        // outside a pod, without RUNR_NAMESPACE: no in-cluster gateway is handed out
        config(['knative.agent.runr_namespace' => null]);
        $this->assertSame([], app(RegionCallbackHosts::class)->hosts());
        $this->checks('gw.platform.svc.k8s.local:8080', $region, $auth)->assertStatus(400);
    }

    public function test_configured_and_region_addresses_are_accepted_on_the_callbacks(): void
    {
        config([
            'knative.agent.ws_url' => 'ws://10.0.0.10:8080',
            'observability.replay.ingest_url' => 'http://ingest.internal',
            'workflows.public_api_url' => 'https://API.Internal.example/',
        ]);
        [$region, $auth] = $this->agentRegion(['agent_ws_url' => 'wss://runr.lan/ws/agent/x']);
        $this->obsRegion(['knative_settings' => ['app_url' => 'http://172.18.0.5']]);

        $this->assertEqualsCanonicalizing(
            ['10.0.0.10', 'ingest.internal', 'api.internal.example', self::IN_CLUSTER, 'runr.lan', '172.18.0.5'],
            app(RegionCallbackHosts::class)->hosts(),
        );

        foreach (['10.0.0.10', 'ingest.internal', 'api.internal.example', 'runr.lan', '172.18.0.5'] as $host) {
            $this->checks($host, $region, $auth)->assertOk();
            $this->getJson("http://{$host}/api/public/status-pages/nope")->assertStatus(400);
        }

        $this->postJson('http://ingest.internal/api/internal/observability/replay/garbage', ['method' => 'GET'])->assertNotFound();
        $this->postJson('http://api.internal.example/api/internal/workflows/queue/_/01JNOPROJECT00000000000000/'.str_repeat('0', 40), [])
            ->assertNotFound();
    }

    /**
     * Without RUNR_NAMESPACE the namespace comes from the service account volume, which the kubelet
     * renews with every token rotation. PHP's realpath cache still resolved the file into the deleted
     * directory for up to two minutes, so the in-cluster gateway dropped out of the trusted hosts and
     * the agent's fetch of its uptime checks got 400 once or twice about every 48 minutes.
     */
    public function test_the_in_cluster_host_stays_trusted_across_a_service_account_rotation(): void
    {
        config(['knative.agent.runr_namespace' => null, 'knative.agent.serviceaccount_dir' => $this->serviceAccountVolume()]);
        [$region, $auth] = $this->agentRegion();
        $path = "/api/internal/observability/agent/{$region->id}/uptime-checks";

        $this->viaGateway(self::IN_CLUSTER, $path, $auth)->assertOk();

        $this->rotateServiceAccountVolume();
        $this->viaGateway(self::IN_CLUSTER, $path, $auth)->assertOk()->assertJsonStructure(['checks']);
        $this->postJson('http://'.self::IN_CLUSTER."/api/internal/observability/agent/{$region->id}/uptime-results", ['results' => []], $auth)
            ->assertStatus(202);

        $this->rotateServiceAccountVolume();
        $this->viaGateway(self::IN_CLUSTER, $path, $auth)->assertOk();
        $this->viaGateway('evil.example', $path, $auth)->assertStatus(400);
    }

    /** The same rotation for RunR's own token: the in-cluster client takes the new one at once. */
    public function test_the_in_cluster_client_reads_the_rotated_token(): void
    {
        config(['knative.agent.runr_namespace' => null, 'knative.agent.serviceaccount_dir' => $this->serviceAccountVolume()]);
        // the in-cluster API (kubernetes.default.svc, or KUBERNETES_SERVICE_HOST where the tests run in a pod)
        $this->externalRoutes['https://'] = fn () => Http::response(['metadata' => ['uid' => 'uid-1']]);
        $identity = new RunrClusterIdentity;
        $this->assertSame('uid-1', $identity->ownClusterUid());

        $this->rotateServiceAccountVolume();
        Cache::flush();

        $this->assertSame('uid-1', $identity->ownClusterUid());
        $this->assertSame('runr', $identity->namespace());
        Http::assertSent(fn (ClientRequest $r) => $r->header('Authorization') === ['Bearer sa-token-1']);
    }

    public function test_an_empty_region_lookup_is_not_cached(): void
    {
        [$region, $auth] = $this->agentRegion();
        $this->assertSame([self::IN_CLUSTER], app(RegionCallbackHosts::class)->hosts());

        // a region given its own address is trusted on the next callback, not a minute later
        $this->obsRegion(['knative_settings' => ['app_url' => 'http://172.18.0.5']]);
        $this->checks('172.18.0.5', $region, $auth)->assertOk();
    }

    public function test_the_in_cluster_host_does_not_depend_on_the_database_or_the_cache(): void
    {
        $this->obsRegion(['knative_settings' => ['app_url' => 'http://172.18.0.5']]);
        $default = config('database.default');

        // the database cannot be read: the in-cluster gateway still, and nothing is cached
        config(['database.default' => 'unavailable']);
        $this->assertSame([self::IN_CLUSTER], app(RegionCallbackHosts::class)->hosts());
        config(['database.default' => $default]);
        $this->assertEqualsCanonicalizing([self::IN_CLUSTER, '172.18.0.5'], app(RegionCallbackHosts::class)->hosts());

        // the cache cannot be used: the database is read
        Cache::shouldReceive('get')->andThrow(new RuntimeException('cache unavailable'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('cache unavailable'));
        $this->assertEqualsCanonicalizing([self::IN_CLUSTER, '172.18.0.5'], app(RegionCallbackHosts::class)->hosts());
    }
}
