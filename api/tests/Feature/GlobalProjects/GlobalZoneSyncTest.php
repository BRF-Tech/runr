<?php

namespace Tests\Feature\GlobalProjects;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Models\Setting;
use App\Models\Version;
use App\Services\GlobalDns\DnsWire;
use App\Services\GlobalDns\GlobalZoneSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The zone sync (docs/global-projects.md, "Zone sync"): every ready server gets the same zone
 * through its HTTP API (behind the Kubernetes API server's service proxy, with its API key) — the
 * project records, NS and glue; what RunR does not want goes; the DNS-01 challenges are copied
 * from the update server to the others; a server that differed is reported as drift.
 */
class GlobalZoneSyncTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/namespaces/runr-global-dns/services/http:runr-pdns-api:8081/proxy/api/v1/servers/localhost';

    /** @var list<array{host: string, method: string, path: string, body: array, key: string}> */
    private array $calls = [];

    /** @var array<string, array> host => zone rrsets */
    private array $zones = [];

    private KnativeRegion $eu;

    private KnativeRegion $us;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('base_domain', 'example.com');
        $this->app->instance(DnsWire::class, new class extends DnsWire
        {
            public function query(string $server, string $name, string $type = 'A', bool $tcp = false, int $port = 53, float $timeout = 3.0): ?array
            {
                return ['rcode' => 0, 'authoritative' => true, 'answers' => []];
            }
        });

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['host' => $host, 'method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? [], 'key' => $request->header('X-API-Key')[0] ?? ''];

            if ($request->method() === 'GET' && $path === self::API.'/zones/g.example.com.') {
                return isset($this->zones[$host])
                    ? Http::response(['name' => 'g.example.com.', 'rrsets' => $this->zones[$host]])
                    : Http::response(['error' => 'Could not find domain'], 404);
            }

            return $request->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response([], 200);
        });

        $this->eu = $this->region('eu-1', '203.0.113.10', '203.0.113.53', 'key-eu', now()->subHour());
        $this->us = $this->region('us-1', '198.51.100.10', '198.51.100.53', 'key-us', now());
    }

    private function region(string $name, string $ip, string $dns, string $key, $created): KnativeRegion
    {
        $region = KnativeRegion::create([
            'name' => $name,
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => "https://{$name}.test:6443", 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 't']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'external_ip' => $ip,
            'is_active' => true,
            'knative_serving_status' => 'ready',
        ]);

        $server = new RegionGlobalDns(['knative_region_id' => $region->id, 'status' => 'ready', 'address' => $dns, 'api_key' => $key]);
        $server->created_at = $created;
        $server->save();

        return $region;
    }

    private function globalProject(): Project
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => 'ha'])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->eu->id, 'position' => 0, 'status' => 'ready', 'served_version_id' => $version->id]);
        ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $this->us->id, 'position' => 1, 'status' => 'ready', 'served_version_id' => $version->id]);

        return $project;
    }

    /** @return list<array<string, mixed>> the rrsets PATCHed to a server */
    private function patched(string $host): array
    {
        $rrsets = [];
        foreach ($this->calls as $call) {
            if ($call['host'] === $host && $call['method'] === 'PATCH' && $call['path'] === self::API.'/zones/g.example.com.') {
                array_push($rrsets, ...$call['body']['rrsets']);
            }
        }

        return $rrsets;
    }

    private function rrset(array $rrsets, string $name, string $type): ?array
    {
        foreach ($rrsets as $rrset) {
            if ($rrset['name'] === $name && $rrset['type'] === $type) {
                return $rrset;
            }
        }

        return null;
    }

    public function test_every_server_gets_the_project_records_ns_and_glue_with_its_own_key(): void
    {
        $this->globalProject();
        $this->zones = ['eu-1.test' => [], 'us-1.test' => []];

        app(GlobalZoneSync::class)->sync();

        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $rrsets = $this->patched($host);
            $lua = $this->rrset($rrsets, 'shop.g.example.com.', 'LUA');
            $this->assertNotNull($lua, "LUA record on {$host}");
            $this->assertStringContainsString("{{'203.0.113.10'}, {'198.51.100.10'}}", $lua['records'][0]['content']);
            $this->assertSame(['ns-eu-1.g.example.com.', 'ns-us-1.g.example.com.'], array_column($this->rrset($rrsets, 'g.example.com.', 'NS')['records'], 'content'));
            $this->assertSame([['content' => '198.51.100.53', 'disabled' => false]], $this->rrset($rrsets, 'ns-us-1.g.example.com.', 'A')['records']);
        }

        $keys = array_unique(array_map(fn ($c) => "{$c['host']}={$c['key']}", array_filter($this->calls, fn ($c) => str_contains($c['path'], '/proxy/api/v1/'))));
        sort($keys);
        $this->assertSame(['eu-1.test=key-eu', 'us-1.test=key-us'], array_values($keys));
        $this->assertSame('in_sync', RegionGlobalDns::forRegion($this->eu)->sync_status);
    }

    public function test_a_missing_zone_is_created_first(): void
    {
        $this->zones = ['eu-1.test' => []];

        app(GlobalZoneSync::class)->sync();

        $create = collect($this->calls)->first(fn ($c) => $c['host'] === 'us-1.test' && $c['method'] === 'POST' && $c['path'] === self::API.'/zones');
        $this->assertNotNull($create);
        $this->assertSame('g.example.com.', $create['body']['name']);
        $this->assertSame('Native', $create['body']['kind']);
    }

    public function test_challenges_are_copied_from_the_update_server_and_drift_is_repaired(): void
    {
        $challenge = ['name' => '_acme-challenge.g.example.com.', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => '"dns01-token"', 'disabled' => false]]];
        $this->zones = [
            // eu-1 is the update server (its server is the oldest): cert-manager wrote the challenge there
            'eu-1.test' => [$challenge],
            // us-1 has a record nobody wants and an old challenge
            'us-1.test' => [
                ['name' => 'stale.g.example.com.', 'type' => 'A', 'ttl' => 60, 'records' => [['content' => '192.0.2.99']]],
                ['name' => '_acme-challenge.old.g.example.com.', 'type' => 'TXT', 'ttl' => 60, 'records' => [['content' => '"old"']]],
            ],
        ];

        app(GlobalZoneSync::class)->sync();

        $us = $this->patched('us-1.test');
        $this->assertSame('REPLACE', $this->rrset($us, '_acme-challenge.g.example.com.', 'TXT')['changetype']);
        $this->assertSame('"dns01-token"', $this->rrset($us, '_acme-challenge.g.example.com.', 'TXT')['records'][0]['content']);
        $this->assertSame('DELETE', $this->rrset($us, '_acme-challenge.old.g.example.com.', 'TXT')['changetype']);
        $this->assertSame('DELETE', $this->rrset($us, 'stale.g.example.com.', 'A')['changetype']);

        // the update server's own challenges are cert-manager's: never touched
        $this->assertNull($this->rrset($this->patched('eu-1.test'), '_acme-challenge.g.example.com.', 'TXT'));

        $this->assertSame('drift', RegionGlobalDns::forRegion($this->us)->sync_status);
        $this->assertSame('in_sync', RegionGlobalDns::forRegion($this->eu)->sync_status);
    }

    public function test_every_region_gets_its_health_route(): void
    {
        $this->zones = ['eu-1.test' => [], 'us-1.test' => []];

        app(GlobalZoneSync::class)->sync();

        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $route = collect($this->calls)->first(fn ($c) => $c['host'] === $host && $c['method'] === 'PATCH' && str_ends_with($c['path'], '/ingressroutes/runr-global-health'));
            $this->assertNotNull($route, "health route on {$host}");
            $this->assertSame('Host(`runr-health.g.example.com`) && PathPrefix(`/global-health/`)', $route['body']['spec']['routes'][0]['match']);
        }

        // no cert-manager in the regions: the wildcard waits, the reason is shown
        $this->assertSame('error', RegionGlobalDns::forRegion($this->eu)->tls_status);
    }

    public function test_a_stopped_project_leaves_the_zone(): void
    {
        $project = $this->globalProject();
        $project->forceFill(['stopped_at' => now()])->saveQuietly();
        $this->zones = ['eu-1.test' => [['name' => 'shop.g.example.com.', 'type' => 'LUA', 'ttl' => 30, 'records' => [['content' => 'A "x"']]]], 'us-1.test' => []];

        app(GlobalZoneSync::class)->sync();

        $this->assertSame('DELETE', $this->rrset($this->patched('eu-1.test'), 'shop.g.example.com.', 'LUA')['changetype']);
        $this->assertNull($this->rrset($this->patched('us-1.test'), 'shop.g.example.com.', 'LUA'));
    }
}
