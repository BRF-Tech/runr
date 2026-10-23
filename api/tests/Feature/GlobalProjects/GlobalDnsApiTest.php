<?php

namespace Tests\Feature\GlobalProjects;

use App\Jobs\GlobalDns\CheckGlobalDnsServerJob;
use App\Jobs\GlobalDns\RemoveGlobalDnsServerJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\RedeployRegionAgentJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Models\SecureSetting;
use App\Models\Setting;
use App\Services\Dns\DnsResolver;
use App\Services\GlobalDns\DnsWire;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\GlobalDnsZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Global DNS through the API (docs/global-projects.md, "Global DNS"): enabling a region's PowerDNS
 * server (installed through the region's kubeconfig, followed until ready: zone, TSIG key, public
 * :53), the refusals, disabling it, and the zone overview with the delegation records the operator
 * creates.
 */
class GlobalDnsApiTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const API = '/api/v1/namespaces/runr-global-dns/services/http:runr-pdns-api:8081/proxy/api/v1/servers/localhost';

    /** @var list<array{method: string, path: string, body: array}> */
    private array $calls = [];

    /** @var array<string, array> "METHOD path" => body */
    private array $responses = [];

    private object $dns;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('base_domain', 'example.com');

        $this->dns = new class extends DnsResolver
        {
            public array $nsRecords = [];

            public function ns(string $name): array
            {
                return $this->nsRecords[$name] ?? [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->app->instance(DnsWire::class, new class extends DnsWire
        {
            public function query(string $server, string $name, string $type = 'A', bool $tcp = false, int $port = 53, float $timeout = 3.0): ?array
            {
                return $server === '203.0.113.53' ? ['rcode' => 0, 'authoritative' => true, 'answers' => []] : null;
            }
        });

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $key = $request->method().' '.$path;
            $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? []];

            if (isset($this->responses[$key])) {
                return Http::response($this->responses[$key]);
            }

            return $request->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response([], 200);
        });
    }

    private function region(string $name = 'eu-1', array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => $name,
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$name}.test:6443", 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 't']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'external_ip' => '203.0.113.10',
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ]);
    }

    public function test_enabling_installs_the_server_and_follows_it(): void
    {
        $region = $this->region();

        $this->signedIn($this->userWithRole('owner'))->postJson("/api/knative-regions/{$region->id}/global-dns")
            ->assertStatus(202)
            ->assertJsonPath('status', 'installing')
            ->assertJsonPath('ns_name', 'ns-eu-1.g.example.com')
            ->assertJsonPath('eligible', true);

        $applied = array_column(array_filter($this->calls, fn ($c) => $c['method'] === 'PATCH'), 'path');
        $this->assertContains('/api/v1/namespaces/runr-global-dns', $applied);
        $this->assertContains('/apis/apps/v1/namespaces/runr-global-dns/deployments/runr-pdns', $applied);
        $this->assertContains('/api/v1/namespaces/runr-global-dns/services/runr-pdns', $applied);

        $server = RegionGlobalDns::forRegion($region);
        $this->assertNotEmpty($server->api_key);
        $this->assertArrayNotHasKey('api_key', $server->toArray());
        Queue::assertPushed(CheckGlobalDnsServerJob::class, fn ($job) => $job->regionId === $region->id);
        Queue::assertPushed(RedeployRegionAgentJob::class);
    }

    public function test_the_server_becomes_ready_with_its_address_zone_and_tsig_key(): void
    {
        $region = $this->region();
        $server = RegionGlobalDns::query()->create(['knative_region_id' => $region->id, 'status' => 'installing', 'api_key' => 'k']);
        $this->responses['GET /apis/apps/v1/namespaces/runr-global-dns/deployments/runr-pdns'] = ['status' => ['readyReplicas' => 1]];
        $this->responses['GET /api/v1/namespaces/runr-global-dns/services/runr-pdns'] = ['status' => ['loadBalancer' => ['ingress' => [['ip' => '203.0.113.53']]]]];

        $this->assertTrue(app(GlobalDnsServerService::class)->check($region, $server));

        $server->refresh();
        $this->assertSame('ready', $server->status);
        $this->assertSame('203.0.113.53', $server->address);
        $this->assertTrue($server->reachable);

        $zone = collect($this->calls)->first(fn ($c) => $c['method'] === 'POST' && $c['path'] === self::API.'/zones');
        $this->assertSame(['name' => 'g.example.com.', 'kind' => 'Native', 'nameservers' => ['ns-eu-1.g.example.com.'], 'soa_edit_api' => 'DEFAULT'], $zone['body']);

        $tsig = collect($this->calls)->first(fn ($c) => $c['method'] === 'POST' && $c['path'] === self::API.'/tsigkeys');
        $this->assertSame('runr-acme', $tsig['body']['name']);
        $this->assertSame('hmac-sha256', $tsig['body']['algorithm']);
        $this->assertSame(SecureSetting::getValue(GlobalDnsZone::TSIG_SETTING), $tsig['body']['key']);

        Queue::assertPushed(SyncGlobalZoneJob::class);
    }

    public function test_a_server_without_a_load_balancer_address_never_becomes_ready(): void
    {
        $region = $this->region();
        $server = RegionGlobalDns::query()->create(['knative_region_id' => $region->id, 'status' => 'installing', 'api_key' => 'k']);
        $this->responses['GET /apis/apps/v1/namespaces/runr-global-dns/deployments/runr-pdns'] = ['status' => ['readyReplicas' => 1]];

        $this->assertFalse(app(GlobalDnsServerService::class)->check($region, $server));
        $this->assertSame('installing', $server->fresh()->status);

        $this->assertTrue(app(GlobalDnsServerService::class)->check($region, $server->fresh(), true));
        $this->assertSame('error', $server->fresh()->status);
        $this->assertStringContainsString('public port 53', (string) $server->fresh()->status_message);
    }

    public function test_demo_regions_and_a_platform_without_base_domain_cannot_run_it(): void
    {
        $demo = $this->region('demo', ['is_demo' => true]);
        $this->signedIn($this->userWithRole('owner'))->postJson("/api/knative-regions/{$demo->id}/global-dns")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'Demo regions cannot run global DNS.');

        Setting::query()->whereKey('base_domain')->delete();
        $region = $this->region();
        $this->signedIn($this->userWithRole('owner'))->getJson("/api/knative-regions/{$region->id}/global-dns")
            ->assertOk()
            ->assertJsonPath('status', 'disabled')
            ->assertJsonPath('eligible', false);
    }

    public function test_disabling_is_refused_while_global_projects_run_in_the_region(): void
    {
        $region = $this->region();
        RegionGlobalDns::query()->create(['knative_region_id' => $region->id, 'status' => 'ready', 'address' => '203.0.113.53', 'api_key' => 'k']);
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id, 'placement' => 'global'])->saveQuietly();
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $region->id]);

        $this->signedIn($this->userWithRole('owner'))->deleteJson("/api/knative-regions/{$region->id}/global-dns")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Global projects run in this region (shop): remove the region from them first.']);

        $project->delete();
        $this->signedIn($this->userWithRole('owner'))->deleteJson("/api/knative-regions/{$region->id}/global-dns")
            ->assertStatus(202)
            ->assertJsonPath('status', 'uninstalling');
        Queue::assertPushed(RemoveGlobalDnsServerJob::class);
    }

    public function test_the_overview_lists_the_delegation_records_and_whether_dns_delegates(): void
    {
        $eu = $this->region();
        $us = $this->region('us-1');
        RegionGlobalDns::query()->create(['knative_region_id' => $eu->id, 'status' => 'ready', 'address' => '203.0.113.53', 'api_key' => 'k']);
        RegionGlobalDns::query()->create(['knative_region_id' => $us->id, 'status' => 'ready', 'address' => '2001:db8::53', 'api_key' => 'k']);
        $this->dns->nsRecords['g.example.com'] = ['ns-eu-1.g.example.com'];

        $response = $this->signedIn($this->userWithRole('owner'))->getJson('/api/global-dns')
            ->assertOk()
            ->assertJsonPath('zone', 'g.example.com')
            ->assertJsonPath('tls', 'acme')
            ->assertJsonPath('delegation.status', 'partial')
            ->assertJsonCount(2, 'servers');

        $this->assertSame([
            ['name' => 'g.example.com', 'type' => 'NS', 'value' => 'ns-eu-1.g.example.com'],
            ['name' => 'ns-eu-1.g.example.com', 'type' => 'A', 'value' => '203.0.113.53'],
            ['name' => 'g.example.com', 'type' => 'NS', 'value' => 'ns-us-1.g.example.com'],
            ['name' => 'ns-us-1.g.example.com', 'type' => 'AAAA', 'value' => '2001:db8::53'],
        ], $response->json('delegation.records'));
        $this->assertStringContainsString('ns-us-1.g.example.com', (string) $response->json('delegation.message'));

        $this->dns->nsRecords['g.example.com'] = ['ns-eu-1.g.example.com', 'ns-us-1.g.example.com'];
        $this->signedIn($this->userWithRole('owner'))->getJson('/api/global-dns')->assertJsonPath('delegation.status', 'ok');
    }

    public function test_a_region_that_runs_a_server_is_not_removed(): void
    {
        $region = $this->region();
        RegionGlobalDns::query()->create(['knative_region_id' => $region->id, 'status' => 'ready', 'address' => '203.0.113.53', 'api_key' => 'k']);

        $this->signedIn($this->userWithRole('owner'))->deleteJson("/api/knative-regions/{$region->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The region runs a global DNS server: disable it (Region → General → Global DNS) before removing the region.']);
        $this->assertNotNull(KnativeRegion::query()->find($region->id));
    }

    public function test_a_member_without_regions_manage_cannot_enable_it(): void
    {
        $region = $this->region();

        $this->signedIn($this->userWithRole('viewer'))->postJson("/api/knative-regions/{$region->id}/global-dns")->assertForbidden();
        $this->assertNull(RegionGlobalDns::forRegion($region));
    }
}
