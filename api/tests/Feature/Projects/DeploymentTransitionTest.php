<?php

namespace Tests\Feature\Projects;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\Git\GitDeploy;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\CacheVersionService;
use App\Workflows\Jobs\SyncWorkflowsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Deployment::transition() — the conditional status write of every lifecycle step (readiness
 * check, deploy, teardown, stop) — fires the model events like update(): every listener of a
 * deployment's change still runs, and a claim lost to a concurrent change fires none.
 */
class DeploymentTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_transition_fires_the_model_events_like_an_update(): void
    {
        $deployment = $this->deployment($this->project('events'));

        $events = [];
        foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
            Deployment::{$event}(function (Deployment $d) use ($event, &$events) {
                $events[] = $event.($d->isDirty('status') || $d->wasChanged('status') ? ':status' : '');
            });
        }

        $this->assertTrue($deployment->transition(['pending', 'running'], ['status' => 'active', 'ready_at' => now()]));

        $this->assertSame(['saving:status', 'updating:status', 'updated:status', 'saved:status'], $events);
        $this->assertSame('active', $deployment->fresh()->status);
        $this->assertTrue($deployment->wasChanged('status'));
        $this->assertFalse($deployment->isDirty(), 'the model holds the stored row');
    }

    public function test_a_lost_claim_fires_no_updated_event_and_changes_nothing(): void
    {
        $deployment = $this->deployment($this->project('lost'));
        $stale = Deployment::query()->findOrFail($deployment->id);
        Deployment::query()->whereKey($deployment->id)->update(['status' => 'shutting_down']);

        $updated = 0;
        Deployment::updated(function () use (&$updated) {
            $updated++;
        });

        $this->assertFalse($stale->transition(['pending', 'running'], ['status' => 'active']));
        $this->assertSame(0, $updated);
        $this->assertSame('shutting_down', $stale->status, 'the model is read back');
        $this->assertFalse($stale->isDirty());
    }

    public function test_a_git_deploy_follows_its_deployment_through_transitions(): void
    {
        $project = $this->project('git');
        $live = $this->deployment($project);
        $broken = $this->deployment($project);
        $toLive = $this->gitDeploy($project, $live);
        $toFail = $this->gitDeploy($project, $broken);

        $this->assertTrue($live->transition(['pending', 'running'], ['status' => 'active', 'ready_at' => now()]));
        $this->assertTrue($broken->transition(['pending', 'running'], ['status' => 'failed', 'knative_message' => 'Image pull failed']));

        $this->assertSame(GitDeploy::ACTIVE, $toLive->fresh()->status, 'the push deploy is live, not deploying forever');
        $this->assertSame(GitDeploy::FAILED, $toFail->fresh()->status);
        $this->assertSame('Image pull failed', $toFail->fresh()->status_message);
    }

    public function test_a_workflow_project_activated_by_a_transition_queues_a_sync(): void
    {
        Queue::fake();
        $deployment = $this->deployment($this->project('flows', trigger: 'workflow'));

        $deployment->transition(['pending', 'running'], ['status' => 'active', 'ready_at' => now()]);

        Queue::assertPushed(SyncWorkflowsJob::class, fn ($job) => $job->projectId === $deployment->project_id);
    }

    public function test_a_transition_invalidates_the_cached_deployments_of_the_project(): void
    {
        $deployment = $this->deployment($this->project('cached'));
        $cache = app(CacheVersionService::class);
        $scope = CacheVersionService::projectScope($deployment->project_id);
        $before = $cache->version($scope);

        $deployment->transition(['pending', 'running'], ['status' => 'active', 'ready_at' => now()]);

        $this->assertGreaterThan($before, $cache->version($scope), 'the deployments endpoints answer the new status');
    }

    public function test_ended_at_is_set_once_when_a_deployment_stops_running(): void
    {
        Carbon::setTestNow('2026-10-11 15:00:00');
        $deployment = $this->deployment($this->project('ending'));
        $this->assertTrue($deployment->transition(['pending'], ['status' => 'shutting_down']));
        $this->assertSame('2026-10-11 15:00:00', $deployment->ended_at?->toDateTimeString());

        // The teardown finishes later: the time it stopped running stays
        Carbon::setTestNow('2026-10-11 15:05:00');
        $this->assertTrue($deployment->transition(['shutting_down'], ['status' => 'shut_down']));
        $this->assertSame('2026-10-11 15:00:00', $deployment->fresh()->ended_at?->toDateTimeString());

        // Between two states that are not live nothing is set: a deployment that never ran has none
        $never = $this->deployment($this->project('never'), 'failed');
        $never->update(['status' => 'shut_down']);
        $this->assertNull($never->fresh()->ended_at);
    }

    public function test_a_transition_keeps_an_ended_at_a_concurrent_write_stored(): void
    {
        Carbon::setTestNow('2026-10-11 15:00:00');
        $deployment = $this->deployment($this->project('concurrent'), 'active');
        // Another worker stores the end between this transition's read and its write
        Deployment::saving(function (Deployment $d) {
            if ($d->isDirty('status') && $d->status === 'shutting_down') {
                Deployment::query()->whereKey($d->id)->update(['ended_at' => '2026-10-11 14:59:00']);
            }
        });

        $this->assertTrue($deployment->transition(['active'], ['status' => 'shutting_down']));

        $this->assertSame('2026-10-11 14:59:00', $deployment->fresh()->ended_at?->toDateTimeString());
        $this->assertSame('2026-10-11 14:59:00', $deployment->ended_at?->toDateTimeString(), 'the model holds what is stored');
    }

    public function test_the_migrations_give_deployments_that_stopped_before_the_upgrade_their_last_change(): void
    {
        $project = $this->project('history');
        $ended = $this->deployment($project, 'shut_down');
        $live = $this->deployment($project, 'active');
        $kept = $this->deployment($project, 'failed');
        Deployment::query()->whereKey($ended->id)->toBase()->update(['ended_at' => null, 'updated_at' => '2026-10-02 10:00:00']);
        Deployment::query()->whereKey($kept->id)->toBase()->update(['ended_at' => '2026-10-03 08:00:00', 'updated_at' => '2026-10-04 09:00:00']);

        foreach ([
            database_path('migrations/2026_10_19_400001_add_deployment_spans_and_stop_level.php'),
            base_path('Modules/Tenancy/Database/TenantMigrations/0062_add_deployment_spans_and_stop_level.php'),
        ] as $migration) {
            Deployment::query()->whereKey($ended->id)->toBase()->update(['ended_at' => null]);
            (require $migration)->up();

            $this->assertSame('2026-10-02 10:00:00', $ended->fresh()->ended_at?->toDateTimeString(), basename($migration));
            $this->assertNull($live->fresh()->ended_at, 'a live deployment has none');
            $this->assertSame('2026-10-03 08:00:00', $kept->fresh()->ended_at?->toDateTimeString(), 'a stored time stays');
        }
    }

    private function project(string $name, string $trigger = 'http'): Project
    {
        $region = KnativeRegion::query()->firstOrCreate(['name' => 'fn'], ['project_namespace' => 'fn', 'is_active' => true]);
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'trigger_type' => $trigger, 'timeout_seconds' => 30]);

        return $project->fresh();
    }

    private function deployment(Project $project, string $status = 'pending'): Deployment
    {
        $version = Version::query()->firstOrCreate(['project_id' => $project->id, 'number' => 1], ['created_by' => 'dev', 'build_status' => 'ready']);

        return $project->deployments()->create(['version_id' => $version->id, 'status' => $status, 'deployed_by' => 'dev']);
    }

    private function gitDeploy(Project $project, Deployment $deployment): GitDeploy
    {
        return GitDeploy::create([
            'project_id' => $project->id, 'kind' => GitDeploy::KIND_PRODUCTION, 'trigger' => 'push', 'provider' => 'runr',
            'sha' => str_repeat('a', 40), 'branch' => 'main', 'author_name' => 'Ada', 'status' => GitDeploy::DEPLOYING,
            'deployment_id' => $deployment->id,
        ]);
    }
}
