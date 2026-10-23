<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Project;
use App\Models\RightsizingRecommendation;
use App\Services\GitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rightsizing_list (the organization's open recommendations, or one project's state) and
 * rightsizing_apply (the recommended size into the config through the API, like project_update).
 */
class RightsizingToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    /** The shop (CaaS, 250m / 128 MiB) could run on 125m. */
    private function recommended(Project $project, array $attributes = []): RightsizingRecommendation
    {
        return RightsizingRecommendation::create($attributes + [
            'project_id' => $project->id,
            'region_id' => $project->knative_region_id,
            'status' => RightsizingRecommendation::OPEN,
            'direction' => 'down',
            'current_cpu_m' => 250,
            'current_memory_mb' => 128,
            'recommended_cpu_m' => 125,
            'recommended_memory_mb' => 128,
            'reasons' => ['cpu_over_provisioned'],
            'evidence' => ['days' => 14, 'changes' => ['cpu' => 'down', 'memory' => 'keep'], 'freed' => ['cpu_m' => 125, 'memory_mb' => 0, 'pods' => 1, 'always_on' => false]],
            'savings' => ['basis' => 'usage', 'monthly' => 0.0, 'currency' => 'USD'],
            'monthly_savings' => 0.0,
            'currency' => 'USD',
            'computed_at' => now(),
        ]);
    }

    public function test_the_organization_list(): void
    {
        $shop = $this->containerProject('shop');
        $this->recommended($shop);

        $result = $this->structured($this->callTool('rightsizing_list'));

        $this->assertCount(1, $result['recommendations']);
        $this->assertSame('shop', $result['recommendations'][0]['project']);
        $this->assertSame(['cpu_m' => 125, 'memory_mb' => 128], $result['recommendations'][0]['recommended']);
        $this->assertSame(['cpu_over_provisioned'], $result['recommendations'][0]['reasons']);
        $this->assertSame(1, $result['summary']['count']);
    }

    public function test_one_project(): void
    {
        $shop = $this->containerProject('shop');
        $this->recommended($shop);
        $orders = $this->faasProject('orders');
        $this->deployment($orders, $this->version($orders));

        $open = $this->structured($this->callTool('rightsizing_list', ['project' => 'shop']));
        $this->assertSame('open', $open['status']);
        $this->assertSame(['cpu_m' => 250, 'memory_mb' => 128], $open['recommendation']['current']);

        // a project of a demo region: no metrics to read
        $none = $this->structured($this->callTool('rightsizing_list', ['project' => 'orders']));
        $this->assertSame('not_eligible', $none['status']);
        $this->assertSame('no_metrics', $none['reason']);
        $this->assertNull($none['recommendation']);
    }

    public function test_apply_saves_a_version_to_deploy(): void
    {
        $this->mock(GitService::class, fn ($git) => $git->shouldReceive('writeFiles')->andReturn('sha'));
        $shop = $this->containerProject('shop');
        $this->version($shop, 1);
        $this->recommended($shop);

        $result = $this->structured($this->callTool('rightsizing_apply', ['project' => 'shop']));

        $this->assertSame('v2', $result['version']['label']);
        $this->assertTrue($result['deploy_required']);
        $this->assertSame('applied', $result['recommendation']['status']);
        $this->assertSame('125m', $shop->containerConfig->fresh()->cpu);

        // nothing left to apply
        $again = $this->callTool('rightsizing_apply', ['project' => 'shop']);
        $this->assertTrue($again['isError'] ?? false);
    }

    public function test_the_tools_need_their_permissions(): void
    {
        $this->assertToolHidden('rightsizing_list', ['projects.view']);
        $this->assertToolHidden('rightsizing_apply', ['projects.view', 'metrics.view'], ['project' => 'shop']);
    }
}
