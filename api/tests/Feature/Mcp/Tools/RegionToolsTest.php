<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\KnativeRegion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** regions_list and region_status (region resource + component status routes). */
class RegionToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    public function test_regions_are_listed_concisely(): void
    {
        KnativeRegion::create(['name' => 'eu-west', 'project_namespace' => 'fn', 'is_active' => true, 'knative_settings' => ['knative_domain' => 'eu.runr.test']]);

        $result = $this->structured($this->callTool('regions_list'));

        $names = array_column($result['regions'], 'name');
        $this->assertEqualsCanonicalizing(['op', 'eu-west'], $names);
        $eu = collect($result['regions'])->firstWhere('name', 'eu-west');
        $this->assertSame('eu.runr.test', $eu['function_domain']);
        $this->assertArrayNotHasKey('kubeconfig', $eu);
    }

    public function test_the_status_of_a_region_by_name(): void
    {
        $region = KnativeRegion::create([
            'name' => 'eu-west', 'project_namespace' => 'fn', 'is_active' => true,
            'knative_serving_status' => 'ready', 'knative_operator_status' => 'ready',
        ]);

        $result = $this->structured($this->callTool('region_status', ['region' => 'eu-west']));

        $this->assertSame($region->id, $result['region']['id']);
        $this->assertArrayHasKey('components', $result['region']);
        $this->assertSame('ready', $result['region']['components']['operator']['status']);
    }

    public function test_region_errors_and_permissions(): void
    {
        $this->mcpToolError($this->callTool('region_status', ['region' => 'mars']));
        $this->assertSchemaRejects('region_status', [], '/region');
        $this->assertSchemaRejects('regions_list', ['all' => true], '/all');

        $this->customRole('builder', ['projects.view', 'tokens.manage']);
        $builder = $this->userWithRole('builder');
        $this->assertToolHidden('regions_list', ['*'], [], $builder);
        $this->assertToolHidden('region_status', ['*'], ['region' => 'op'], $builder);
    }
}
