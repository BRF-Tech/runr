<?php

namespace Tests\Feature\Workflows;

use App\Models\User;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowTrigger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

/**
 * Where a definition comes from survives a declaration through the API (docs/workflows.md):
 * re-declaring the set (runr workflows declare, PUT /projects/{project}/workflows) keeps the
 * source of a definition whose content does not change — what the function's SDK reported stays
 * `sdk` —, and one definition is declared or archived on its own (PUT / DELETE
 * /projects/{project}/workflows/{workflow}: runr_workflow) without touching the others.
 */
class WorkflowDeclareSourceTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    private const ORDERS = [
        'description' => 'Order pipeline',
        'triggers' => [['event' => 'order.created', 'if' => 'event.data.total > 0'], ['cron' => '*/10 * * * *', 'timezone' => 'Europe/Istanbul']],
        'retries' => 2,
        'concurrency' => ['limit' => 5, 'key' => 'event.data.customer_id'],
        'cancel_on' => [['event' => 'order.cancelled', 'match' => 'data.id']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->actingAs(User::factory()->create(), 'sanctum');

        // the function's SDK reports two workflows
        $this->fn->define('orders', fn () => 1, self::ORDERS)->define('cleanup', fn () => 1, ['triggers' => [['cron' => '0 3 * * *']]]);
        app(WorkflowRegistry::class)->sync($this->project);
        $this->travel(5)->minutes();
    }

    private function base(): string
    {
        return "/api/projects/{$this->project->id}/workflows";
    }

    private function workflow(string $name): Workflow
    {
        return Workflow::query()->where('project_id', $this->project->id)->where('name', $name)->firstOrFail();
    }

    /**
     * A definition as the CLI and the Terraform provider send it back (runr.Workflow.Definition()
     * of the Go client): built from the API's answer — the stored triggers, the retries, the
     * limits, cancel_on's expression as "if" without its enclosing parentheses.
     */
    private function asSentBack(array $w): array
    {
        $def = ['name' => $w['name'], 'triggers' => $w['triggers'], 'retries' => $w['retries']];
        if ($w['description'] !== null) {
            $def['description'] = $w['description'];
        }
        foreach (['timeout', 'idempotency'] as $field) {
            if ($w[$field] !== null) {
                $def[$field] = $w[$field];
            }
        }
        if ($w['concurrency'] !== []) {
            $def['concurrency'] = array_map(fn ($l) => array_filter($l, fn ($v) => $v !== null), $w['concurrency']);
        }
        if ($w['cancel_on'] !== []) {
            $def['cancel_on'] = array_map(fn ($c) => ['event' => $c['event']] + (isset($c['expression']) ? ['if' => substr($c['expression'], 1, -1)] : []), $w['cancel_on']);
        }

        return $def;
    }

    public function test_an_sdk_definition_stays_sdk_when_the_cli_declares_the_set(): void
    {
        $orders = $this->workflow('orders');
        $this->assertSame('sdk', $orders->source);
        $syncedAt = $orders->synced_at;

        // runr workflows declare -f runr.workflows.json: the code's two definitions, cleanup changed, report new
        $this->putJson($this->base(), ['workflows' => [
            ['name' => 'orders'] + self::ORDERS,
            ['name' => 'cleanup', 'triggers' => [['cron' => '0 4 * * *']]],
            ['name' => 'report', 'triggers' => [['event' => 'report.requested']]],
        ]])->assertOk()
            ->assertJsonPath('data.1.name', 'orders')->assertJsonPath('data.1.source', 'sdk')
            ->assertJsonPath('data.0.name', 'cleanup')->assertJsonPath('data.0.source', 'api')
            ->assertJsonPath('data.2.name', 'report')->assertJsonPath('data.2.source', 'api');

        $this->assertSame('sdk', $this->workflow('orders')->source, 'unchanged: the code still owns it');
        $this->assertTrue($syncedAt->equalTo($this->workflow('orders')->synced_at), 'its sync time is the SDK\'s');
        $this->assertSame(3, WorkflowTrigger::where('workflow_id', $orders->id)->count(), 'its triggers stay');
        $this->assertSame('api', $this->workflow('cleanup')->source, 'changed through the API');
    }

    public function test_an_sdk_definition_stays_sdk_when_terraform_declares_the_set_from_the_answer(): void
    {
        // the provider of an earlier release: the set as the API answers it, one definition added
        $listed = $this->getJson($this->base())->assertOk()->json('data');
        $set = array_map(fn ($w) => $this->asSentBack($w), $listed);
        $set[] = ['name' => 'report', 'triggers' => [['event' => 'report.requested']]];

        $this->putJson($this->base(), ['workflows' => $set])->assertOk();

        $this->assertSame('sdk', $this->workflow('orders')->source);
        $this->assertSame('sdk', $this->workflow('cleanup')->source);
        $this->assertSame('api', $this->workflow('report')->source);
    }

    public function test_one_definition_is_declared_and_archived_without_touching_the_others(): void
    {
        $orders = $this->workflow('orders');

        // runr_workflow: PUT one
        $this->putJson($this->base().'/report', ['description' => 'Monthly report', 'triggers' => [['event' => 'report.requested']], 'retries' => 1])
            ->assertOk()
            ->assertJsonPath('message', __('workflows.declared_one', ['name' => 'report']))
            ->assertJsonPath('data.name', 'report')
            ->assertJsonPath('data.source', 'api')
            ->assertJsonPath('data.retries', 1)
            ->assertJsonPath('data.triggers.0.event', 'report.requested');

        foreach (['orders', 'cleanup'] as $name) {
            $this->assertSame('sdk', $this->workflow($name)->source, $name);
            $this->assertNull($this->workflow($name)->archived_at, $name);
        }
        $this->assertTrue($orders->synced_at->equalTo($this->workflow('orders')->synced_at));

        // the same again: nothing changes; changed: replaced
        $this->putJson($this->base().'/report', ['name' => 'report', 'description' => 'Monthly report', 'triggers' => [['event' => 'report.requested']], 'retries' => 1])->assertOk();
        $this->putJson($this->base().'/report', ['triggers' => [['cron' => '0 6 1 * *']]])
            ->assertOk()->assertJsonPath('data.triggers.0.cron', '0 6 1 * *')->assertJsonPath('data.description', null);
        $this->assertSame(1, WorkflowTrigger::where('workflow_id', $this->workflow('report')->id)->count());

        // a declared-unchanged SDK definition keeps its source here too
        $this->putJson($this->base().'/orders', self::ORDERS)->assertOk()->assertJsonPath('data.source', 'sdk');

        // DELETE one: archived, its triggers gone, the others as they are
        $this->deleteJson($this->base().'/report')
            ->assertOk()
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('message', __('workflows.archived', ['name' => 'report']));
        $this->assertSame(0, WorkflowTrigger::where('workflow_id', $this->workflow('report')->id)->count());
        $this->assertNull($this->workflow('orders')->archived_at);
        $this->deleteJson($this->base().'/report')->assertOk()->assertJsonPath('data.archived', true);
        $this->deleteJson($this->base().'/missing')->assertNotFound();

        // declared again: back, through the API
        $this->putJson($this->base().'/report', ['triggers' => []])->assertOk()->assertJsonPath('data.archived', false)->assertJsonPath('data.source', 'api');
    }

    public function test_a_single_declaration_is_validated(): void
    {
        $this->putJson($this->base().'/report', ['name' => 'other'])
            ->assertStatus(422)->assertJsonPath('code', 'definition_invalid');
        $this->putJson($this->base().'/report', ['retries' => -1])
            ->assertStatus(422)->assertJsonPath('code', 'definition_invalid');
        $this->putJson($this->base().'/report', ['triggers' => [['event' => 'runr/x', 'cron' => 'nope']]])
            ->assertStatus(422);
        // the API's own segments are no workflow names
        $this->putJson($this->base().'/sync', [])->assertStatus(422)->assertJsonPath('code', 'definition_invalid');

        config(['workflows.limits.max_workflows_per_project' => 2]);
        $this->putJson($this->base().'/report', [])->assertStatus(422)->assertJsonPath('code', 'definition_invalid');
        // replacing one of the two is no new workflow
        $this->putJson($this->base().'/cleanup', ['triggers' => []])->assertOk();
    }

    public function test_a_sync_takes_the_code_s_source_again(): void
    {
        $this->putJson($this->base().'/report', ['triggers' => [['event' => 'report.requested']]])->assertOk();
        $this->assertSame('api', $this->workflow('report')->source);

        // the code now reports it, unchanged: the code owns it
        $this->fn->define('report', fn () => 1, ['triggers' => [['event' => 'report.requested']]]);
        app(WorkflowRegistry::class)->sync($this->project);

        $this->assertSame('sdk', $this->workflow('report')->source);
        $this->assertInstanceOf(Carbon::class, $this->workflow('report')->synced_at);
    }
}
