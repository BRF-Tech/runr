<?php

namespace Tests\Feature\Workflows;

use App\Workflows\Engine\EventIngestor;
use App\Workflows\Engine\Retention;
use App\Workflows\Engine\Ticker;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use App\Workflows\Models\WorkflowTrigger;
use App\Workflows\Models\WorkflowUsage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\Support\Workflows\FakeStepTools;
use Tests\TestCase;

/** Cron triggers (one run per due minute, timezones, paused workflows), retention, scheduling. */
class WorkflowCronAndRetentionTest extends TestCase
{
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
    }

    public function test_a_due_cron_trigger_starts_exactly_one_run_per_minute(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:20', 'UTC'));
        $workflow = $this->defineWorkflow('report', fn ($step, $event) => $event->data->scheduled_at, [
            'triggers' => [['cron' => '0 9 * * *']],
        ]);

        $this->assertSame(1, app(Ticker::class)->fireCrons(CarbonImmutable::now()));
        $this->assertSame(0, app(Ticker::class)->fireCrons(CarbonImmutable::now()), 'same minute: no second run');

        $run = WorkflowRun::where('workflow_id', $workflow->id)->sole();
        $this->assertSame('cron', $run->trigger_source);
        $this->assertSame('runr/cron', $run->event->name);
        $this->assertSame('0 9 * * *', $run->event->data->cron);
        $this->assertSame('cron:'.WorkflowTrigger::where('workflow_id', $workflow->id)->value('id').':202610120900', $run->idempotency_key);

        $this->drain();
        $this->assertSame('2026-10-12T09:00:00Z', $run->fresh()->output);

        // Not due at 09:01, due again the next day
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:01:00', 'UTC'));
        $this->assertSame(0, app(Ticker::class)->fireCrons(CarbonImmutable::now()));
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC'));
        $this->assertSame(1, app(Ticker::class)->fireCrons(CarbonImmutable::now()));
    }

    public function test_cron_respects_the_timezone(): void
    {
        $workflow = $this->defineWorkflow('istanbul-morning', fn () => 1, [
            'triggers' => [['cron' => '0 9 * * *', 'timezone' => 'Europe/Istanbul']],
        ]);

        // 09:00 UTC is 12:00 in Istanbul: not due
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
        $this->assertSame(0, app(Ticker::class)->fireCrons(CarbonImmutable::now()));

        // 06:00 UTC is 09:00 in Istanbul (UTC+3)
        $this->travelTo(CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC'));
        $this->assertSame(1, app(Ticker::class)->fireCrons(CarbonImmutable::now()));
        $this->assertSame(1, WorkflowRun::where('workflow_id', $workflow->id)->count());
    }

    public function test_paused_workflows_do_not_fire(): void
    {
        $workflow = $this->defineWorkflow('paused-cron', fn () => 1, ['triggers' => [['cron' => '* * * * *']]]);
        $workflow->forceFill(['is_active' => false])->save();

        $this->assertSame(0, app(Ticker::class)->fireCrons(CarbonImmutable::now()));
    }

    public function test_resync_keeps_the_last_tick_of_an_unchanged_cron(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'));
        $workflow = $this->defineWorkflow('hourly', fn () => 1, ['triggers' => [['cron' => '0 * * * *']]]);
        app(Ticker::class)->fireCrons(CarbonImmutable::now());

        // Re-declaring (e.g. a sync after a redeploy) within the same minute
        $this->defineWorkflow('hourly', fn () => 1, ['triggers' => [['cron' => '0 * * * *']]]);
        $this->assertSame(0, app(Ticker::class)->fireCrons(CarbonImmutable::now()));
        $this->assertSame(1, WorkflowRun::where('workflow_id', $workflow->id)->count());
    }

    public function test_event_trigger_with_if_filter(): void
    {
        $workflow = $this->defineWorkflow('big-orders', fn () => 1, [
            'triggers' => [['event' => 'order.created', 'if' => 'event.data.total >= 1000']],
        ]);

        $small = app(EventIngestor::class)->ingest([['name' => 'order.created', 'data' => ['total' => 10]]])[0];
        $big = app(EventIngestor::class)->ingest([['name' => 'order.created', 'data' => ['total' => 5000]]])[0];

        $this->assertSame([], $small['runs']);
        $this->assertCount(1, $big['runs']);
        $this->assertSame(1, WorkflowEvent::find($big['id'])->runs_started);
        $this->assertSame($big['id'], WorkflowRun::where('workflow_id', $workflow->id)->sole()->event_id);
    }

    public function test_prune_removes_old_finished_runs_events_and_usage_only(): void
    {
        config(['workflows.retention' => ['runs_days' => 30, 'events_days' => 7, 'usage_days' => 400]]);
        $workflow = $this->defineWorkflow('old', function (FakeStepTools $step) {
            $step->run('s', fn () => 1);
            $step->sleep('z', '60d');

            return 1;
        });

        $finished = $this->startRun($workflow);
        $finished->forceFill(['status' => WorkflowRun::COMPLETED, 'ended_at' => now()->subDays(31)])->save();
        WorkflowStep::create(['run_id' => $finished->id, 'step_key' => 'k', 'name' => 'k', 'type' => 'run', 'status' => 'completed', 'position' => 1]);

        $recent = $this->startRun($workflow);
        $recent->forceFill(['status' => WorkflowRun::FAILED, 'ended_at' => now()->subDays(2)])->save();

        $active = $this->startRun($workflow);
        $active->forceFill(['created_at' => now()->subDays(90)])->save();

        $oldEvent = WorkflowEvent::create(['name' => 'a', 'data' => [], 'source' => 'api', 'ts' => 1]);
        $oldEvent->forceFill(['created_at' => now()->subDays(8)])->save();
        $newEvent = WorkflowEvent::create(['name' => 'b', 'data' => [], 'source' => 'api', 'ts' => 1]);

        WorkflowUsage::create(['date' => now()->subDays(401)->toDateString(), 'project_id' => $this->project->id, 'workflow_id' => $workflow->id, 'step_executions' => 5]);

        $result = app(Retention::class)->prune();

        $this->assertSame(['runs' => 1, 'events' => 1, 'usage' => 1], $result);
        $this->assertNull(WorkflowRun::find($finished->id));
        $this->assertSame(0, WorkflowStep::where('run_id', $finished->id)->count());
        $this->assertNotNull(WorkflowRun::find($recent->id));
        $this->assertNotNull(WorkflowRun::find($active->id), 'active runs are never pruned');
        $this->assertNull(WorkflowEvent::find($oldEvent->id));
        $this->assertNotNull(WorkflowEvent::find($newEvent->id));
    }

    public function test_engine_tasks_are_scheduled_with_short_overlap_locks(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

        $this->assertTrue($events->has('workflows-tick'));
        $this->assertSame('* * * * *', $events['workflows-tick']->expression);
        $this->assertTrue($events['workflows-tick']->withoutOverlapping);
        $this->assertLessThanOrEqual(10, $events['workflows-tick']->expiresAt);

        $this->assertTrue($events->has('workflows-prune'));
        $this->assertTrue($events['workflows-prune']->withoutOverlapping);
        $this->assertLessThanOrEqual(120, $events['workflows-prune']->expiresAt);
    }

    public function test_tick_and_prune_commands_run(): void
    {
        $this->artisan('workflows:tick')->assertExitCode(0);
        $this->artisan('workflows:prune')->assertExitCode(0);
    }
}
