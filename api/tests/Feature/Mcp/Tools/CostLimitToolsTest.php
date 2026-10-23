<?php

namespace Tests\Feature\Mcp\Tools;

use App\Mcp\Tools\ToolContext;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\CostLimit;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * project_cost_limit_get / project_cost_limit_set (Billing module, docs/billing.md#monthly-cost-limit):
 * the tools exist while billing is on and act through the project billing API — the month so far
 * and the forecast, setting and removing the limit, a "stop" limit the month already passed only
 * with stop_now, and the resume of a project the limit stopped.
 */
class CostLimitToolsTest extends TestCase
{
    use EnablesBilling, McpToolFixtures, RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->setUpBilling();
        $this->setUpMcpTools();

        // The tools' owner owns the project; the region is pay as you go (no setting)
        $this->project = $this->billedProject('orders', $this->region, $this->owner);
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        ToolContext::sleepUsing(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Usage worth $amount (the seeded 0.04 per vCPU-hour: 25 vCPU-hours = 1.00). */
    private function spend(float $amount): void
    {
        $this->usageDay($this->project, '2026-10-05', $amount * 25);
    }

    public function test_the_tools_are_listed_while_billing_is_on(): void
    {
        [$token, $session] = $this->connect();
        $names = $this->mcpToolNames($token, $session);

        $this->assertContains('project_cost_limit_get', $names);
        $this->assertContains('project_cost_limit_set', $names);
        $this->assertToolHidden('project_cost_limit_get', ['billing.view'], ['project' => $this->project->id]);
    }

    public function test_get_shows_the_month_so_far_and_the_forecast(): void
    {
        $this->spend(5);

        $result = $this->callTool('project_cost_limit_get', ['project' => $this->project->id]);
        $status = $this->structured($result);
        $this->assertTrue($status['available']);
        $this->assertNull($status['limit']);
        $this->assertEqualsWithDelta(5.0, $status['month_to_date'], 0.001);
        $this->assertEqualsWithDelta(7.75, $status['forecast'], 0.001);
        $this->assertStringContainsString('No cost limit set', $result['content'][0]['text']);
    }

    public function test_set_changes_and_removes_the_limit(): void
    {
        $saved = $this->structured($this->callTool('project_cost_limit_set', ['project' => $this->project->id, 'amount' => 25, 'action' => 'notify']));
        $this->assertEqualsWithDelta(25.0, $saved['cost_limit']['limit']['amount'], 0.001);
        $this->assertSame('notify', $saved['cost_limit']['limit']['action']);
        $this->assertSame(1, CostLimit::query()->count());

        $this->structured($this->callTool('project_cost_limit_set', ['project' => $this->project->id, 'remove' => true]));
        $this->assertSame(0, CostLimit::query()->count());

        // Nothing to do / contradicting arguments / outside the schema
        $this->mcpToolError($this->callTool('project_cost_limit_set', ['project' => $this->project->id]));
        $this->mcpToolError($this->callTool('project_cost_limit_set', ['project' => $this->project->id, 'amount' => 5, 'remove' => true]));
        $this->assertSchemaRejects('project_cost_limit_set', ['project' => $this->project->id, 'amount' => 0], '/amount');
        $this->assertSchemaRejects('project_cost_limit_set', ['project' => $this->project->id, 'amount' => 5, 'action' => 'scale_down'], '/action');
    }

    public function test_a_stop_limit_the_month_already_passed_needs_stop_now_and_the_project_is_resumed_after_removing_it(): void
    {
        $this->spend(12);

        $refused = $this->callTool('project_cost_limit_set', ['project' => $this->project->id, 'amount' => 10, 'action' => 'stop']);
        $this->mcpToolError($refused);
        $this->assertStringContainsString('stop_now', $refused['content'][0]['text']);
        $this->assertSame(0, CostLimit::query()->count());
        $this->assertFalse($this->project->fresh()->isStopped());

        $stopped = $this->structured($this->callTool('project_cost_limit_set', ['project' => $this->project->id, 'amount' => 10, 'action' => 'stop', 'stop_now' => true]));
        $this->assertTrue($stopped['cost_limit']['stopped']);
        $this->assertTrue($this->project->fresh()->isStopped());

        // Still at the limit: the resume is refused
        $error = $this->mcpToolError($this->callTool('project_cost_limit_set', ['project' => $this->project->id, 'resume' => true]));
        $this->assertSame(409, $error['status']);
        $this->assertTrue($this->project->fresh()->isStopped());

        $resumed = $this->structured($this->callTool('project_cost_limit_set', ['project' => $this->project->id, 'remove' => true, 'resume' => true]));
        $this->assertNull($resumed['cost_limit']['limit']);
        $this->assertFalse($resumed['cost_limit']['stopped']);
        $this->assertArrayHasKey('resume', $resumed);
        $this->assertFalse($this->project->fresh()->isStopped());
    }

    public function test_a_fixed_price_project_has_no_limit(): void
    {
        $fixed = $this->billedProject('shop', $this->billingRegion('fx', 'fixed'), $this->owner);

        $result = $this->callTool('project_cost_limit_get', ['project' => $fixed->id]);
        $this->assertFalse($this->structured($result)['available']);
        $this->assertStringContainsString('No cost limit', $result['content'][0]['text']);

        $error = $this->mcpToolError($this->callTool('project_cost_limit_set', ['project' => $fixed->id, 'amount' => 10, 'action' => 'notify']));
        $this->assertSame(422, $error['status']);
    }
}
