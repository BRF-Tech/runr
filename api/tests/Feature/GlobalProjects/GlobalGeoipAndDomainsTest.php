<?php

namespace Tests\Feature\GlobalProjects;

use App\Http\Resources\ProjectDomainResource;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Models\Setting;
use App\Models\Version;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalProjects\GlobalDomains;
use App\Services\ProjectDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * The GeoIP source of a region's PowerDNS server for clusters without internet access, and the
 * custom domains of a global project in every region (docs/global-projects.md).
 */
class GlobalGeoipAndDomainsTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    /** @var list<array{host: string, method: string, path: string, body: array, query: string}> */
    private array $calls = [];

    /** @var array<string, array|string> "host METHOD path" => body */
    private array $responses = [];

    private KnativeRegion $eu;

    private KnativeRegion $us;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Setting::set('base_domain', 'example.com');

        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['host' => $host, 'method' => $request->method(), 'path' => $path, 'body' => json_decode($request->body(), true) ?? [], 'query' => (string) parse_url($request->url(), PHP_URL_QUERY)];

            $key = "{$host} {$request->method()} {$path}";
            if (isset($this->responses[$key])) {
                return Http::response($this->responses[$key]);
            }

            return $request->method() === 'GET'
                ? Http::response(['kind' => 'Status', 'code' => 404], 404)
                : Http::response([], 200);
        });

        $this->eu = $this->region('eu-1', '203.0.113.10');
        $this->us = $this->region('us-1', '198.51.100.10');
    }

    private function region(string $name, string $ip): KnativeRegion
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
        RegionGlobalDns::query()->create(['knative_region_id' => $region->id, 'status' => RegionGlobalDns::STATUS_READY, 'address' => '10.0.0.'.random_int(2, 250), 'api_key' => 'k', 'tls_status' => 'ready']);

        return $region;
    }

    // ── GeoIP ───────────────────────────────────────────────────────────────

    public function test_an_operator_url_from_the_region_object_store_becomes_the_server_source(): void
    {
        $url = 'http://runr-objects.runr-data.svc.cluster.local:3900/geoip/dbip-city-lite.mmdb.gz?X-Amz-Signature=abc';

        $this->signedIn($this->userWithRole('owner'))->putJson("/api/knative-regions/{$this->eu->id}/global-dns/geoip", ['source' => 'url', 'url' => $url])
            ->assertOk()
            ->assertJsonPath('geoip.source', 'url')
            ->assertJsonPath('geoip.url', $url)
            ->assertJsonPath('geoip.status', 'unknown');

        $deployment = collect($this->calls)->last(fn ($c) => $c['host'] === 'eu-1.test' && $c['method'] === 'PATCH' && str_ends_with($c['path'], '/deployments/runr-pdns'));
        $env = array_column($deployment['body']['spec']['template']['spec']['initContainers'][0]['env'], 'value', 'name');
        $this->assertSame(['GEOIP_MODE' => 'url', 'GEOIP_URL' => $url], $env);

        // the DNS namespace may reach the region's managed object store
        $policy = collect($this->calls)->last(fn ($c) => $c['method'] === 'PATCH' && str_ends_with($c['path'], '/namespaces/runr-data/networkpolicies/runr-global-dns-geoip'));
        $this->assertSame(['app.kubernetes.io/name' => 'runr-objects'], $policy['body']['spec']['podSelector']['matchLabels']);
        $this->assertSame(3900, $policy['body']['spec']['ingress'][0]['ports'][0]['port']);
    }

    public function test_a_file_on_the_volume_needs_no_download_and_url_is_required_for_url(): void
    {
        $owner = $this->userWithRole('owner');
        $this->signedIn($owner)->putJson("/api/knative-regions/{$this->eu->id}/global-dns/geoip", ['source' => 'url'])
            ->assertStatus(422)->assertJsonValidationErrors('url');

        $this->signedIn($owner)->putJson("/api/knative-regions/{$this->eu->id}/global-dns/geoip", ['source' => 'file'])->assertOk();
        $this->assertSame(['file', ''], GlobalDnsServerService::geoipSource(RegionGlobalDns::forRegion($this->eu)));

        $this->signedIn($owner)->postJson("/api/knative-regions/{$this->eu->id}/global-dns/geoip/reload")->assertStatus(202);
        $reload = collect($this->calls)->last(fn ($c) => $c['method'] === 'PATCH' && str_ends_with($c['path'], '/deployments/runr-pdns'));
        $this->assertArrayHasKey('runr.dev/geoip-reload', $reload['body']['spec']['template']['metadata']['annotations']);

        // the platform default, and "-" for none
        config(['global_dns.geoip.url' => '-']);
        $this->assertSame(['none', ''], GlobalDnsServerService::geoipSource(null));
    }

    public function test_the_console_reads_the_state_the_init_container_reported(): void
    {
        $this->responses['eu-1.test GET /api/v1/namespaces/runr-global-dns/pods'] = ['items' => [
            ['metadata' => ['name' => 'runr-pdns-old', 'creationTimestamp' => '2026-10-01T00:00:00Z']],
            ['metadata' => ['name' => 'runr-pdns-new', 'creationTimestamp' => '2026-10-22T00:00:00Z']],
        ]];
        $this->responses['eu-1.test GET /api/v1/namespaces/runr-global-dns/pods/runr-pdns-new/log'] = "zone database created\nGEOIP_STATUS {\"error\": \"http://mirror/geo.mmdb.gz: timed out\", \"mode\": \"url\", \"source\": \"http://mirror/geo.mmdb.gz\", \"status\": \"missing\"}\n";

        $this->signedIn($this->userWithRole('owner'))->getJson("/api/knative-regions/{$this->eu->id}/global-dns")
            ->assertOk()
            ->assertJsonPath('geoip.status', 'missing')
            ->assertJsonPath('geoip.source', 'default');

        $this->assertStringContainsString('timed out', (string) RegionGlobalDns::forRegion($this->eu)->geoip_message);
        $log = collect($this->calls)->first(fn ($c) => str_ends_with($c['path'], '/runr-pdns-new/log'));
        $this->assertStringContainsString('container=prepare', $log['query']);
    }

    // ── Custom domains ──────────────────────────────────────────────────────

    private function globalProject(): Project
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => 'ha'])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev']);
        foreach ([[$this->eu, 0], [$this->us, 1]] as [$region, $position]) {
            ProjectRegion::query()->create(['project_id' => $project->id, 'knative_region_id' => $region->id, 'position' => $position, 'status' => 'ready', 'served_version_id' => $version->id, 'deployment_id' => $deployment->id]);
        }

        return $project->fresh();
    }

    public function test_a_global_project_domain_points_at_the_global_host_name(): void
    {
        $targets = app(ProjectDomainService::class)->dnsTargets($this->globalProject());

        $this->assertSame([
            ['type' => 'CNAME', 'value' => 'shop.g.example.com'],
            ['type' => 'A', 'value' => '203.0.113.10'],
            ['type' => 'A', 'value' => '198.51.100.10'],
        ], $targets);
    }

    public function test_every_serving_region_routes_and_certifies_the_domain(): void
    {
        $project = $this->globalProject();
        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $this->responses["{$host} GET /apis/cert-manager.io/v1/namespaces/fn/issuers/runr-global-dns"] = ['metadata' => ['name' => 'runr-global-dns']];
        }
        $domain = $project->domains()->create(['domain' => 'shop.acme.test', 'status' => ProjectDomain::STATUS_VERIFIED, 'verification_token' => 't', 'tls_mode' => ProjectDomain::TLS_CERT_MANAGER]);
        $domain->setRelation('project', $project);

        $this->assertTrue(app(ProjectDomainService::class)->activate($domain));

        foreach (['eu-1.test', 'us-1.test'] as $host) {
            $certificate = collect($this->calls)->last(fn ($c) => $c['host'] === $host && $c['method'] === 'PATCH' && str_contains($c['path'], '/certificates/'));
            $this->assertNotNull($certificate, "certificate in {$host}");
            $this->assertSame(['name' => 'runr-global-dns', 'kind' => 'Issuer', 'group' => 'cert-manager.io'], $certificate['body']['spec']['issuerRef']);
            $route = collect($this->calls)->last(fn ($c) => $c['host'] === $host && $c['method'] === 'PATCH' && str_contains($c['path'], '/ingressroutes/'));
            $this->assertNotNull($route, "route in {$host}");
        }

        $domain->refresh();
        $this->assertSame(ProjectDomain::STATUS_ACTIVE, $domain->status);
        $this->assertEqualsCanonicalizing([$this->eu->id, $this->us->id], array_keys($domain->region_states));

        $resource = (new ProjectDomainResource($domain->setRelation('project', $project)))->resolve();
        $target = app(GlobalDomains::class)->challengeTarget('shop.acme.test');
        $this->assertSame(['type' => 'CNAME', 'name' => '_acme-challenge.shop.acme.test', 'value' => $target], $resource['acme_challenge']);
        $this->assertStringStartsWith('_acme-challenge.d-', (string) $target);
        $this->assertStringEndsWith('.g.example.com', (string) $target);
    }

    public function test_a_region_that_leaves_takes_the_domain_objects_with_it(): void
    {
        $project = $this->globalProject();
        $domain = $project->domains()->create(['domain' => 'shop.acme.test', 'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 't', 'tls_mode' => ProjectDomain::TLS_NONE, 'region_states' => [$this->us->id => ['status' => 'active']]]);

        $this->assertTrue(app(GlobalDomains::class)->removeRegion($project, $this->us));

        $this->assertNotEmpty(array_filter($this->calls, fn ($c) => $c['host'] === 'us-1.test' && $c['method'] === 'DELETE' && str_contains($c['path'], '/ingressroutes/')));
        $this->assertNull($domain->fresh()->region_states);
    }
}
