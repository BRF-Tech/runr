<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\EventIngestor;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/** step.waitForEvent: match / if against the trigger event, resume with the event, timeout → null. */
class WorkflowWaitForEventTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        $this->freezeSecond();
    }

    private function send(string $name, array $data, ?string $id = null): array
    {
        $event = ['name' => $name, 'data' => $data];
        if ($id) {
            $event['id'] = $id;
        }

        return app(EventIngestor::class)->ingest([$event])[0];
    }

    private function shipmentWorkflow(string $timeout = '1h')
    {
        return $this->defineWorkflow('ship', function (FakeStepTools $step, object $event) use ($timeout) {
            $paid = $step->waitForEvent('wait-paid', 'order.paid', match: 'data.order_id', timeout: $timeout);
            if ($paid === null) {
                return ['status' => 'expired'];
            }

            return ['status' => 'shipped', 'amount' => $paid->data->amount, 'event' => $paid->name];
        });
    }

    public function test_waits_until_a_matching_event_arrives(): void
    {
        $workflow = $this->shipmentWorkflow();
        $run = $this->startRun($workflow, ['order_id' => 7]);

        $this->assertSame(['waiting'], $this->drain());
        $run->refresh();
        $this->assertSame(WorkflowRun::WAITING, $run->status);
        $this->assertEquals(now()->addHour(), $run->next_attempt_at);

        $wait = $this->step($run, 'wait-paid');
        $this->assertSame(WorkflowStep::TYPE_WAIT, $wait->type);
        $this->assertSame(WorkflowStep::WAITING, $wait->status);
        $this->assertSame('order.paid', $wait->wait_event);
        $this->assertSame('(event.data.order_id == async.data.order_id)', $wait->wait_expression);
        $this->assertEquals(now()->addHour(), $wait->timeout_at);

        // Another order's payment does not resume this run
        $other = $this->send('order.paid', ['order_id' => 8, 'amount' => 1]);
        $this->assertSame(0, $other['resumed']);
        $this->assertSame([], $this->drain());
        $this->assertSame(WorkflowRun::WAITING, $this->runStatus($run));

        // The matching one does
        $match = $this->send('order.paid', ['order_id' => 7, 'amount' => 120]);
        $this->assertSame(1, $match['resumed']);
        $this->assertSame(['run_completed'], $this->drain());

        $run->refresh();
        $this->assertSame(WorkflowRun::COMPLETED, $run->status);
        $this->assertEquals((object) ['status' => 'shipped', 'amount' => 120, 'event' => 'order.paid'], $run->output);

        $wait->refresh();
        $this->assertSame(WorkflowStep::COMPLETED, $wait->status);
        $this->assertSame($match['id'], $wait->output->id);
        $this->assertSame(1, WorkflowEvent::find($match['id'])->waits_resumed);
    }

    public function test_times_out_with_null(): void
    {
        $workflow = $this->shipmentWorkflow('30m');
        $run = $this->startRun($workflow, ['order_id' => 7]);
        $this->drain();

        $stats = $this->advance(29 * 60);
        $this->assertSame(0, $stats['expired']);
        $this->assertSame(WorkflowRun::WAITING, $this->runStatus($run));

        $stats = $this->advance(60);
        $this->assertSame(1, $stats['expired']);
        $this->assertEquals((object) ['status' => 'expired'], $run->fresh()->output);
        $this->assertSame(WorkflowStep::COMPLETED, $this->step($run, 'wait-paid')->status);
        $this->assertNull($this->step($run, 'wait-paid')->output);

        // A late event resumes nothing
        $this->assertSame(0, $this->send('order.paid', ['order_id' => 7, 'amount' => 1])['resumed']);
    }

    public function test_an_event_sent_before_the_wait_is_not_matched(): void
    {
        $workflow = $this->shipmentWorkflow();
        $this->send('order.paid', ['order_id' => 7, 'amount' => 5]);

        $run = $this->startRun($workflow, ['order_id' => 7]);
        $this->drain();

        $this->assertSame(WorkflowRun::WAITING, $this->runStatus($run));
    }

    public function test_if_expression_filters_events(): void
    {
        $workflow = $this->defineWorkflow('approval', function (FakeStepTools $step) {
            $ok = $step->waitForEvent('approved', 'review.done', timeout: '1d', if: "async.data.verdict == 'approve' && async.data.doc == event.data.doc");

            return $ok?->data->by;
        });

        $run = $this->startRun($workflow, ['doc' => 'D1']);
        $this->drain();

        $this->assertSame(0, $this->send('review.done', ['doc' => 'D1', 'verdict' => 'reject', 'by' => 'x'])['resumed']);
        $this->assertSame(0, $this->send('review.done', ['doc' => 'D2', 'verdict' => 'approve', 'by' => 'y'])['resumed']);
        $this->assertSame(1, $this->send('review.done', ['doc' => 'D1', 'verdict' => 'approve', 'by' => 'ayse'])['resumed']);
        $this->drain();

        $this->assertSame('ayse', $run->fresh()->output);
    }

    public function test_one_event_resumes_every_matching_run(): void
    {
        $workflow = $this->defineWorkflow('broadcast', function (FakeStepTools $step) {
            $e = $step->waitForEvent('go', 'batch.release', timeout: '1h');

            return $e->data->batch;
        });

        $runs = [$this->startRun($workflow), $this->startRun($workflow), $this->startRun($workflow)];
        $this->drain();

        $this->assertSame(3, $this->send('batch.release', ['batch' => 'b-1'])['resumed']);
        $this->drain();

        foreach ($runs as $run) {
            $this->assertSame('b-1', $run->fresh()->output);
        }
    }

    public function test_wait_timeout_is_capped_by_the_run_timeout(): void
    {
        $workflow = $this->defineWorkflow('capped', function (FakeStepTools $step) {
            $step->waitForEvent('never', 'nothing.happens', timeout: '30d');

            return 'x';
        }, ['timeout' => '2h']);

        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertEquals(now()->addHours(2), $this->step($run, 'never')->timeout_at);
    }

    public function test_default_wait_timeout(): void
    {
        config(['workflows.defaults.wait_timeout' => '3d']);
        $workflow = $this->defineWorkflow('default-timeout', function (FakeStepTools $step) {
            $step->waitForEvent('w', 'x.y');

            return 1;
        });

        $run = $this->startRun($workflow);
        $this->drain();

        $this->assertEquals(now()->addDays(3), $this->step($run, 'w')->timeout_at);
    }

    public function test_a_duplicate_event_id_is_processed_once(): void
    {
        $workflow = $this->shipmentWorkflow();
        $run = $this->startRun($workflow, ['order_id' => 7]);
        $this->drain();

        $first = $this->send('order.paid', ['order_id' => 7, 'amount' => 3], 'pay-1');
        $second = $this->send('order.paid', ['order_id' => 7, 'amount' => 3], 'pay-1');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, WorkflowEvent::where('external_id', 'pay-1')->count());
        $this->drain();
        $this->assertSame(WorkflowRun::COMPLETED, $this->runStatus($run));
    }
}
