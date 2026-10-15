<?php

namespace Tests\Concerns;

use App\Models\ContainerConfig;
use App\Models\Project;
use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Engine\RunStarter;
use App\Workflows\Engine\Ticker;
use App\Workflows\Engine\WorkflowExecutor;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Models\Workflow;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Models\WorkflowStep;
use Closure;
use Illuminate\Support\Facades\Http;
use Tests\Support\Workflows\FakeWorkflowFunction;
use Tests\Support\Workflows\RecordingDispatcher;

/**
 * Engine test harness: a workflow-trigger project whose function is FakeWorkflowFunction
 * (Http::fake on https://fn.test/), a recording dispatcher instead of the queue, and drain()
 * to execute dispatched runs until nothing due is left. Call setUpWorkflows() from setUp().
 */
trait RunsWorkflows
{
    protected FakeWorkflowFunction $fn;

    protected RecordingDispatcher $dispatcher;

    protected Project $project;

    /** @var array<string, array> definitions declared so far (declare() replaces the set) */
    private array $declared = [];

    protected function setUpWorkflows(): void
    {
        config([
            'workflows.backoff.jitter' => 0,
            'workflows.backoff.base_seconds' => 2,
            'workflows.backoff.factor' => 2,
        ]);

        $this->dispatcher = new RecordingDispatcher;
        $this->app->instance(WorkflowDispatcher::class, $this->dispatcher);

        $this->project = $this->makeWorkflowProject('wf-orders');

        $app = WorkflowApp::forProject($this->project);
        $app->forceFill(['endpoint_url' => 'https://fn.test/'])->save();

        $this->fn = new FakeWorkflowFunction($app->signing_key);

        Http::fake(['fn.test/*' => fn ($request) => $this->fn->handle($request)]);
    }

    protected function makeWorkflowProject(string $name, ?string $queueStream = null): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'ghcr.io/example/workflows:1',
            'cpu' => '250m',
            'memory_mb' => 128,
            'timeout_seconds' => 30,
            'trigger_type' => 'workflow',
            'queue_stream' => $queueStream,
        ]);

        return $project->fresh(['containerConfig']);
    }

    /** Define a workflow in the fake function and declare it to the engine. */
    protected function defineWorkflow(string $name, Closure $handler, array $definition = []): Workflow
    {
        $this->fn->define($name, $handler, $definition);
        $this->declared[$name] = ['name' => $name] + $definition;

        app(WorkflowRegistry::class)->declare($this->project, array_values($this->declared));

        return Workflow::where('project_id', $this->project->id)->where('name', $name)->firstOrFail();
    }

    protected function startRun(Workflow $workflow, array $data = [], array $options = []): WorkflowRun
    {
        return app(RunStarter::class)->start($workflow->fresh(), ['name' => 'test/start', 'data' => $data], $options)->run;
    }

    /** Execute due dispatches until none is left; returns the executor outcomes. */
    protected function drain(int $max = 100): array
    {
        $outcomes = [];

        for ($i = 0; $i < $max; $i++) {
            $runId = $this->dispatcher->takeDue(now());
            if ($runId === null) {
                return $outcomes;
            }
            $outcomes[] = app(WorkflowExecutor::class)->execute($runId);
        }

        $this->fail('drain() did not settle after '.$max.' executions: '.implode(', ', array_slice($outcomes, -10)));
    }

    /** Execute the next due dispatch only; null when none is due. */
    protected function executeNext(): ?string
    {
        $runId = $this->dispatcher->takeDue(now());

        return $runId === null ? null : app(WorkflowExecutor::class)->execute($runId);
    }

    /** Move the clock forward, run the per-minute tick (wakes sleeps / timeouts) and drain. */
    protected function advance(int $seconds): array
    {
        $this->travelTo(now()->addSeconds($seconds));
        $stats = app(Ticker::class)->tick();
        $this->drain();

        return $stats;
    }

    protected function runStatus(WorkflowRun $run): string
    {
        return $run->fresh()->status;
    }

    protected function step(WorkflowRun $run, string $key): ?WorkflowStep
    {
        return WorkflowStep::where('run_id', $run->id)->where('step_key', $key)->first();
    }
}
