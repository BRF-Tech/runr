<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Models\RegionGlobalDns;
use App\Models\Setting;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * project_global_get, project_global_update, project_global_failover and global_dns_status
 * (docs/global-projects.md): the failover setting, a failover of the primary, the regions' trigger
 * and data state, the servers' GeoIP state.
 */
class GlobalProjectToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private KnativeRegion $eu;

    private KnativeRegion $us;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        Queue::fake();
        Http::fake(fn () => Http::response(['kind' => 'Status', 'code' => 404], 404));
        Setting::set('base_domain', 'example.com');
        $this->eu = $this->globalRegion('eu-1', '203.0.113.10');
        $this->us = $this->globalRegion('us-1', '198.51.100.10');
    }

    private function globalRegion(string $name, string $ip): KnativeRegion
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
        RegionGlobalDns::query()->create([
            'knative_region_id' => $region->id, 'status' => RegionGlobalDns::STATUS_READY, 'address' => '10.0.0.'.random_int(2, 250),
            'geoip_source' => $name === 'us-1' ? 'file' : null, 'geoip_status' => $name === 'us-1' ? 'missing' : 'loaded',
        ]);

        return $region;
    }

    /** A global project in eu-1 (primary) and us-1, both serving its active version. */
    private function globalProject(): Project
    {
        $project = Project::create(['name' => 'shop', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->eu->id, 'placement' => 'global', 'global_mode' => 'ha'])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'ghcr.io/acme/shop:1.0', 'trigger_type' => 'http', 'cpu' => '250m', 'memory_mb' => 128]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active', 'deployed_by' => 'dev']);
        foreach ([[$this->eu, 0, true], [$this->us, 1, false]] as [$region, $position, $triggers]) {
            ProjectRegion::query()->create([
                'project_id' => $project->id, 'knative_region_id' => $region->id, 'position' => $position, 'status' => 'ready',
                'served_version_id' => $version->id, 'deployment_id' => $deployment->id, 'triggers_active' => $triggers,
            ]);
        }

        return $project->fresh();
    }

    public function test_the_global_object_names_the_failover_triggers_and_data(): void
    {
        $project = $this->globalProject();

        $result = $this->callTool('project_global_get', ['project' => $project->id]);
        $global = $this->structured($result)['global'];

        $this->assertSame('automatic', $global['failover']);
        $this->assertNull($global['moving_to']);
        $this->assertSame([], $global['bindings']);
        $this->assertTrue($global['regions'][0]['triggers_active']);
        $this->assertStringContainsString('failover automatic', $result['content'][0]['text']);
        $this->assertStringContainsString('eu-1 (primary): ready, v1, in DNS, triggers', $result['content'][0]['text']);
    }

    public function test_the_failover_setting_changes_and_needs_a_confirmation(): void
    {
        $project = $this->globalProject();

        $this->assertSchemaRejects('project_global_update', ['project' => $project->id, 'failover' => 'manual'], '/confirm');
        $this->assertSchemaRejects('project_global_update', ['project' => $project->id, 'failover' => 'sometimes', 'confirm' => true], '/failover');

        $global = $this->structured($this->callTool('project_global_update', ['project' => $project->id, 'failover' => 'manual', 'confirm' => true]))['global'];

        $this->assertSame('manual', $global['failover']);
        $this->assertSame('manual', $project->fresh()->globalFailover());
    }

    public function test_a_failover_moves_the_primary_and_fences_the_old_one(): void
    {
        $project = $this->globalProject();

        $this->assertSchemaRejects('project_global_failover', ['project' => $project->id], '/confirm');
        $this->mcpToolError($this->callTool('project_global_failover', ['project' => $project->id, 'region' => 'eu-1', 'confirm' => true]));

        $result = $this->callTool('project_global_failover', ['project' => $project->id, 'region' => 'us-1', 'confirm' => true]);
        $global = $this->structured($result)['global'];

        $this->assertSame($this->us->id, $global['primary_region_id']);
        $this->assertSame($this->us->id, $project->fresh()->knative_region_id);
        $this->assertSame('fenced', collect($global['regions'])->firstWhere('region_id', $this->eu->id)['status']);
        $this->assertStringContainsString('the primary is us-1', $result['content'][0]['text']);
    }

    public function test_a_failover_needs_projects_update(): void
    {
        $project = $this->globalProject();

        $this->customRole('watcher', ['projects.view', 'tokens.manage']);
        $watcher = $this->userWithRole('watcher');
        $this->assertToolHidden('project_global_failover', ['*'], ['project' => $project->id, 'confirm' => true], $watcher);
    }

    public function test_the_global_dns_status_names_the_geoip_state_of_every_server(): void
    {
        $result = $this->callTool('global_dns_status');
        $dns = $this->structured($result);

        $servers = collect($dns['servers'])->keyBy('region_name');
        $this->assertSame('loaded', $servers['eu-1']['geoip']['status']);
        $this->assertSame('default', $servers['eu-1']['geoip']['source']);
        $this->assertSame('file', $servers['us-1']['geoip']['source']);
        $this->assertStringContainsString('GeoIP: eu-1 loaded (default), us-1 missing (file).', $result['content'][0]['text']);
    }
}
