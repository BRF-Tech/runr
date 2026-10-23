<?php

namespace Tests\Feature\Rightsizing;

use App\Models\AuditEvent;
use App\Models\DeploymentSession;
use App\Models\Project;
use App\Models\ResourcePeak;
use App\Models\RightsizingRecommendation;
use App\Models\User;
use App\Models\Version;
use App\Services\GitService;
use App\Services\Rightsizing\RightsizingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Rightsizing recommendations through the API (docs/monitoring.md#rightsizing): the nightly run
 * stores one per project with its evidence; members read the organization's list (largest saving
 * first, only projects whose metrics they may read) and a project's state; applying puts the size
 * into the config like a manual edit (same checks, a new version, the next deploy runs it);
 * dismissing hides it while the recommended size stays the same, snoozing for a number of days.
 */
class RightsizingApiTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, RightsizingFixtures;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 03:00:00');
        $this->owner = $this->userWithRole('owner');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function refresh(): int
    {
        return app(RightsizingService::class)->refresh();
    }

    /** A scale-to-zero function of 512 MiB / 500m that used 140 MiB / 80m at most for 14 days. */
    private function oversized(string $name = 'orders', array $peaks = []): Project
    {
        $project = $this->sizedProject($name, $this->realRegion($name.'-region'));
        $this->peaks($project, 14, $peaks);

        return $project;
    }

    private function recommendation(Project $project, array $attributes = []): RightsizingRecommendation
    {
        return RightsizingRecommendation::create($attributes + [
            'project_id' => $project->id,
            'region_id' => $project->knative_region_id,
            'status' => RightsizingRecommendation::OPEN,
            'direction' => 'down',
            'current_cpu_m' => 500,
            'current_memory_mb' => 512,
            'recommended_cpu_m' => 125,
            'recommended_memory_mb' => 256,
            'reasons' => ['memory_over_provisioned'],
            'evidence' => ['freed' => ['cpu_m' => 375, 'memory_mb' => 256, 'pods' => 1, 'always_on' => false]],
            'computed_at' => now(),
        ]);
    }

    public function test_the_nightly_run_stores_a_recommendation_with_its_evidence(): void
    {
        $project = $this->oversized();

        $this->assertSame(1, $this->refresh());

        $response = $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}/rightsizing")->assertOk();
        $response->assertJsonPath('status', 'open')
            ->assertJsonPath('pending_deploy', false)
            ->assertJsonPath('data.direction', 'down')
            ->assertJsonPath('data.current', ['cpu_m' => 500, 'memory_mb' => 512])
            ->assertJsonPath('data.recommended', ['cpu_m' => 125, 'memory_mb' => 256])
            ->assertJsonPath('data.changes', ['cpu' => 'down', 'memory' => 'down'])
            ->assertJsonPath('data.reasons', ['memory_over_provisioned', 'cpu_over_provisioned'])
            ->assertJsonPath('data.evidence.window_days', 14)
            ->assertJsonPath('data.evidence.days', 14)
            ->assertJsonPath('data.evidence.from', '2026-10-01')
            ->assertJsonPath('data.evidence.to', '2026-10-14')
            ->assertJsonPath('data.evidence.memory_max_mb', 140)
            ->assertJsonPath('data.evidence.cpu_p95_m', 80)
            ->assertJsonPath('data.freed.memory_mb', 256)
            ->assertJsonPath('data.freed.cpu_m', 375)
            ->assertJsonPath('data.freed.always_on', false)
            ->assertJsonPath('data.savings', null)            // billing off: resources only
            ->assertJsonPath('data.blocked', null);

        // a second run keeps the one recommendation
        $this->refresh();
        $this->assertSame(1, RightsizingRecommendation::count());
    }

    public function test_the_project_endpoint_tells_why_there_is_none(): void
    {
        $region = $this->realRegion();

        $young = $this->sizedProject('young', $region);
        $this->peaks($young, 2);
        $this->signedIn($this->owner)->getJson("/api/projects/{$young->id}/rightsizing")->assertOk()
            ->assertJsonPath('status', 'collecting')
            ->assertJsonPath('data', null)
            ->assertJsonPath('collecting.days', 2)
            ->assertJsonPath('collecting.required_days', 3);

        $fit = $this->sizedProject('fit', $region);
        $this->peaks($fit, 7, ['memory_max_mb' => 400.0, 'cpu_p95_m' => 380]);
        $this->signedIn($this->owner)->getJson("/api/projects/{$fit->id}/rightsizing")->assertOk()
            ->assertJsonPath('status', 'right_sized');

        $stopped = $this->sizedProject('paused', $region);
        $stopped->forceFill(['stopped_at' => now()])->saveQuietly();
        $this->signedIn($this->owner)->getJson("/api/projects/{$stopped->id}/rightsizing")->assertOk()
            ->assertJsonPath('status', 'not_eligible')
            ->assertJsonPath('reason', 'stopped');

        $idle = Project::create(['name' => 'idle', 'service_type' => 'faas']);
        $idle->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        $this->signedIn($this->owner)->getJson("/api/projects/{$idle->id}/rightsizing")->assertOk()
            ->assertJsonPath('reason', 'not_running');

        $demo = $this->sizedProject('showcase', $this->realRegion('demo', ['is_demo' => true]));
        $this->signedIn($this->owner)->getJson("/api/projects/{$demo->id}/rightsizing")->assertOk()
            ->assertJsonPath('reason', 'no_metrics');
    }

    public function test_sessions_decide_for_a_scale_to_zero_function(): void
    {
        $project = $this->sizedProject('rare', $this->realRegion());
        $this->peaks($project, 5, ['pods' => 1]);

        $this->assertSame(0, $this->refresh(), '5 pods in 5 days: too few sessions');
        $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}/rightsizing")
            ->assertJsonPath('status', 'collecting')
            ->assertJsonPath('collecting.sessions', 5)
            ->assertJsonPath('collecting.required_sessions', 20);

        for ($i = 0; $i < 20; $i++) {
            DeploymentSession::create(['project_id' => $project->id, 'deployment_id' => $project->activeDeployment->id,
                'region_id' => $project->knative_region_id, 'entry_point' => 'http', 'started_at' => now()->subDays(2), 'pod_count' => 1]);
        }
        $this->assertSame(1, $this->refresh());
    }

    public function test_the_organization_list_is_sorted_by_saving_and_filtered_by_access(): void
    {
        $region = $this->realRegion();
        $small = $this->recommendation($this->sizedProject('small', $region), ['monthly_savings' => 5.0, 'currency' => 'USD', 'savings' => ['basis' => 'reserved', 'monthly' => 5.0, 'currency' => 'USD']]);
        $big = $this->recommendation($this->sizedProject('big', $region), ['monthly_savings' => 12.5, 'currency' => 'USD', 'savings' => ['basis' => 'reserved', 'monthly' => 12.5, 'currency' => 'USD']]);
        $this->recommendation($this->sizedProject('usage', $region), ['evidence' => ['freed' => ['memory_mb' => 1024, 'cpu_m' => 0]]]);
        $this->recommendation($this->sizedProject('tiny', $region), ['evidence' => ['freed' => ['memory_mb' => 256, 'cpu_m' => 0]]]);
        $this->recommendation($this->sizedProject('later', $region), ['status' => RightsizingRecommendation::SNOOZED, 'snoozed_until' => now()->addDays(3)]);
        $this->recommendation($this->sizedProject('woken', $region), ['status' => RightsizingRecommendation::SNOOZED, 'snoozed_until' => now()->subDay(),
            'evidence' => ['freed' => ['memory_mb' => 0, 'cpu_m' => 0]]]);
        $this->recommendation($this->sizedProject('no', $region), ['status' => RightsizingRecommendation::DISMISSED]);
        // stale: the project was resized after the recommendation
        $resized = $this->sizedProject('resized', $region);
        $this->recommendation($resized);
        $resized->faasConfig->update(['memory_mb' => 384]);

        $response = $this->signedIn($this->owner)->getJson('/api/rightsizing')->assertOk();

        $this->assertSame(['big', 'small', 'usage', 'tiny', 'woken'], array_column(array_column($response->json('data'), 'project'), 'name'),
            'money first, then the memory freed; snoozed until a past day reads as open');
        $this->assertSame('open', $response->json('data.4.status'));
        $response->assertJsonPath('summary.count', 5)
            ->assertJsonPath('summary.monthly_savings', 17.5)
            ->assertJsonPath('summary.currency', 'USD');

        // a member with access to one project only
        $member = $this->userWithRole('billing');
        $this->grantProject($member, $small->project, 'developer');
        $this->signedIn($member)->getJson('/api/rightsizing')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.project.name', 'small');
        $this->signedIn($member)->getJson("/api/projects/{$big->project_id}/rightsizing")->assertForbidden();
    }

    public function test_apply_saves_the_size_like_a_manual_edit(): void
    {
        $this->mock(GitService::class, fn ($git) => $git->shouldReceive('writeFiles')->andReturn('sha-rightsizing'));
        $project = $this->oversized();
        $this->refresh();

        $response = $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/rightsizing/apply")->assertOk();

        $response->assertJsonPath('data.status', 'applied')
            ->assertJsonPath('version.label', 'v2')
            ->assertJsonPath('deploy_required', true);
        $this->assertStringContainsString('v2', $response->json('message'));

        $config = $project->faasConfig->fresh();
        $this->assertSame('125m', $config->cpu);
        $this->assertSame(256, (int) $config->memory_mb);
        $version = Version::query()->where('project_id', $project->id)->where('number', 2)->sole();
        $this->assertSame('Rightsizing: memory 512 MB → 256 MB, CPU 500m → 125m', $version->commit_message);
        $this->assertSame((string) $this->owner->id, $version->created_by);

        $recommendation = RightsizingRecommendation::sole();
        $this->assertSame($version->id, $recommendation->applied_version_id);
        $this->assertSame((string) $this->owner->id, $recommendation->decided_by);

        $event = AuditEvent::where('action', 'project.rightsizing.apply')->sole();
        $this->assertSame($project->id, $event->target_id);
        $this->assertSame(['cpu_m' => 125, 'memory_mb' => 256], $event->metadata['recommended']);

        // the running deployment still has the old size until the next deploy
        $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}/rightsizing")
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('pending_deploy', true);

        // nothing left to apply; the next run finds the project right-sized and keeps the record
        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/rightsizing/apply")
            ->assertNotFound()->assertJsonPath('code', 'rightsizing_none');
        $this->refresh();
        $this->assertSame(RightsizingRecommendation::APPLIED, RightsizingRecommendation::sole()->status);
    }

    public function test_a_resized_project_is_not_applied(): void
    {
        $project = $this->oversized();
        $this->refresh();
        $project->faasConfig->update(['memory_mb' => 384]);

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/rightsizing/apply")->assertNotFound();
        $this->assertSame(384, (int) $project->faasConfig->fresh()->memory_mb);

        // stale but still stored: the service refuses it with 409
        try {
            app(RightsizingService::class)->apply(RightsizingRecommendation::sole(), $this->owner);
            $this->fail('a stale recommendation was applied');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $this->assertSame('rightsizing_stale', $e->getResponse()->getData(true)['code']);
        }
    }

    public function test_a_larger_size_the_region_cannot_hold_is_blocked_and_refused(): void
    {
        $this->mock(GitService::class, fn ($git) => $git->shouldReceive('writeFiles')->andReturn('sha'));
        $region = $this->realRegion('tight', ['total_memory_mb' => 700]);
        $project = $this->sizedProject('hungry', $region, alwaysOn: true);
        // killed for memory at its size: the next step (1024 MiB) does not fit 700 MiB
        $this->peaks($project, 5, ['memory_max_mb' => 505.0, 'oom_kills' => 1, 'cpu_p95_m' => 450]);

        $this->refresh();
        $response = $this->signedIn($this->owner)->getJson("/api/projects/{$project->id}/rightsizing")->assertOk();
        $response->assertJsonPath('data.direction', 'up')
            ->assertJsonPath('data.recommended.memory_mb', 1024)
            ->assertJsonPath('data.reasons', ['memory_oom_killed'])
            ->assertJsonPath('data.blocked.code', 'resource_limit_exceeded');
        $this->assertNotSame('', $response->json('data.blocked.message'));

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/rightsizing/apply")
            ->assertStatus(422)->assertJsonValidationErrors('config.memory_mb');
        $this->assertSame(512, (int) $project->faasConfig->fresh()->memory_mb);
        $this->assertSame(RightsizingRecommendation::OPEN, RightsizingRecommendation::sole()->status);
    }

    public function test_dismissed_until_the_size_changes_and_snoozed_for_days(): void
    {
        $project = $this->oversized();
        $this->refresh();

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/rightsizing/dismiss")->assertOk()
            ->assertJsonPath('data.status', 'dismissed');
        $this->signedIn($this->owner)->getJson('/api/rightsizing')->assertJsonCount(0, 'data');

        // the same recommendation again: stays dismissed
        $this->refresh();
        $this->assertSame(RightsizingRecommendation::DISMISSED, RightsizingRecommendation::sole()->status);

        // the window now calls for another size: open again
        ResourcePeak::query()->update(['memory_max_mb' => 60.0]);
        $this->refresh();
        $recommendation = RightsizingRecommendation::sole();
        $this->assertSame(RightsizingRecommendation::OPEN, $recommendation->status);
        $this->assertSame(128, $recommendation->recommended_memory_mb);
        $this->assertNull($recommendation->decided_by);

        // snoozed for a week
        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/rightsizing/dismiss", ['snooze_days' => 7])->assertOk()
            ->assertJsonPath('data.status', 'snoozed')
            ->assertJsonPath('data.snoozed_until', now()->addDays(7)->toIso8601String());
        $this->signedIn($this->owner)->getJson('/api/rightsizing')->assertJsonCount(0, 'data');
        $this->refresh();
        $this->assertSame(RightsizingRecommendation::SNOOZED, RightsizingRecommendation::sole()->status, 'still snoozed');

        Carbon::setTestNow(now()->addDays(8));
        $this->signedIn($this->owner)->getJson('/api/rightsizing')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'open');

        $this->signedIn($this->owner)->postJson("/api/projects/{$project->id}/rightsizing/dismiss", ['snooze_days' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('snooze_days');
    }

    public function test_reading_needs_metrics_view_and_applying_projects_update(): void
    {
        $project = $this->oversized();
        $this->refresh();
        $viewer = $this->userWithRole('viewer');

        $this->signedIn($viewer)->getJson('/api/rightsizing')->assertOk()->assertJsonCount(1, 'data');
        $this->signedIn($viewer)->getJson("/api/projects/{$project->id}/rightsizing")->assertOk();
        $this->signedIn($viewer)->postJson("/api/projects/{$project->id}/rightsizing/apply")->assertForbidden();
        $this->signedIn($viewer)->postJson("/api/projects/{$project->id}/rightsizing/dismiss")->assertForbidden();

        $billing = $this->userWithRole('billing');
        $this->signedIn($billing)->getJson('/api/rightsizing')->assertForbidden();
        $this->assertSame(RightsizingRecommendation::OPEN, RightsizingRecommendation::sole()->status);
    }

    public function test_a_project_that_stops_running_loses_its_open_recommendation(): void
    {
        $project = $this->oversized();
        $this->refresh();
        $project->forceFill(['stopped_at' => now()])->saveQuietly();

        $this->refresh();

        $this->assertSame(0, RightsizingRecommendation::count());
    }
}
