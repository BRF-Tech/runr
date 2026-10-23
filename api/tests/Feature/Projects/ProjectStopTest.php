<?php

namespace Tests\Feature\Projects;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Events\ProjectResumed;
use App\Events\ProjectStopped;
use App\Listeners\ProcessDeployment;
use App\Listeners\ProcessTeardown;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\ImportException;
use App\Services\KnativeDeployService;
use App\Services\Projects\ProjectStopConflict;
use App\Services\Projects\ProjectStopService;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Stopping and resuming a project (docs/billing.md#project-stop-and-resume) — core, it works with
 * billing off: projects.stop, a required reason shown to the members and sent to the owner, the
 * live deployments torn down, every deploy refused while stopped (API and git deploy), and the
 * version that ran redeployed on resume. Both actions are audited.
 */
class ProjectStopTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $admin;

    private User $developer;

    private Project $project;

    private Version $version;

    private Deployment $live;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class, ProjectStopped::class, ProjectResumed::class]);

        $this->userWithRole('owner');
        $this->admin = $this->userWithRole('admin');
        $this->developer = $this->userWithRole('developer');

        $this->project = $this->caasProject('orders');
        $this->project->forceFill(['owner_id' => (string) $this->developer->id])->save();
        $this->version = Version::create(['project_id' => $this->project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $this->live = $this->project->deployments()->create(['version_id' => $this->version->id, 'status' => 'active', 'k8s_job_name' => 'run-orders-v1', 'deployed_by' => 'dev']);
    }

    public function test_stopping_needs_projects_stop_and_a_reason(): void
    {
        $this->signedIn($this->developer)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Unpaid invoices'])
            ->assertForbidden()->assertJsonPath('permissions', ['projects.stop']);

        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertFalse($this->project->fresh()->isStopped());
    }

    public function test_a_stop_tears_the_project_down_and_tells_its_members_why(): void
    {
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Unpaid invoices'])
            ->assertOk()
            ->assertJsonPath('data.stopped.reason', 'Unpaid invoices')
            ->assertJsonPath('data.stopped.by.id', (string) $this->admin->id);

        $project = $this->project->fresh();
        $this->assertTrue($project->isStopped());
        $this->assertSame((string) $this->admin->id, $project->stopped_by);
        $this->assertSame($this->version->id, $project->stopped_version_id);

        // The live deployment goes to shutting_down: ProcessTeardown removes its Knative resources
        $this->assertSame('shutting_down', $this->live->fresh()->status);
        Event::assertDispatched(DeploymentStatusChanged::class, fn ($e) => $e->deployment->is($this->live) && $e->newStatus === 'shutting_down');
        Event::assertDispatched(ProjectStopped::class, fn ($e) => $e->project->is($this->project) && $e->reason === 'Unpaid invoices');

        // The owner is told, every member sees the reason on the project
        $notice = Notification::query()->where('user_id', (string) $this->developer->id)->sole();
        $this->assertSame('project.stopped', $notice->type);
        $this->assertSame('Unpaid invoices', $notice->body);
        $this->signedIn($this->developer)->getJson("/api/projects/{$this->project->id}")
            ->assertOk()->assertJsonPath('data.stopped.reason', 'Unpaid invoices');

        $this->assertSame('Unpaid invoices', AuditEvent::query()->where('action', 'project.stop')->sole()->metadata['reason']);

        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('code', 'project_already_stopped');
    }

    public function test_a_stopped_project_is_not_deployed(): void
    {
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Abuse report'])->assertOk();

        $this->signedIn($this->developer)->postJson("/api/projects/{$this->project->id}/deploy", ['version_id' => $this->version->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'project_stopped')
            ->assertJsonPath('reason', 'Abuse report');

        // A git push is refused the same way (the push reports the reason)
        try {
            app(DeploymentStarter::class)->start($this->project->fresh(), $this->version, 'git');
            $this->fail('the git deploy of a stopped project must be refused');
        } catch (ImportException $e) {
            $this->assertStringContainsString('Abuse report', $e->getMessage());
        }

        $this->assertSame(1, $this->project->deployments()->count(), 'no deployment was created');
    }

    public function test_resume_redeploys_the_version_that_ran(): void
    {
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")
            ->assertStatus(409)->assertJsonPath('code', 'project_not_stopped');

        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Maintenance of the account'])->assertOk();
        $this->live->update(['status' => 'shut_down']);

        $response = $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.stopped', null);

        $deployment = Deployment::query()->findOrFail($response->json('deployment_id'));
        $this->assertSame('pending', $deployment->status);
        $this->assertSame($this->version->id, $deployment->version_id);
        Event::assertDispatched(DeploymentCreated::class, fn ($e) => $e->deployment->is($deployment));
        Event::assertDispatched(ProjectResumed::class, fn ($e) => $e->deploymentId === $deployment->id);

        $project = $this->project->fresh();
        $this->assertFalse($project->isStopped());
        $this->assertNull($project->stop_reason);
        $this->assertNull($project->stopped_version_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'project.resume')->where('result', AuditEvent::RESULT_SUCCESS)->count());
    }

    public function test_a_resume_without_a_deployable_version_only_lifts_the_stop(): void
    {
        $this->live->delete();
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Paused'])->assertOk();

        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")
            ->assertOk()->assertJsonPath('deployment_id', null);

        $this->assertFalse($this->project->fresh()->isStopped());
        Event::assertNotDispatched(DeploymentCreated::class);
    }

    public function test_of_two_concurrent_stops_one_wins_and_the_running_version_is_kept(): void
    {
        $stale = $this->project->fresh();
        $stops = app(ProjectStopService::class);

        $stops->stop($this->project, $this->admin, 'First');
        try {
            // The second request read the project before the first one stopped it
            $stops->stop($stale, $this->admin, 'Second');
            $this->fail('the second stop must change nothing');
        } catch (ProjectStopConflict $e) {
            $this->assertSame('project_already_stopped', $e->errorCode);
        }

        $project = $this->project->fresh();
        $this->assertSame('First', $project->stop_reason);
        $this->assertSame($this->version->id, $project->stopped_version_id, 'resume still knows what ran');
        $this->assertSame(1, Notification::query()->where('type', 'project.stopped')->count());
    }

    public function test_a_platform_stop_is_resumed_only_by_a_platform_administrator(): void
    {
        $platform = $this->platformAdmin();

        $this->signedIn($platform)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Terms of service'])
            ->assertOk()->assertJsonPath('data.stopped.level', 'platform');
        $this->assertSame('platform', $this->project->fresh()->stop_level);

        // Members see that the platform stopped it, and why — not which administrator
        $this->signedIn($this->developer)->getJson("/api/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('data.stopped.level', 'platform')
            ->assertJsonPath('data.stopped.reason', 'Terms of service')
            ->assertJsonPath('data.stopped.by', null);

        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")
            ->assertForbidden()->assertJsonPath('code', 'project_stopped_by_platform');
        $this->assertTrue($this->project->fresh()->isStopped());

        $this->signedIn($platform)->postJson("/api/projects/{$this->project->id}/resume")->assertOk();
        $this->assertFalse($this->project->fresh()->isStopped());
    }

    public function test_an_organization_stop_is_resumed_by_the_organization_or_the_platform_and_taken_over_by_the_platform(): void
    {
        $platform = $this->platformAdmin();

        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Unpaid invoices'])
            ->assertOk()
            ->assertJsonPath('data.stopped.level', 'organization')
            ->assertJsonPath('data.stopped.by.id', (string) $this->admin->id);

        // The platform takes the stop over: from now on only it can resume
        $this->signedIn($platform)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Fraud review'])
            ->assertOk()->assertJsonPath('data.stopped.level', 'platform')->assertJsonPath('data.stopped.reason', 'Fraud review');
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")->assertForbidden();
        $this->signedIn($platform)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('code', 'project_already_stopped')->assertJsonPath('level', 'platform');
        $this->signedIn($platform)->postJson("/api/projects/{$this->project->id}/resume")->assertOk();

        // An organization stop: the organization resumes it
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Paused'])->assertOk();
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")->assertOk();
    }

    public function test_resume_keeps_the_overrides_of_the_deployment_that_ran(): void
    {
        $this->live->forceFill(['deploy_config' => ['min_scale' => 2, 'scale_to_zero' => false]])->save();
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Maintenance'])->assertOk();

        $id = $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")->assertOk()->json('deployment_id');

        $this->assertSame(['min_scale' => 2, 'scale_to_zero' => false], Deployment::query()->findOrFail($id)->deploy_config);
    }

    public function test_resume_runs_the_deploy_checks_and_says_why_nothing_was_deployed(): void
    {
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Maintenance'])->assertOk();
        $this->version->forceFill(['build_status' => Version::STATUS_PRUNED])->save();

        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")
            ->assertOk()
            ->assertJsonPath('deployment_id', null)
            ->assertJsonPath('data.stopped', null)
            ->assertJsonPath('redeploy_error', fn ($message) => str_contains((string) $message, 'v1'));

        // A module guard refuses the redeploy too (e.g. a fixed region's allocation)
        $this->live->fresh()->update(['status' => 'active']);
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Again'])->assertOk();
        $this->version->forceFill(['build_status' => 'ready'])->save();
        ProjectAdmission::guardDeploys(fn () => throw new HttpResponseException(response()->json(['message' => 'Over the allocation.', 'code' => 'fixed_allocation_exceeded'], 422)));
        try {
            $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")
                ->assertOk()->assertJsonPath('deployment_id', null)->assertJsonPath('redeploy_error', 'Over the allocation.')
                ->assertJsonPath('redeploy_code', 'fixed_allocation_exceeded');
        } finally {
            ProjectAdmission::reset();
        }
    }

    public function test_a_deploy_queued_before_the_stop_does_nothing(): void
    {
        $this->project->forceFill(['knative_region_id' => KnativeRegion::create(['name' => 'demo', 'project_namespace' => 'demo', 'is_active' => true, 'is_demo' => true])->id])->saveQuietly();
        $pending = $this->project->deployments()->create(['version_id' => $this->version->id, 'status' => 'pending', 'k8s_job_name' => 'run-orders-v1']);

        app(ProjectStopService::class)->stop($this->project, $this->admin, 'Unpaid bill');
        $this->assertSame('shutting_down', $pending->fresh()->status);

        // The ProcessDeployment of the earlier deploy runs now, then the teardown of the stop
        (new ProcessDeployment(app(KnativeDeployService::class)))->handle(new DeploymentCreated($pending, $this->project->fresh(), $this->version));
        $this->assertSame('shutting_down', $pending->fresh()->status, 'the deploy did not set it live');
        (new ProcessTeardown(app(KnativeDeployService::class)))->handle(new DeploymentStatusChanged($pending->fresh(), 'pending', 'shutting_down'));

        $this->assertSame('shut_down', $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->ended_at);

        // A deployment created after the stop slipped through the endpoint's check: refused on the worker
        $late = $this->project->deployments()->create(['version_id' => $this->version->id, 'status' => 'pending', 'k8s_job_name' => 'run-orders-v1']);
        app(KnativeDeployService::class)->deploy($this->project->fresh(['knativeRegion', 'containerConfig']), $this->version, $late);
        $this->assertSame('failed', $late->fresh()->status);
        $this->assertStringContainsString('stopped', (string) $late->fresh()->knative_message);
    }

    public function test_the_billing_role_stops_projects(): void
    {
        $billing = $this->userWithRole('billing');

        $this->signedIn($billing)->postJson("/api/projects/{$this->project->id}/stop", ['reason' => 'Unpaid invoices'])->assertOk();
        $this->signedIn($billing)->postJson("/api/projects/{$this->project->id}/resume")->assertOk();
    }

    public function test_a_stop_runr_makes_by_itself_names_no_one_and_the_organization_resumes_it(): void
    {
        $stops = app(ProjectStopService::class);
        $stops->stopBySystem($this->project, 'cost_limit', 'Monthly cost limit reached');

        $project = $this->project->fresh();
        $this->assertTrue($project->isStopped());
        $this->assertSame('system:cost_limit', $project->stopped_by);
        $this->assertSame('cost_limit', ProjectStopService::systemOf($project));
        $this->assertSame(ProjectStopService::LEVEL_ORGANIZATION, $stops->levelOfStop($project));
        $this->assertSame($this->version->id, $project->stopped_version_id);
        $this->assertSame('shutting_down', $this->live->fresh()->status);
        Event::assertDispatched(ProjectStopped::class, fn ($e) => $e->project->is($this->project) && $e->actorId === 'system:cost_limit');

        // Audited as the system's stop; the owner is told
        $audit = AuditEvent::query()->where('action', 'project.stop')->sole();
        $this->assertSame(AuditEvent::ACTOR_SYSTEM, $audit->actor_type);
        $this->assertSame('cost_limit', $audit->metadata['system']);
        $this->assertSame('Monthly cost limit reached', $audit->metadata['reason']);
        $this->assertSame('project.stopped', Notification::query()->where('user_id', (string) $this->developer->id)->sole()->type);

        // The members see what stopped it, no user named
        $this->signedIn($this->developer)->getJson("/api/projects/{$this->project->id}")->assertOk()
            ->assertJsonPath('data.stopped.system', 'cost_limit')
            ->assertJsonPath('data.stopped.by', null)
            ->assertJsonPath('data.stopped.level', 'organization')
            ->assertJsonPath('data.stopped.reason', 'Monthly cost limit reached');

        // Stopped already: a second stop changes nothing
        try {
            $stops->stopBySystem($project, 'cost_limit', 'Again');
            $this->fail('a stopped project must not be stopped again');
        } catch (ProjectStopConflict $e) {
            $this->assertSame('project_already_stopped', $e->errorCode);
        }
        $this->assertSame('Monthly cost limit reached', $this->project->fresh()->stop_reason);

        // An administrator's stop of a project is never a system stop
        $this->assertNull(ProjectStopService::systemOf($this->caasProject('other')));

        // The organization's stop level: an organization administrator resumes it
        $this->live->update(['status' => 'shut_down']);
        $this->signedIn($this->admin)->postJson("/api/projects/{$this->project->id}/resume")->assertOk()
            ->assertJsonPath('data.stopped', null);
        $this->assertNull(ProjectStopService::systemOf($this->project->fresh()));
    }
}
