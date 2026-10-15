<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\Dns\DnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * domains_list, domain_add, domain_verify, domain_remove — the custom domain routes with a fake
 * DNS resolver and a fake region cluster (as in ProjectDomainApiTest).
 */
class DomainToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private const DOMAIN = 'shop.example.test';

    private object $dns;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        $this->dns = new class extends DnsResolver
        {
            public array $txtRecords = [];

            public array $cnameRecords = [];

            public function txt(string $name): array
            {
                return $this->txtRecords[$name] ?? [];
            }

            public function cname(string $name): array
            {
                return $this->cnameRecords[$name] ?? [];
            }

            public function addresses(string $name): array
            {
                return [];
            }
        };
        $this->app->instance(DnsResolver::class, $this->dns);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/ingressroutes') => Http::response(['items' => []]),
                $request->method() === 'GET' => Http::response(['kind' => 'Status', 'code' => 404], 404),
                default => Http::response([], 200),
            };
        });

        $region = KnativeRegion::create([
            'name' => 'eu',
            'project_namespace' => 'fn',
            'kubeconfig' => Yaml::dump([
                'apiVersion' => 'v1', 'kind' => 'Config', 'current-context' => 'c',
                'clusters' => [['name' => 'c', 'cluster' => ['server' => 'https://region.test:6443', 'insecure-skip-tls-verify' => true]]],
                'users' => [['name' => 'u', 'user' => ['token' => 'admin']]],
                'contexts' => [['name' => 'c', 'context' => ['cluster' => 'c', 'user' => 'u']]],
            ], 10, 2),
            'knative_serving_namespace' => 'knative-serving',
            'external_ip' => '203.0.113.10',
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
            'is_active' => true,
        ]);
        $this->project = $this->containerProject('shop', [], $region);
    }

    private function publishDns(ProjectDomain $domain): void
    {
        $this->dns->txtRecords[$domain->challengeName()] = [$domain->challengeValue()];
        $this->dns->cnameRecords[$domain->domain] = ['shop.eu.runr.test'];
    }

    public function test_a_domain_is_added_with_its_dns_records_and_listed(): void
    {
        $added = $this->structured($this->callTool('domain_add', ['project' => 'shop', 'domain' => self::DOMAIN]));

        $this->assertSame(self::DOMAIN, $added['domain']['domain']);
        $this->assertSame('pending', $added['domain']['status']);
        $this->assertSame('TXT', $added['domain']['verification']['type']);
        $this->assertNotEmpty($added['domain']['dns_records']);

        $list = $this->structured($this->callTool('domains_list', ['project' => 'shop']));
        $this->assertSame([self::DOMAIN], array_column($list['domains'], 'domain'));
        $this->assertTrue($list['eligible']);
    }

    public function test_verification_reports_missing_records_and_activates_once_they_exist(): void
    {
        $this->callTool('domain_add', ['project' => 'shop', 'domain' => self::DOMAIN]);
        $domain = ProjectDomain::sole();

        $pending = $this->structured($this->callTool('domain_verify', ['project' => 'shop', 'domain' => self::DOMAIN]));
        $this->assertFalse($pending['verified']);
        $this->assertNotSame('', $pending['message']);

        $this->publishDns($domain);
        $active = $this->structured($this->callTool('domain_verify', ['project' => 'shop', 'domain' => $domain->id]));
        $this->assertTrue($active['verified']);
        $this->assertSame(ProjectDomain::STATUS_ACTIVE, $domain->fresh()->status);
    }

    public function test_a_domain_is_removed_with_confirmation(): void
    {
        $this->callTool('domain_add', ['project' => 'shop', 'domain' => self::DOMAIN]);

        $this->assertSchemaRejects('domain_remove', ['project' => 'shop', 'domain' => self::DOMAIN], '/confirm');
        $result = $this->structured($this->callTool('domain_remove', ['project' => 'shop', 'domain' => self::DOMAIN, 'confirm' => true]));

        $this->assertSame(self::DOMAIN, $result['removed']['domain']);
        $this->assertSame(0, ProjectDomain::count());
    }

    public function test_domain_validation(): void
    {
        $this->assertSchemaRejects('domain_add', ['project' => 'shop', 'domain' => 'https://shop.example.test/path'], '/domain');
        $this->mcpToolError($this->callTool('domain_verify', ['project' => 'shop', 'domain' => 'unknown.example.test']));

        // The route's rules: a host of the region's own function domain is taken
        $error = $this->mcpToolError($this->callTool('domain_add', ['project' => 'shop', 'domain' => 'x.eu.runr.test']));
        $this->assertSame(422, $error['status']);
    }

    public function test_domain_permissions(): void
    {
        $viewer = $this->userWithRole('viewer');

        $listed = $this->structured($this->callTool('domains_list', ['project' => 'shop'], $viewer));
        $this->assertSame([], $listed['domains']);

        foreach (['domain_add', 'domain_verify', 'domain_remove'] as $tool) {
            $this->assertToolHidden($tool, ['*'], ['project' => 'shop', 'domain' => self::DOMAIN, 'confirm' => true], $viewer);
        }
        $this->assertToolHidden('domains_list', ['regions.view']);
    }
}
