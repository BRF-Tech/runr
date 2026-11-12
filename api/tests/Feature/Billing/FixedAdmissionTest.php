<?php

namespace Tests\Feature\Billing;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\Git\PreviewEnvironment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use App\Services\Environments\DeployTarget;
use App\Services\GitService;
use App\Services\Helpers\StoppedCapacity;
use App\Services\Projects\ProjectStopService;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Services\Admission;
use Modules\Billing\Services\FixedRequests;
use Tests\Concerns\ActsWithRoles;
use Tests\Feature\Billing\Concerns\EnablesBilling;
use Tests\TestCase;

/**
 * Fixed projects are hard-limited to their approved resources (BillingPolicy decision 2): no
 * deploy without an allocation, nothing above it; the allocation ends with the project's move.
 */
class FixedAdmissionTest extends TestCase
{
    use ActsWithRoles, EnablesBilling, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->setUpBilling();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownBilling();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sized(Project $project, string $cpu, int $memoryMb, int $minScale = 1): Project
    {
        ContainerConfig::query()->create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'timeout_seconds' => 30,
            'trigger_type' => 'http',
            'min_scale' => $minScale,
            'scale_to_zero' => false,
        ]);

        return $project->fresh();
    }

    private function refusal(Project $project, ?DeployIntent $intent = null): ?array
    {
        try {
            ProjectAdmission::assertDeployable($project, $intent);
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getData(true);
        }

        return null;
    }

    public function test_a_fixed_project_deploys_only_within_its_allocation(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);

        $this->assertSame('fixed_allocation_required', $this->refusal($project)['code'] ?? null);

        // Too small for what the project reserves (size + queue-proxy)
        $small = $this->allocation($project, 100, 128, '2026-10-01');
        $this->assertSame('fixed_allocation_exceeded', $this->refusal($project)['code'] ?? null);

        $small->forceFill(['status' => FixedRequest::SUPERSEDED, 'active_until' => '2026-10-09'])->save();
        $this->allocation($project, 4000, 8192, '2026-10-10');
        $this->assertNull($this->refusal($project));
    }

    public function test_a_pay_as_you_go_project_is_not_limited(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('pg'), $owner), '500m', 512);

        $this->assertNull($this->refusal($project));
    }

    public function test_the_config_limiter_refuses_changes_above_the_allocation(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);
        $region = $project->knative_region_id;

        // Without an allocation the deploy guard decides
        $this->assertNull(ProjectAdmission::configProblem($project, $region, ['cpu' => '9']));

        // 2 vCPU: three pods of 500m + queue-proxy fit, four do not
        $this->allocation($project, 2000, 4096, '2026-10-01');
        $this->assertNull(ProjectAdmission::configProblem($project, $region, ['max_scale' => 3]));
        $this->assertStringContainsString('2400 m CPU', (string) ProjectAdmission::configProblem($project, $region, ['max_scale' => 4]));
        $this->assertNotNull(ProjectAdmission::configProblem($project, $region, ['memory_mb' => 4096]));
        // A move to another region ends the allocation: not limited here
        $this->assertNull(ProjectAdmission::configProblem($project, $this->billingRegion('pg')->id, ['cpu' => '9']));

        // Through the API: the project update answers 422
        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('writeFiles')->andReturn('abc123'));
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}", ['config' => ['max_scale' => 10]])
            ->assertStatus(422)->assertJsonValidationErrors('config')->assertJsonPath('code', 'fixed_allocation_exceeded');
        $this->signedIn($owner)->putJson("/api/projects/{$project->id}", ['config' => ['max_scale' => 3]])->assertOk();
    }

    public function test_the_worst_case_up_to_max_scale_must_fit(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);
        $project->containerConfig->update(['max_scale' => 5]);
        $this->allocation($project, 1000, 1024, '2026-10-01');

        // min_scale 1 fits (600m), max_scale 5 does not (3000m)
        $refusal = $this->refusal($project->fresh());
        $this->assertSame('fixed_allocation_exceeded', $refusal['code'] ?? null);
        $this->assertStringContainsString('5 pods', $refusal['message']);

        $project->containerConfig->update(['max_scale' => 1]);
        $this->assertNull($this->refusal($project->fresh()));

        // Without a max_scale the worst case is unbounded
        $project->containerConfig->update(['max_scale' => 0]);
        $this->assertSame('fixed_max_scale_required', $this->refusal($project->fresh())['code'] ?? null);
    }

    public function test_deploy_overrides_are_part_of_the_worst_case(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);
        $project->containerConfig->update(['max_scale' => 1]);
        $this->allocation($project, 1000, 1024, '2026-10-01');
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        // min_scale alone (10 × 600m) used to pass: only a deploy_config with cpu reached the limiter
        // (with a max_scale that holds it: min_scale may not pass max_scale)
        $this->signedIn($owner)->postJson("/api/projects/{$project->id}/deploy", [
            'version_id' => $version->id, 'deploy_config' => ['min_scale' => 10, 'max_scale' => 10, 'scale_to_zero' => false],
        ])->assertStatus(422)->assertJsonPath('code', 'fixed_allocation_exceeded');
        $this->signedIn($owner)->postJson("/api/projects/{$project->id}/deploy", [
            'version_id' => $version->id, 'deploy_config' => ['max_scale' => 4],
        ])->assertStatus(422)->assertJsonPath('code', 'fixed_allocation_exceeded');
        $this->assertSame(0, $project->deployments()->count());

        $this->signedIn($owner)->postJson("/api/projects/{$project->id}/deploy", [
            'version_id' => $version->id, 'deploy_config' => ['cpu' => '250m', 'memory_mb' => 256, 'max_scale' => 2],
        ])->assertStatus(202);
    }

    public function test_every_refusal_through_the_api_answers_its_code_and_message(): void
    {
        Setting::set('gpu_demo_enabled', 'true'); // GPUs on the demo region (GpuPolicy)
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $deploy = function (Project $project, array $deployConfig = []) use ($owner) {
            $version = Version::query()->firstOrCreate(['project_id' => $project->id, 'number' => 1], ['created_by' => 'dev', 'build_status' => 'ready']);

            return $this->signedIn($owner)->postJson("/api/projects/{$project->id}/deploy", array_filter([
                'version_id' => $version->id, 'deploy_config' => $deployConfig ?: null,
            ]));
        };
        $refused = function ($response, string $code, string $needle) {
            $response->assertStatus(422)->assertJsonPath('code', $code);
            // above the allocation also the region and what each short resource lacks
            $shape = $code === 'fixed_allocation_exceeded' ? ['code', 'message', 'region_id', 'shortfall'] : ['code', 'message'];
            $this->assertSame($shape, collect($response->json())->keys()->sort()->values()->all(), 'the shape of every refusal');
            $this->assertStringContainsString($needle, $response->json('message'));
        };

        // No allocation
        $cpu = $this->sized($this->billedProject('cpu', $region, $owner), '500m', 512);
        $refused($deploy($cpu), 'fixed_allocation_required', 'request resources');

        // CPU above the allocation: (500m + 100m queue-proxy) × 1 pod against 100m
        $this->allocation($cpu, 100, 4096, '2026-10-01');
        $this->assertSame(['cpu_m' => ['needed' => 600, 'available' => 100, 'missing' => 500]], $deploy($cpu)->json('shortfall'));
        $refused($deploy($cpu), 'fixed_allocation_exceeded', '600 m CPU');
        // ... also when only an override raises it
        $small = $this->sized($this->billedProject('small', $region, $owner), '100m', 128);
        $this->allocation($small, 1000, 1024, '2026-10-01');
        $refused($deploy($small, ['cpu' => '2']), 'fixed_allocation_exceeded', '2100 m CPU');

        // Memory above the allocation
        $memory = $this->sized($this->billedProject('memory', $region, $owner), '500m', 512);
        $this->allocation($memory, 4000, 128, '2026-10-01');
        $refused($deploy($memory), 'fixed_allocation_exceeded', '612 MB memory');

        // GPUs above the allocation
        $gpu = $this->sized($this->billedProject('gpu', $region, $owner), '500m', 512);
        $gpu->containerConfig->update(['gpu' => ['count' => 1]]);
        $this->allocation($gpu, 4000, 4096, '2026-10-01');
        $refused($deploy($gpu->fresh()), 'fixed_allocation_exceeded', '1 GPU');

        // Without max_scale
        $unbounded = $this->sized($this->billedProject('unbounded', $region, $owner), '100m', 128);
        $unbounded->containerConfig->update(['max_scale' => 0]);
        $this->allocation($unbounded, 4000, 4096, '2026-10-01');
        $refused($deploy($unbounded->fresh()), 'fixed_max_scale_required', 'max_scale');

        // A config change: the same code and message, and the validation error on config
        $this->mock(GitService::class, fn ($mock) => $mock->shouldReceive('writeFiles')->andReturn('abc123'));
        $this->signedIn($owner)->putJson("/api/projects/{$small->id}", ['config' => ['max_scale' => 20]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'fixed_allocation_exceeded')
            ->assertJsonPath('message', fn ($message) => str_contains((string) $message, '4000 m CPU'))
            ->assertJsonValidationErrors('config');
        $this->assertSame(0, Deployment::query()->count(), 'nothing was deployed');
    }

    public function test_gpus_count_for_every_pod_up_to_max_scale(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);
        $project->containerConfig->update(['max_scale' => 2, 'gpu' => ['count' => 1]]);
        $this->allocation($project, 4000, 4096, '2026-10-01', null, FixedRequest::APPROVED, 1);

        $refusal = $this->refusal($project->fresh());
        $this->assertSame('fixed_allocation_exceeded', $refusal['code'] ?? null);
        $this->assertStringContainsString('2 GPU', $refusal['message']);
    }

    public function test_a_resume_is_held_to_an_allocation_that_shrank_while_stopped(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);
        $big = $this->allocation($project, 4000, 4096, '2026-10-01');
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'x']);

        app(ProjectStopService::class)->stop($project, $owner, 'unpaid bill');
        $big->forceFill(['status' => FixedRequest::SUPERSEDED, 'active_until' => '2026-10-09'])->save();
        $this->allocation($project, 100, 128, '2026-10-10');

        $deployment = app(ProjectStopService::class)->resume($project->fresh(), $owner, $refusal, $code);

        $this->assertNull($deployment, 'nothing above the allocation is deployed');
        $this->assertStringContainsString('approved resources are 100 m CPU', (string) $refusal);
        $this->assertSame('fixed_allocation_exceeded', $code);
        $this->assertFalse($project->fresh()->isStopped(), 'the stop is lifted; the owner scales down and deploys');
        $this->assertSame(0, $project->deployments()->whereIn('status', ['pending', 'running', 'active'])->count());
    }

    public function test_previews_count_against_the_allocation(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);
        $project->containerConfig->update(['max_scale' => 1]);
        $this->allocation($project, 1500, 2048, '2026-10-01');
        $this->assertNull($this->refusal($project->fresh()));
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'run-api-v1']);

        // A live preview (up to its max_scale, one pod here) takes 600m next to production's 600m
        $preview = PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 7, 'provider' => 'github', 'status' => PreviewEnvironment::READY, 'service_name' => 'api-pr-7']);
        $this->assertNull($this->refusal($project->fresh()));
        $this->assertNull($this->refusal($project->fresh(), DeployIntent::preview((string) $preview->id)), 'a redeploy of the same preview counts it once');

        // A second preview does not fit 1500m
        $this->assertSame('fixed_allocation_exceeded', $this->refusal($project->fresh(), DeployIntent::preview(''))['code'] ?? null);
    }

    /** Every environment of the project in the fixed region counts (docs/environments.md). */
    public function test_environments_count_against_the_allocation(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->sized($this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner), '500m', 512);
        $project->containerConfig->update(['max_scale' => 1]);
        $this->allocation($project, 1500, 2048, '2026-10-01');
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'run-api-v1']);

        // Staging runs 600m (500m + queue-proxy, one pod) next to production's 600m
        $staging = ProjectEnvironment::query()->create(['project_id' => $project->id, 'name' => 'staging', 'workload_name' => 'api-staging']);
        Deployment::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'run-api-staging-v1']);
        $this->assertNull($this->refusal($project->fresh()), 'production 600m + staging 600m fit 1500m');
        $this->assertNull($this->refusal(DeployTarget::environment($project->fresh(), $staging), DeployIntent::production(null, $staging->id)), 'staging is counted once');

        // A third workload does not fit
        $qa = ProjectEnvironment::query()->create(['project_id' => $project->id, 'name' => 'qa', 'workload_name' => 'api-qa']);
        $refusal = $this->refusal(DeployTarget::environment($project->fresh(), $qa), DeployIntent::production(null, $qa->id));
        $this->assertSame('fixed_allocation_exceeded', $refusal['code'] ?? null);
        $this->assertStringContainsString('1800', (string) ($refusal['message'] ?? ''));
    }

    public function test_previews_count_with_the_project_config_not_a_production_deploys_overrides(): void
    {
        $owner = $this->userWithRole('owner');
        $project = $this->billedProject('api', $this->billingRegion('fx', 'fixed'), $owner);
        ContainerConfig::query()->create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '2000m', 'memory_mb' => 256,
            'timeout_seconds' => 30, 'trigger_type' => 'http', 'min_scale' => 1, 'max_scale' => 1, 'scale_to_zero' => false]);
        $project = $project->fresh();
        $this->allocation($project, 2500, 4096, '2026-10-01');

        // A live preview runs with the project's config: 2000m + 100m queue-proxy, one pod
        PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 7, 'provider' => 'github', 'status' => PreviewEnvironment::READY, 'service_name' => 'api-pr-7']);

        // Production with cpu 1000m: 1100m + the preview's 2100m = 3200m > 2500m
        $refusal = $this->refusal($project, DeployIntent::production(['cpu' => '1000m']));
        $this->assertSame('fixed_allocation_exceeded', $refusal['code'] ?? null, 'the preview counts 2100m, not the override size');
        $this->assertStringContainsString('3200', (string) ($refusal['message'] ?? ''));
    }

    public function test_moving_or_deleting_a_project_ends_its_allocation(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $project = $this->billedProject('api', $region, $owner);
        $allocation = $this->allocation($project, 1000, 1024, '2026-10-01');
        $pending = $this->allocation($project, 2000, 2048, '2026-10-01', null, FixedRequest::PENDING);
        $pending->forceFill(['active_from' => null])->save();

        $project->update(['knative_region_id' => $this->billingRegion('other')->id]);

        $allocation->refresh();
        $this->assertSame(FixedRequest::ENDED, $allocation->status);
        $this->assertSame('2026-10-10', $allocation->active_until->toDateString());
        $this->assertSame(FixedRequest::CANCELLED, $pending->fresh()->status);

        // Deleting: what is still active ends (the service the Project::deleted hook calls)
        $second = $this->billedProject('web', $region, $owner);
        $active = $this->allocation($second, 1000, 1024, '2026-10-05');
        app(FixedRequests::class)->endForProject((string) $second->id);
        $this->assertSame(FixedRequest::ENDED, $active->fresh()->status);
    }

    /** A fixed project of 500m / 512 MB per pod (600m / 612 MB with the queue-proxy) up to $maxScale pods, with a live deployment. */
    private function running(string $name, KnativeRegion $region, User $owner, int $maxScale): Project
    {
        $project = $this->sized($this->billedProject($name, $region, $owner), '500m', 512);
        $project->containerConfig->update(['max_scale' => $maxScale]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => "run-{$name}-v1"]);

        return $project->fresh();
    }

    /**
     * The allocations cover the projects that run: a stopped project's share is lent to the
     * organization's other projects in the region, the two cannot run at the same time — the
     * resume is refused while another project uses the share — and the allocation stays billed.
     */
    public function test_a_stopped_projects_allocation_is_lent_and_its_resume_waits_for_it(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        // 2 pods each: 1200m / 1224 MB at the worst case, approved 1200m / 2048 MB each
        $api = $this->running('api', $region, $owner, 2);
        $web = $this->running('web', $region, $owner, 2);
        $apiShare = $this->allocation($api, 1200, 2048, '2026-10-01');
        $this->allocation($web, 1200, 2048, '2026-10-01');

        // web may not grow into api's share while api runs
        $this->assertSame('fixed_allocation_exceeded', $this->refusal($web, DeployIntent::production(['max_scale' => 4]))['code'] ?? null);

        app(ProjectStopService::class)->stop($api, $owner, 'paused');
        $api->deployments()->update(['status' => 'shut_down']);
        $this->assertTrue(StoppedCapacity::holds((string) $region->id), 'a fixed region keeps the reservation');
        $this->assertSame(FixedRequest::APPROVED, $apiShare->fresh()->status, 'the allocation stays billed');
        $this->assertNull($apiShare->fresh()->active_until);

        // stopped: its 1200m are lent — web runs 4 pods (2400m) in its place, 5 do not fit
        $this->assertNull($this->refusal($web, DeployIntent::production(['max_scale' => 4])));
        $over = $this->refusal($web, DeployIntent::production(['max_scale' => 5]));
        $this->assertSame('fixed_allocation_exceeded', $over['code'] ?? null);
        $this->assertStringContainsString('lend 1200 m CPU', $over['message']);
        $web->deployments()->update(['deploy_config' => ['max_scale' => 4]]);

        // the resume must fit: web uses api's share, so the redeploy is refused like a deploy
        $deployment = app(ProjectStopService::class)->resume($api->fresh(), $owner, $refusal, $code);
        $this->assertNull($deployment);
        $this->assertSame('fixed_allocation_exceeded', $code);
        $this->assertStringContainsString('still use 1200 m CPU', (string) $refusal);
        $api = $api->fresh();
        $this->assertFalse($api->isStopped());
        $this->assertNotNull($api->capacity_released_at, 'resumed without a deployment, its share still lent');
        // the project page: why it waits and what it lacks — web uses 1200m of its 1200m, 400 MB beyond web's own memory
        $waiting = StoppedCapacity::waiting($api);
        $this->assertSame('fixed_allocation_exceeded', $waiting['code']);
        $this->assertSame((string) $region->id, $waiting['region_id']);
        $this->assertSame(['cpu_m' => ['needed' => 1200, 'available' => 0, 'missing' => 1200]], $waiting['shortfall']);
        $this->assertNotNull($api->fresh()->capacity_released_at, 'asking does not admit it');

        // a third project within its own allocation is not held up by the lending
        $jobs = $this->running('jobs', $region, $owner, 1);
        $this->allocation($jobs, 600, 1024, '2026-10-01');
        $this->assertNull($this->refusal($jobs));
        // ... and web keeps what it uses, but may not take more
        $this->assertNull($this->refusal($web->fresh(), DeployIntent::production(['max_scale' => 4])));
        $this->assertSame('fixed_allocation_exceeded', $this->refusal($web->fresh(), DeployIntent::production(['max_scale' => 5]))['code'] ?? null);

        // web gives the share back: api is admitted again and holds its own share
        $web->deployments()->update(['deploy_config' => ['max_scale' => 2]]);
        $this->assertNull($this->refusal($api));
        $this->assertNull($api->fresh()->capacity_released_at);
        $this->assertSame('fixed_allocation_exceeded', $this->refusal($web->fresh(), DeployIntent::production(['max_scale' => 4]))['code'] ?? null);
    }

    public function test_only_the_part_of_a_lent_share_no_other_project_uses_is_free(): void
    {
        $owner = $this->userWithRole('owner');
        $region = $this->billingRegion('fx', 'fixed');
        $api = $this->running('api', $region, $owner, 2);
        $this->allocation($api, 1200, 2048, '2026-10-01');
        $lender = $this->running('lender', $region, $owner, 1);
        $this->allocation($lender, 1200, 2048, '2026-10-01');

        // the lender runs: nothing is lent
        $lending = app(Admission::class)->lending($api, (string) $region->id);
        $this->assertSame(['cpu_m' => 0, 'memory_mb' => 0, 'gpus' => 0], $lending['lent']);
        $this->assertSame(['cpu_m' => 0, 'memory_mb' => 0, 'gpus' => 0], $lending['used']);

        // stopped: its allocation is lent; api uses 600m of it at 3 pods
        app(ProjectStopService::class)->stop($lender, $owner, 'paused');
        $lender->deployments()->update(['status' => 'shut_down']);
        $api->deployments()->update(['deploy_config' => ['max_scale' => 3]]);
        $web = $this->running('web', $region, $owner, 1);
        $this->allocation($web, 600, 1024, '2026-10-01');

        $lending = app(Admission::class)->lending($web, (string) $region->id);
        $this->assertSame(['cpu_m' => 1200, 'memory_mb' => 2048, 'gpus' => 0], $lending['lent']);
        $this->assertSame(600, $lending['used']['cpu_m']);
        // web: its own 600m + the 600m still free of the lent share
        $this->assertNull($this->refusal($web, DeployIntent::production(['max_scale' => 2])));
        $this->assertSame('fixed_allocation_exceeded', $this->refusal($web, DeployIntent::production(['max_scale' => 3]))['code'] ?? null);
    }
}
