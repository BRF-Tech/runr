<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\CheckDeploymentReadyJob;
use App\Listeners\CheckResourceAfterDeploy;
use App\Listeners\ProcessDeployment;
use App\Listeners\ProcessTeardown;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Support\Projects\DeployQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A redeploy waited on the `default` queue behind what ran before it on the one worker an idle
 * Horizon keeps: the teardown of the deployment it replaced (queued first — its deletes are round
 * trips to the region's API server), the minute jobs, a long install. Its readiness checks are
 * delayed jobs that Horizon's autoscaler does not see waiting. The apply and the checks run on the
 * deploy queue, which Horizon works with a process pool of its own.
 */
class DeployQueueTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Project, 1: Version, 2: Deployment} */
    private function deployment(): array
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
        $project = Project::create(['name' => 'hello', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'timeout_seconds' => 30, 'trigger_type' => 'http']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'pending', 'deployed_by' => 'dev']);

        return [$project->fresh(), $version, $deployment];
    }

    private function pushedListener(string $listener, ?string $queue): bool
    {
        return Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job, $pushedQueue) => $job->class === $listener && $pushedQueue === $queue)->isNotEmpty();
    }

    public function test_the_apply_and_the_readiness_checks_run_on_the_deploy_queue(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        [$project, $version, $deployment] = $this->deployment();
        $old = $project->deployments()->create(['version_id' => $version->id, 'status' => 'shutting_down', 'deployed_by' => 'dev']);

        event(new DeploymentStatusChanged($old, 'active', 'shutting_down'));
        event(new DeploymentCreated($deployment, $project, $version));

        $this->assertTrue($this->pushedListener(ProcessDeployment::class, DeployQueue::NAME), 'the apply runs on the deploy queue');
        $this->assertTrue($this->pushedListener(ProcessTeardown::class, null), "the replaced deployment's teardown stays on default");
        $this->assertTrue($this->pushedListener(CheckResourceAfterDeploy::class, null));

        CheckDeploymentReadyJob::start($deployment, $project);
        Queue::assertPushedOn(DeployQueue::NAME, CheckDeploymentReadyJob::class, fn (CheckDeploymentReadyJob $job) => $job->attempt === 1);

        // the next check too (a check builds the next one)
        $this->assertSame(DeployQueue::NAME, (new CheckDeploymentReadyJob($deployment, $project, 7))->queue);
    }

    public function test_without_redis_everything_stays_on_the_default_queue(): void
    {
        config(['queue.default' => 'sync']);
        [$project, , $deployment] = $this->deployment();

        $this->assertNull(DeployQueue::name());
        $this->assertNull((new CheckDeploymentReadyJob($deployment, $project))->queue);
        $this->assertNull(app(ProcessDeployment::class)->viaQueue());
    }

    public function test_horizon_works_the_deploy_queue_with_a_worker_of_its_own_in_every_environment(): void
    {
        // Horizon runs the plan of its environment: the defaults with the environment's own values
        $defaults = (array) config('horizon.defaults');
        $plans = [];
        foreach ((array) config('horizon.environments') as $env => $supervisors) {
            $plans[$env] = array_replace_recursive($defaults, (array) $supervisors);
        }

        $this->assertArrayHasKey('production', $plans);
        foreach ($plans as $env => $supervisors) {
            $working = array_filter((array) $supervisors, fn (array $s) => ($s['connection'] ?? 'redis') === 'redis'
                && in_array(DeployQueue::NAME, (array) ($s['queue'] ?? []), true));
            $this->assertCount(1, $working, "{$env}: one supervisor works the deploy queue");

            $supervisor = reset($working);
            $queues = (array) $supervisor['queue'];
            $this->assertContains('default', $queues, "{$env}: the same supervisor works default");
            // balance simple / auto: a process pool per queue, each with at least minProcesses workers
            $this->assertContains($supervisor['balance'] ?? false, ['auto', 'simple'], "{$env}: a pool per queue");
            $this->assertGreaterThanOrEqual(1, (int) ($supervisor['minProcesses'] ?? 1), "{$env}: the deploy pool keeps a worker");
            $this->assertGreaterThanOrEqual(count($queues), (int) ($supervisor['maxProcesses'] ?? 1), "{$env}: room for every pool's worker");
        }
    }
}
