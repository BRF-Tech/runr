<?php

namespace Tests\Feature\GlobalProjects;

use App\Jobs\RedeployRegionAgentJob;
use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\PowerDnsManifests;
use App\Services\KubernetesClient;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\RegionReconcile\RegionReconciler;
use App\Services\RegionReconcile\Steps\GlobalDnsStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * region:reconcile and the global DNS server (docs/global-projects.md): a region that runs one has
 * it brought to this release's image and configuration, and its agent deployed again when it lacks
 * the global-health Service; a region without one gets nothing.
 */
class GlobalDnsReconcileStepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array> "METHOD path" => body */
    private array $responses = [];

    /** @var list<string> */
    private array $writes = [];

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() !== 'GET') {
                $this->writes[] = $request->method().' '.$path;
            }

            return isset($this->responses[$request->method().' '.$path])
                ? Http::response($this->responses[$request->method().' '.$path])
                : ($request->method() === 'GET' ? Http::response(['kind' => 'Status', 'code' => 404], 404) : Http::response([], 200));
        });

        $this->region = KnativeRegion::create([
            'name' => 'eu-1',
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://eu-1.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 't']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ]);
    }

    private function cluster(): ClusterGroup
    {
        return new ClusterGroup('uid:test', [new ReconcileTarget($this->region)], new KubernetesClient($this->region->kubeconfig));
    }

    private function liveServer(string $image, string $hash): void
    {
        $this->responses['GET /apis/apps/v1/namespaces/runr-global-dns/deployments/runr-pdns'] = ['spec' => ['template' => [
            'metadata' => ['annotations' => ['runr.dev/config-hash' => $hash]],
            'spec' => ['containers' => [['name' => 'pdns', 'image' => $image]]],
        ]]];
    }

    public function test_the_step_runs_before_the_registry_prewarm(): void
    {
        $steps = array_map(fn ($s) => $s->name(), app(RegionReconciler::class)->steps());

        $this->assertContains('global-dns', $steps);
        $this->assertSame('registry-prewarm', end($steps));
    }

    public function test_a_region_without_a_server_gets_nothing(): void
    {
        $this->assertSame([], (new GlobalDnsStep)->run($this->cluster(), false));
        $this->assertSame([], $this->writes);
    }

    public function test_an_older_image_is_reported_in_a_dry_run_and_applied_otherwise(): void
    {
        RegionGlobalDns::query()->create(['knative_region_id' => $this->region->id, 'status' => 'ready', 'address' => '203.0.113.53', 'api_key' => 'k']);
        // another image than the pin (an upstream one: the reqs mirror holds the pinned tags only)
        $this->liveServer('docker.io/powerdns/pdns-auth-50:5.0.1', PowerDnsManifests::configHash(GlobalDnsServerService::options()));
        $this->responses['GET /api/v1/namespaces/observability/services/runr-agent-health'] = ['metadata' => ['name' => 'runr-agent-health']];

        $lines = (new GlobalDnsStep)->run($this->cluster(), true);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('would update the PowerDNS server of region eu-1', $lines[0]);
        $this->assertStringContainsString('pdns-auth-50:5.0.1 → ghcr.io/brf-tech/runr/reqs/pdns-auth:5.0.8', $lines[0]);
        $this->assertSame([], $this->writes);

        $lines = (new GlobalDnsStep)->run($this->cluster(), false);
        $this->assertStringStartsWith('updated the PowerDNS server', $lines[0]);
        $this->assertContains('PATCH /apis/apps/v1/namespaces/runr-global-dns/deployments/runr-pdns', $this->writes);
    }

    public function test_an_up_to_date_server_with_an_agent_without_health_service_redeploys_the_agent(): void
    {
        RegionGlobalDns::query()->create(['knative_region_id' => $this->region->id, 'status' => 'ready', 'address' => '203.0.113.53', 'api_key' => 'k']);
        $options = GlobalDnsServerService::options();
        $this->liveServer($options['image'], PowerDnsManifests::configHash($options));

        $lines = (new GlobalDnsStep)->run($this->cluster(), false);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('region agent of eu-1 deployed again', $lines[0]);
        Queue::assertPushed(RedeployRegionAgentJob::class);
    }
}
