<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\EventIngestor;
use App\Workflows\Engine\RunStarter;
use App\Workflows\Engine\WorkflowException;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

/** Idempotency keys on start: explicit keys, definition expressions, event-triggered starts. */
class WorkflowIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    public function test_explicit_key_returns_the_existing_run(): void
    {
        $workflow = $this->defineWorkflow('pay', fn () => 'paid');
        $starter = app(RunStarter::class);

        $first = $starter->start($workflow, ['name' => 'x', 'data' => ['a' => 1]], ['idempotency_key' => 'invoice-42']);
        $second = $starter->start($workflow, ['name' => 'x', 'data' => ['a' => 2]], ['idempotency_key' => 'invoice-42']);
        $third = $starter->start($workflow, ['name' => 'x', 'data' => ['a' => 3]], ['idempotency_key' => 'invoice-43']);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->run->id, $second->run->id);
        $this->assertTrue($third->created);
        $this->assertSame(2, WorkflowRun::where('workflow_id', $workflow->id)->count());
        $this->assertSame(1, $first->run->fresh()->event->data->a, 'the first event is kept');
    }

    public function test_keys_are_scoped_per_workflow(): void
    {
        $a = $this->defineWorkflow('a', fn () => 1);
        $b = $this->defineWorkflow('b', fn () => 1);

        $ra = app(RunStarter::class)->start($a, ['name' => 'x'], ['idempotency_key' => 'same']);
        $rb = app(RunStarter::class)->start($b, ['name' => 'x'], ['idempotency_key' => 'same']);

        $this->assertTrue($rb->created);
        $this->assertNotSame($ra->run->id, $rb->run->id);
    }

    public function test_definition_expression_derives_the_key_from_the_event(): void
    {
        $workflow = $this->defineWorkflow('ship', fn () => 1, ['idempotency' => 'event.data.order_id']);

        $r1 = $this->startRun($workflow, ['order_id' => 'o-1', 'try' => 1]);
        $r2 = $this->startRun($workflow, ['order_id' => 'o-1', 'try' => 2]);
        $r3 = $this->startRun($workflow, ['order_id' => 'o-2']);
        $r4 = $this->startRun($workflow, ['no_order' => true]);
        $r5 = $this->startRun($workflow, ['no_order' => true]);

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame('idem:o-1', $r1->idempotency_key);
        $this->assertNotSame($r1->id, $r3->id);
        $this->assertNull($r4->idempotency_key, 'a null key value does not deduplicate');
        $this->assertNotSame($r4->id, $r5->id);
    }

    public function test_long_or_structured_key_values_are_hashed(): void
    {
        $workflow = $this->defineWorkflow('hashy', fn () => 1, ['idempotency' => 'event.data.payload']);

        $run = $this->startRun($workflow, ['payload' => ['a' => str_repeat('x', 300)]]);

        $this->assertMatchesRegularExpression('/^idem#[0-9a-f]{64}$/', $run->idempotency_key);
        $this->assertSame($run->id, $this->startRun($workflow, ['payload' => ['a' => str_repeat('x', 300)]])->id);
    }

    public function test_event_triggered_starts_are_deduplicated_by_the_expression(): void
    {
        $workflow = $this->defineWorkflow('on-order', fn () => 1, [
            'triggers' => [['event' => 'order.created']],
            'idempotency' => 'event.data.order_id',
        ]);

        $ingestor = app(EventIngestor::class);
        $first = $ingestor->ingest([['name' => 'order.created', 'data' => ['order_id' => 9]]])[0];
        $second = $ingestor->ingest([['name' => 'order.created', 'data' => ['order_id' => 9]]])[0];

        $this->assertCount(1, $first['runs']);
        $this->assertSame([], $second['runs']);
        $this->assertSame(1, WorkflowRun::where('workflow_id', $workflow->id)->count());
    }

    public function test_paused_workflows_refuse_new_runs(): void
    {
        $workflow = $this->defineWorkflow('paused', fn () => 1);
        $workflow->forceFill(['is_active' => false])->save();

        $this->expectException(WorkflowException::class);
        $this->startRun($workflow);
    }

    public function test_event_payload_size_limit(): void
    {
        config(['workflows.limits.max_event_bytes' => 100]);
        $workflow = $this->defineWorkflow('big', fn () => 1);

        try {
            $this->startRun($workflow, ['blob' => str_repeat('x', 200)]);
            $this->fail('oversized event accepted');
        } catch (WorkflowException $e) {
            $this->assertSame(413, $e->status);
        }
        $this->assertSame(0, WorkflowRun::count());
    }
}
