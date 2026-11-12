<?php

namespace Tests\Feature\Projects;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Events\NotificationCreated;
use App\Events\ProjectResumed;
use App\Events\ProjectStopped;
use App\Mail\ResourceThresholdMail;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\GitDeploy\DeploymentStarter;
use App\Services\GitDeploy\ImportException;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\StoppedCapacity;
use App\Services\Projects\ProjectStopService;
use App\Services\ResourceThresholdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * A stopped project in a pay-as-you-go region (every region while billing is off) gives its
 * capacity reservation back (StoppedCapacity, docs/architecture.md → Region capacity and
 * admission): the region's budgets leave it out, a resume admits it again — a full region refuses
 * the resume's redeploy with capacity_exceeded and the project stays resumed without a
 * deployment, holding nothing, until a deploy fits — and the region shows, and alarms on, what it
 * would need with every stopped project back.
 */
class StoppedProjectCapacityTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private User $admin;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class, ProjectStopped::class, ProjectResumed::class]);

        $this->userWithRole('owner');
        $this->admin = $this->userWithRole('admin');
        // 1000m: always-on budget 1000m, FaaS budget 1000m × 3 = 3000m
        $this->region = KnativeRegion::query()->create([
            'name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'cluster_status' => 'online',
            'total_cpu_m' => 1000, 'total_memory_mb' => 100000,
        ]);
    }

    /** A scale-to-zero function of 250m (+ 100m queue-proxy: 350m of the FaaS budget) with a running version. */
    private function function(string $name, string $cpu = '250m', int $minScale = 0): Project
    {
        $project = Project::query()->create(['name' => $name, 'service_type' => 'caas', 'knative_region_id' => $this->region->id]);
        ContainerConfig::query()->create([
            'project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => $cpu, 'memory_mb' => 128,
            'timeout_seconds' => 30, 'trigger_type' => 'http', 'min_scale' => $minScale, 'scale_to_zero' => $minScale === 0,
        ]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => "run-{$name}-v1", 'deployed_by' => 'dev']);

        return $project->fresh();
    }

    private function faasUsed(): int
    {
        return RegionCapacity::usage($this->region->id)['burst']['cpu_m'];
    }

    /** Stopped the way an administrator stops it; its deployment torn down. */
    private function stop(Project $project): Project
    {
        app(ProjectStopService::class)->stop($project, $this->admin, 'paused for the test');
        Deployment::query()->where('project_id', $project->id)->update(['status' => 'shut_down']);

        return $project->fresh();
    }

    public function test_a_stopped_project_holds_nothing_of_a_pay_as_you_go_region(): void
    {
        $api = $this->function('api');
        $this->function('web');
        $this->assertSame(700, $this->faasUsed());

        $api = $this->stop($api);

        $this->assertTrue(StoppedCapacity::releases($api));
        $this->assertNull(StoppedCapacity::waiting($api), 'stopped: the stop banner says why, not the capacity one');
        $this->assertSame(350, $this->faasUsed(), 'the stopped project gives its pod of the FaaS budget back');
        $stopped = RegionCapacity::stopped($this->region->id);
        $this->assertSame(350, $stopped['demand']['burst']['cpu_m']);
        $this->assertSame([['id' => $api->id, 'name' => 'api', 'stopped' => true, 'cpu_m' => 350, 'memory_mb' => 228]], $stopped['projects']);

        // its room is free for others: an always-on project counts the same way
        $warm = $this->stop($this->function('warm', '250m', 1));
        $this->assertSame(0, RegionCapacity::usage($this->region->id)['hard']['cpu_m']);
        $this->assertSame(350, RegionCapacity::stopped($this->region->id)['demand']['hard']['cpu_m']);
        $this->assertNotNull($warm->stopped_at);
    }

    public function test_a_resume_that_fits_is_admitted_and_holds_its_reservation_again(): void
    {
        $api = $this->stop($this->function('api'));
        $this->assertSame(0, $this->faasUsed());

        $response = $this->signedIn($this->admin)->postJson("/api/projects/{$api->id}/resume")
            ->assertOk()->assertJsonPath('redeploy_code', null);

        $this->assertNotNull($response->json('deployment_id'));
        $this->assertNull($api->fresh()->capacity_released_at, 'admitted: the mark is gone');
        $this->assertSame(350, $this->faasUsed());
        $this->assertSame([], RegionCapacity::stopped($this->region->id)['projects']);
    }

    public function test_a_full_region_refuses_the_resume_and_the_project_waits_for_capacity(): void
    {
        $api = $this->stop($this->function('api'));
        // the freed room is taken: 8 × 350m = 2800m of the 3000m FaaS budget
        $others = [];
        foreach (range(1, 8) as $i) {
            $others[$i] = $this->function("fn-{$i}");
        }
        $this->assertSame(2800, $this->faasUsed());

        $response = $this->signedIn($this->admin)->postJson("/api/projects/{$api->id}/resume")
            ->assertOk()
            ->assertJsonPath('deployment_id', null)
            ->assertJsonPath('data.stopped', null)
            ->assertJsonPath('redeploy_code', StoppedCapacity::CAPACITY_EXCEEDED);
        $this->assertStringContainsString('Region eu-1 has no room for this project again', (string) $response->json('redeploy_error'));
        $this->assertStringContainsString('this needs 350m, 200m of 3000m are free', (string) $response->json('redeploy_error'));

        // resumed without a deployment, still holding nothing — and still counted as coming back
        $api = $api->fresh();
        $this->assertFalse($api->isStopped());
        $this->assertNotNull($api->capacity_released_at);
        $this->assertSame(0, Deployment::query()->where('project_id', $api->id)->whereIn('status', Deployment::LIVE)->count());
        $this->assertSame(2800, $this->faasUsed());
        $this->assertSame([['id' => $api->id, 'name' => 'api', 'stopped' => false, 'cpu_m' => 350, 'memory_mb' => 228]], RegionCapacity::stopped($this->region->id)['projects']);

        // the project page says why it waits and what it lacks
        $waiting = $this->signedIn($this->admin)->getJson("/api/projects/{$api->id}")->assertOk()->json('data.capacity_waiting');
        $this->assertSame(StoppedCapacity::CAPACITY_EXCEEDED, $waiting['code']);
        $this->assertSame($this->region->id, $waiting['region_id']);
        $this->assertSame(['cpu_m' => ['budget' => 'faas', 'needed' => 350, 'available' => 200, 'missing' => 150]], $waiting['shortfall']);
        $this->assertNotNull($waiting['since']);
        $this->assertStringContainsString('Region eu-1 has no room', $waiting['message']);

        // a git push is refused the same way
        try {
            app(DeploymentStarter::class)->start($api, Version::query()->where('project_id', $api->id)->firstOrFail(), 'git');
            $this->fail('a deploy that does not fit must be refused');
        } catch (ImportException $e) {
            $this->assertSame(StoppedCapacity::CAPACITY_EXCEEDED, $e->errorCode);
        }

        // and a console deploy: 422 with the code; nor is its old deployment set live by hand
        $version = Version::query()->where('project_id', $api->id)->firstOrFail();
        $this->signedIn($this->admin)->postJson("/api/projects/{$api->id}/deploy", ['version_id' => $version->id])
            ->assertStatus(422)->assertJsonPath('code', StoppedCapacity::CAPACITY_EXCEEDED)->assertJsonPath('region_id', $this->region->id);
        $old = Deployment::query()->where('project_id', $api->id)->firstOrFail();
        $this->signedIn($this->admin)->patchJson("/api/projects/{$api->id}/deployments/{$old->id}/status", ['status' => 'active'])
            ->assertStatus(422)->assertJsonPath('code', StoppedCapacity::CAPACITY_EXCEEDED);
        $this->assertSame('shut_down', $old->fresh()->status);

        // once there is room the page says it fits; a deploy admits it and it holds its reservation again
        $others[1]->delete();
        $this->signedIn($this->admin)->getJson("/api/projects/{$api->id}")->assertOk()
            ->assertJsonPath('data.capacity_waiting.code', null)->assertJsonPath('data.capacity_waiting.shortfall', null);
        $this->signedIn($this->admin)->postJson("/api/projects/{$api->id}/deploy", ['version_id' => $version->id])->assertSuccessful();
        $this->signedIn($this->admin)->getJson("/api/projects/{$api->id}")->assertOk()->assertJsonPath('data.capacity_waiting', null);
        $this->assertNull($api->fresh()->capacity_released_at);
        $this->assertSame(2800, $this->faasUsed());
    }

    public function test_the_capacity_endpoint_shows_the_region_with_its_stopped_projects_back(): void
    {
        foreach (range(1, 6) as $i) {
            $this->function("fn-{$i}");
        }
        foreach (['old-a', 'old-b', 'old-c', 'old-d'] as $name) {
            $this->stop($this->function($name, $name === 'old-a' ? '500m' : '250m'));
        }

        $data = $this->signedIn($this->admin)->getJson("/api/knative-regions/{$this->region->id}/capacity")->assertOk()->json('data');

        // today: 6 × 350m = 2100m of 3000m
        $this->assertSame(2100, $data['cpu_m']['faas_used']);
        $this->assertEquals(70.0, $data['cpu_m']['utilization']);

        // with the 4 stopped projects back: + 600m + 3 × 350m = 3750m of 3000m
        $with = $data['with_stopped'];
        $this->assertSame(4, $with['projects']);
        $this->assertFalse($with['held']);
        $this->assertSame(['warning' => 60, 'critical' => 80], $with['thresholds']);
        $this->assertSame(1650, $with['cpu_m']['stopped_faas']);
        $this->assertSame(0, $with['cpu_m']['stopped_always_on']);
        $this->assertSame(3750, $with['cpu_m']['faas_used']);
        $this->assertEquals(125.0, $with['cpu_m']['utilization']);
        $this->assertSame('faas', $with['cpu_m']['budget']);
        $this->assertSame(750, $with['cpu_m']['exceeded_by']);
        $this->assertSame('critical', $with['cpu_m']['level']);
        // under 80 %: 3750m ≤ capacity × 3 × 0.8 → capacity 1563m, 563m more
        $this->assertSame(563, $with['cpu_m']['add_capacity']);
        $this->assertNull($with['memory_mb']['level']);
        $this->assertSame('old-a', $with['stopped_projects'][0]['name'], 'the largest first');
        $this->assertCount(4, $with['stopped_projects']);
    }

    public function test_the_alarm_tells_the_provider_to_add_nodes_when_the_stopped_projects_would_cross_its_level(): void
    {
        Mail::fake();
        Event::fake([NotificationCreated::class]);
        Cache::flush();

        // 4 × 350m = 1400m of 3000m (47 %): no alarm on what holds the region
        foreach (range(1, 4) as $i) {
            $this->function("fn-{$i}");
        }
        foreach (range(1, 5) as $i) {
            $this->stop($this->function("old-{$i}"));
        }

        app(ResourceThresholdService::class)->checkAllRegions();

        $alarms = Notification::query()->where('type', 'resource_threshold')->get();
        $this->assertCount(1, $alarms, 'only the alarm with the stopped projects back');
        $alarm = $alarms->sole();
        // 1400m + 5 × 350m = 3150m of 3000m: 105 %
        $this->assertSame('critical', $alarm->level);
        $this->assertNull($alarm->user_id, 'shared: every user of a single-tenant installation sees it');
        $this->assertSame(ResourceThresholdService::WITH_STOPPED, $alarm->data['basis']);
        $this->assertSame('cpu', $alarm->data['resource_type']);
        $this->assertEquals(105.0, $alarm->data['percentage']);
        $this->assertSame(80, $alarm->data['threshold']);
        $this->assertSame(5, $alarm->data['stopped_projects']);
        $this->assertSame(1750, $alarm->data['stopped']);
        $this->assertSame(150, $alarm->data['exceeded_by']);
        // 3150m ≤ capacity × 3 × 0.8 → 1313m: 313m more
        $this->assertSame(313, $alarm->data['add_capacity']);
        $this->assertSame('eu-1: CPU 105% with stopped projects', $alarm->title);
        $this->assertStringContainsString('If its 5 stopped projects (1750m of CPU in total) were resumed at the same time', $alarm->body);
        $this->assertStringContainsString('150m more than it has', $alarm->body);
        $this->assertStringContainsString('Add nodes with at least 313m of CPU to stay under 80 %', $alarm->body);
        $this->assertStringContainsString('old-1 (350m)', $alarm->body);
        Event::assertDispatched(NotificationCreated::class, fn ($e) => $e->notification->is($alarm));
        Mail::assertQueued(ResourceThresholdMail::class, fn (ResourceThresholdMail $mail) => $mail->withStopped && count($mail->projects) === 5 && str_contains($mail->envelope()->subject, 'with stopped projects'));

        // once an hour per region, resource and level
        app(ResourceThresholdService::class)->checkAllRegions();
        $this->assertSame(1, Notification::query()->where('type', 'resource_threshold')->count());
    }

    public function test_no_alarm_while_the_region_with_its_stopped_projects_stays_under_the_levels(): void
    {
        Mail::fake();
        Cache::flush();

        // 2 × 350m + 2 stopped × 350m = 1400m of 3000m: 47 %
        $this->function('fn-1');
        $this->function('fn-2');
        $this->stop($this->function('old-1'));
        $this->stop($this->function('old-2'));

        app(ResourceThresholdService::class)->checkAllRegions();

        $this->assertSame(0, Notification::query()->where('type', 'resource_threshold')->count());
        Mail::assertNothingQueued();

        // the warning level (60 %) is an alarm of its own: 1400m + 2 × 350m = 2100m: 70 %
        $this->stop($this->function('old-3'));
        $this->stop($this->function('old-4'));
        app(ResourceThresholdService::class)->checkAllRegions();
        $alarm = Notification::query()->where('type', 'resource_threshold')->sole();
        $this->assertSame('warning', $alarm->level);
        $this->assertSame(60, $alarm->data['threshold']);
        $this->assertSame(0, $alarm->data['exceeded_by']);
        $this->assertStringContainsString('would be 70 % full (threshold 60 %)', $alarm->body);
    }

    /**
     * A stop, a resume and the deploy that admits the project again change what the region holds
     * and what the project page says, though each writes the project with a guarded query update
     * (no model event): the answers the API cached (ResponseCache, 60 s) must not outlive them. The
     * capacity card read the region before the stop for up to a minute, and the project page did
     * not show the capacity_waiting banner after a refused resume.
     */
    public function test_the_cached_answers_follow_a_stop_a_refused_resume_and_the_readmission(): void
    {
        $api = $this->function('api');
        $capacity = fn () => $this->signedIn($this->admin)->getJson("/api/knative-regions/{$this->region->id}/capacity")->assertOk();
        $page = fn () => $this->signedIn($this->admin)->getJson("/api/projects/{$api->id}")->assertOk();

        $this->assertSame(350, $capacity()->json('data.cpu_m.faas_used'));
        $this->assertSame('HIT', $capacity()->headers->get('X-Cache'), 'the capacity answer is cached');

        // the stop: the region holds nothing of it at once
        $this->signedIn($this->admin)->postJson("/api/projects/{$api->id}/stop", ['reason' => 'paused for the test'])->assertOk();
        // its teardown (the queued job, faked here) as the stop() helper does it
        Deployment::query()->where('project_id', $api->id)->update(['status' => 'shut_down']);
        $data = $capacity()->json('data');
        $this->assertSame(0, $data['cpu_m']['faas_used'], 'the stop gave the reservation back');
        $this->assertSame(1, $data['with_stopped']['projects']);
        $this->assertSame(350, $data['with_stopped']['cpu_m']['stopped_faas']);

        // the freed room is taken; the region and the page are read (and cached) before the resume
        $others = [];
        foreach (range(1, 8) as $i) {
            $others[$i] = $this->function("fn-{$i}");
        }
        $this->assertTrue($capacity()->json('data.with_stopped.stopped_projects.0.stopped'));
        $page()->assertJsonPath('data.capacity_waiting', null);
        $this->assertNotNull($page()->json('data.stopped'));

        // a refused resume: the page shows the banner and the region the project as resumed at once
        $this->signedIn($this->admin)->postJson("/api/projects/{$api->id}/resume")->assertOk()
            ->assertJsonPath('deployment_id', null)->assertJsonPath('redeploy_code', StoppedCapacity::CAPACITY_EXCEEDED);
        $page()->assertJsonPath('data.stopped', null)
            ->assertJsonPath('data.capacity_waiting.code', StoppedCapacity::CAPACITY_EXCEEDED);
        $this->assertFalse($capacity()->json('data.with_stopped.stopped_projects.0.stopped'));

        // room again: the deploy that admits it shows in the region's figures at once
        $others[1]->delete();
        $this->assertSame(2450, $capacity()->json('data.cpu_m.faas_used'));
        $version = Version::query()->where('project_id', $api->id)->firstOrFail();
        $this->signedIn($this->admin)->postJson("/api/projects/{$api->id}/deploy", ['version_id' => $version->id])->assertSuccessful();
        $data = $capacity()->json('data');
        $this->assertSame(2800, $data['cpu_m']['faas_used'], 'admitted: it holds its reservation again');
        $this->assertSame(0, $data['with_stopped']['projects']);
        $page()->assertJsonPath('data.capacity_waiting', null);
    }

    /**
     * A project's size and scaling (its xaas / faas / container config) are what it reserves: a
     * resize or a move to always-on reaches the region's cached capacity answer at once.
     */
    public function test_the_cached_capacity_answer_follows_a_resize_and_a_scaling_change(): void
    {
        $api = $this->function('api');
        $capacity = fn () => $this->signedIn($this->admin)->getJson("/api/knative-regions/{$this->region->id}/capacity")->assertOk();

        $this->assertSame(350, $capacity()->json('data.cpu_m.faas_used'));
        $this->assertSame('HIT', $capacity()->headers->get('X-Cache'));

        // 250m → 400m: its FaaS pod reserves 400m + 100m queue-proxy
        $this->signedIn($this->admin)->putJson("/api/projects/{$api->id}", ['config' => ['cpu' => '400m']])->assertOk();
        $this->assertSame(500, $capacity()->json('data.cpu_m.faas_used'), 'the resize');

        // always on: one pod of the always-on budget, none of the FaaS budget
        $this->signedIn($this->admin)->putJson("/api/projects/{$api->id}", ['config' => ['scale_to_zero' => false, 'min_scale' => 1]])->assertOk();
        $data = $capacity()->json('data.cpu_m');
        $this->assertSame(500, $data['always_on_used'], 'the scaling change');
        $this->assertSame(0, $data['faas_used']);
    }
}
