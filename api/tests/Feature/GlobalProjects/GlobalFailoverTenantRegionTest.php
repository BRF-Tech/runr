<?php

namespace Tests\Feature\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\GlobalProjects\GlobalFailover;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The health check of a global project's regions (GlobalFailover, a GET of /global-health/<slug>
 * on the region Traefik's address): an organization's own region reports that address from its
 * own cluster, so RunR asks it only at an address it may reach for such a region (a public one,
 * TenantKubeconfigs::mayReach()) and follows no redirect from it. Platform regions are asked as
 * before.
 */
class GlobalFailoverTenantRegionTest extends TestCase
{
    /** @var list<array{url: string, redirects: mixed}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['global_dns.zone' => 'g.example.com']);
        Http::fake(function (Request $request, array $options) {
            $this->requests[] = ['url' => $request->url(), 'redirects' => $options['allow_redirects'] ?? null];

            return Http::response('ok');
        });
    }

    private function row(string $id, string $address, bool $tenant): ProjectRegion
    {
        $region = (new KnativeRegion)->forceFill(['id' => "region-{$id}", 'name' => "r-{$id}", 'external_ip' => $address]);
        if ($tenant) {
            $region->forceFill(['owner_tenant_id' => 'acme']);
        }

        return (new ProjectRegion)->forceFill(['id' => $id])->setRelation('knativeRegion', $region);
    }

    /**
     * @param  list<ProjectRegion>  $rows
     * @return array<string, bool>
     */
    private function health(array $rows): array
    {
        $project = (new Project)->forceFill(['slug' => 'shop']);

        return (fn () => $this->healthOf($project, $rows, false))->call(app(GlobalFailover::class));
    }

    public function test_an_organizations_region_is_asked_at_a_public_address_only_and_without_redirects(): void
    {
        $health = $this->health([
            $this->row('internal', '10.0.0.5', tenant: true),
            $this->row('metadata', '169.254.169.254', tenant: true),
            $this->row('public', '203.0.113.10', tenant: true),
            $this->row('platform', '10.0.0.6', tenant: false),
        ]);

        $this->assertSame(['internal' => false, 'metadata' => false, 'public' => true, 'platform' => true], [
            'internal' => $health['internal'], 'metadata' => $health['metadata'], 'public' => $health['public'], 'platform' => $health['platform'],
        ]);
        $urls = array_column($this->requests, 'redirects', 'url');
        $this->assertSame(['http://203.0.113.10/global-health/shop', 'http://10.0.0.6/global-health/shop'], array_keys($urls));
        $this->assertFalse($urls['http://203.0.113.10/global-health/shop'], 'no redirect of the organization\'s Traefik is followed');
        $this->assertNotFalse($urls['http://10.0.0.6/global-health/shop'], 'a platform region as before');
    }

    public function test_the_operators_flag_lets_private_addresses_of_organizations_be_asked(): void
    {
        config(['tenancy.region_kubeconfig.allow_private_servers' => true]);

        $health = $this->health([$this->row('internal', '10.0.0.5', tenant: true), $this->row('loopback', '127.0.0.1', tenant: true)]);

        $this->assertTrue($health['internal']);
        $this->assertFalse($health['loopback']);
        $this->assertSame(['http://10.0.0.5/global-health/shop'], array_column($this->requests, 'url'));
    }
}
