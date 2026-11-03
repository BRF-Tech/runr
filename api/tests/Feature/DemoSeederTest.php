<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentRollout;
use App\Models\Project;
use App\Services\Rollouts\DeployStrategy;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The demo data (Settings → Demo data, `php artisan demo:seed`, the public demo): English texts
 * for an international visitor, and a deployment history in most projects, so the project page
 * and its tour have deployments to show — before, only the three container projects got one
 * (a code project has no version until its code is saved, and the seeder deployed before).
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.admin.password' => 'demo-test-password']);
        (new DemoSeeder)->run();
    }

    private function project(string $name): Project
    {
        return Project::query()->where('name', $name)->firstOrFail();
    }

    /** @return Collection<int, Deployment> */
    private function deployments(Project $project)
    {
        return Deployment::query()->where('project_id', $project->id)->with('version')->orderBy('created_at')->get();
    }

    public function test_the_visible_texts_are_plain_english(): void
    {
        $projects = Project::query()->get();
        $this->assertCount(11, $projects);

        foreach ($projects as $project) {
            $text = (string) $project->description;
            $this->assertNotSame('', $text, $project->name);
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $text, $project->name);
            $this->assertDoesNotMatchRegularExpression('/fonksiyon|konteyner/u', $text, $project->name);
        }

        $messages = $projects->flatMap(fn (Project $p) => $p->versions()->pluck('commit_message'))->filter();
        foreach ($messages as $message) {
            $this->assertDoesNotMatchRegularExpression('/[çğıöşüÇĞİÖŞÜ]/u', $message);
        }
    }

    public function test_every_project_has_four_versions_and_a_deployment_history_with_one_live_deployment(): void
    {
        foreach (Project::query()->get() as $project) {
            $this->assertSame([1, 2, 3, 4], $project->versions()->reorder('number')->pluck('number')->all(), $project->name);

            $deployments = $this->deployments($project);
            $this->assertGreaterThanOrEqual(4, $deployments->count(), $project->name);

            $active = $deployments->where('status', 'active');
            $this->assertCount(1, $active, $project->name);
            $live = $active->first();
            $this->assertTrue($live->knative_ready, $project->name);
            $this->assertNotNull($live->ready_at, $project->name);
            $this->assertNull($live->ended_at, $project->name);
            $this->assertSame('admin', $live->deployed_by);
            $this->assertSame('run-'.$project->slug.'-v'.$live->version->number, $live->k8s_job_name);

            // The others ended, in the past, and the history reads in order of the versions
            foreach ($deployments->where('status', '!=', 'active') as $ended) {
                $this->assertContains($ended->status, ['shut_down', 'failed'], $project->name);
                $this->assertNotNull($ended->ended_at, $project->name);
                $this->assertTrue($ended->ended_at->isPast());
            }
            $this->assertTrue($deployments->every(fn (Deployment $d) => $d->created_at->isPast()));
            $this->assertTrue($deployments->first()->created_at->lt(now()->subDays(10)), $project->name);
        }
    }

    public function test_a_rollback_runs_the_third_version_with_the_config_it_ran_with(): void
    {
        $deployments = $this->deployments($this->project('demo-xaas-http'));

        $this->assertSame([1, 2, 3, 4, 3], $deployments->map(fn (Deployment $d) => $d->version->number)->all());
        $live = $deployments->last();
        $this->assertSame('active', $live->status);
        $this->assertSame(Deployment::CONFIG_RESTORED, $live->config_source);
        $this->assertSame('shut_down', $deployments[3]->status);
        $this->assertTrue($deployments[3]->ended_at->lte($live->ready_at));
    }

    public function test_a_failed_deployment_is_kept_with_its_reason_and_the_next_version_fixed_it(): void
    {
        $deployments = $this->deployments($this->project('demo-caas-http'));

        $failed = $deployments->firstWhere('status', 'failed');
        $this->assertSame(3, $failed->version->number);
        $this->assertFalse($failed->knative_ready);
        $this->assertStringContainsString('exited with code 1', $failed->knative_message);
        $this->assertSame(4, $deployments->firstWhere('status', 'active')->version->number);
    }

    public function test_a_canary_rollout_promoted_the_newest_version_and_is_over(): void
    {
        $project = $this->project('demo-faas-http');
        $deployments = $this->deployments($project);

        $rollout = DeploymentRollout::query()->where('project_id', $project->id)->sole();
        $this->assertSame(DeploymentRollout::CANARY, $rollout->strategy);
        $this->assertSame(DeploymentRollout::PROMOTED, $rollout->status);
        $this->assertSame(100, $rollout->traffic_percent);
        $this->assertNotNull($rollout->released_at);
        $this->assertFalse($rollout->holdsTraffic());
        // Over: the minute job (AdvanceRolloutsJob) leaves it alone
        $this->assertFalse(DeploymentRollout::query()->holding()->whereKey($rollout->id)->exists());

        $this->assertSame($deployments->firstWhere('status', 'active')->id, $rollout->deployment_id);
        $stable = $deployments->firstWhere('id', $rollout->stable_deployment_id);
        $this->assertSame(3, $stable->version->number);
        $this->assertSame('shut_down', $stable->status);
        $this->assertSame(['created', 'ready', 'step', 'step', 'step', 'promoted', 'released'], array_column($rollout->events, 'type'));
        $this->assertSame('Step 2 of 3: 50 % of the traffic to the new revision.', $rollout->events[3]['message']);

        // Its next deploys roll out as a canary too
        $this->assertSame(DeployStrategy::CANARY, DeployStrategy::forProject($project->fresh())->strategy);
    }

    public function test_the_projects_without_a_story_deploy_each_version_in_turn(): void
    {
        $deployments = $this->deployments($this->project('demo-xaas-schedule'));

        $this->assertSame([1, 2, 3, 4], $deployments->map(fn (Deployment $d) => $d->version->number)->all());
        $this->assertSame(['shut_down', 'shut_down', 'shut_down', 'active'], $deployments->pluck('status')->all());
        // each one served until the next one was ready
        $this->assertTrue($deployments[0]->ended_at->gt($deployments[1]->created_at));
        $this->assertTrue($deployments[0]->ended_at->lte($deployments[1]->ready_at ?? $deployments[1]->updated_at));
    }
}
