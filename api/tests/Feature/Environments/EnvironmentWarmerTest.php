<?php

namespace Tests\Feature\Environments;

use App\Models\Deployment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Version;
use App\Services\Projects\WarmScaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\EnvironmentFixtures;
use Tests\TestCase;

/**
 * The timed warmer of environments (docs/scaling.md#timed-warmer, docs/environments.md): each
 * environment's workload runs the project's schedule unless it has its own (overrides.scale_schedule;
 * [] for none) — on its own Knative Service, for every revision that takes traffic there, in the
 * organization's time zone — and records what was applied on the environment.
 *
 * Wednesday 2026-10-21 10:00 UTC = 13:00 in Istanbul (the default zone) = 06:00 in New York.
 */
class EnvironmentWarmerTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use RefreshDatabase;

    private const PA_PATH = '/apis/autoscaling.internal.knative.dev/v1alpha1/namespaces/eu-fn/podautoscalers';

    /** @var array<string, array<string, mixed>> Knative Services of the region by name: their status */
    private array $services = [];

    /** @var array<string, string|null> PodAutoscalers by revision: their min-scale annotation */
    private array $autoscalers = [];

    /** @var list<array{revision: string, value: string}> */
    private array $patches = [];

    private KnativeRegion $eu;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'UTC'));
        $this->eu = $this->region('eu', true, ['cluster_status' => 'online']);
        $this->owner = $this->userWithRole('owner');

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_ends_with($path, '/namespaces/eu-fn/services')) {
                return Http::response(['items' => array_map(fn (string $name, array $status) => ['metadata' => ['name' => $name], 'status' => $status],
                    array_keys($this->services), $this->services)]);
            }
            $autoscaler = fn (string $name) => ['metadata' => ['name' => $name, 'annotations' => array_filter([WarmScaleService::ANNOTATION => $this->autoscalers[$name]])]];
            if ($request->method() === 'GET' && str_ends_with($path, self::PA_PATH)) {
                return Http::response(['items' => array_map($autoscaler, array_keys($this->autoscalers))]);
            }
            if ($request->method() === 'GET' && array_key_exists(basename($path), $this->autoscalers) && str_contains($path, self::PA_PATH.'/')) {
                return Http::response($autoscaler(basename($path)));
            }
            if ($request->method() === 'PATCH' && str_contains($path, self::PA_PATH.'/')) {
                $value = (string) (json_decode($request->body(), true)['metadata']['annotations'][WarmScaleService::ANNOTATION] ?? '');
                $this->patches[] = ['revision' => basename($path), 'value' => $value];
                $this->autoscalers[basename($path)] = $value;

                return Http::response(['kind' => 'PodAutoscaler']);
            }

            return Http::response(['kind' => 'Status'], 404);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_environment_runs_a_schedule_of_its_own_in_the_organization_zone(): void
    {
        $project = $this->warmProject(null);
        $this->environment($project, 'staging');
        $this->serving('shop', 'shop-00001');
        $this->serving('shop-staging', 'shop-staging-00001');

        // 09:00–17:00 one warm container in staging; the project has no warmer
        $this->patchStaging($project, ['scale_schedule' => [['cron' => '0 9 * * *', 'min_scale' => 1], ['cron' => ' 0  17 * * * ', 'min_scale' => 0]]])
            ->assertOk()
            ->assertJsonPath('data.scale_schedule', [['cron' => '0 9 * * *', 'min_scale' => 1], ['cron' => '0 17 * * *', 'min_scale' => 0]])
            ->assertJsonPath('data.scale_schedule_inherited', false)
            ->assertJsonPath('data.warm_scale', null);
        $staging = ProjectEnvironment::query()->where('name', 'staging')->sole();
        $live = $this->liveIn($project, $staging);

        $this->assertSame(1, app(WarmScaleService::class)->run());
        $this->assertSame([['revision' => 'shop-staging-00001', 'value' => '1']], $this->patches, 'Istanbul (default): only staging, the project has none');
        $this->assertSame(1, $staging->fresh()->warm_scale_applied);
        $this->assertNull($project->fresh()->warm_scale_applied);
        $this->assertSame([[now()->getTimestamp(), 1]], $live->fresh()->sizing['warm_log']);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/environments/staging")
            ->assertJsonPath('data.warm_scale.applied', 1);

        Setting::set('timezone', 'America/New_York');
        $this->patches = [];
        app(WarmScaleService::class)->run();
        $this->assertSame([['revision' => 'shop-staging-00001', 'value' => '0']], $this->patches, 'New York');
        $this->assertSame(0, $staging->fresh()->warm_scale_applied);
    }

    public function test_environments_follow_the_project_s_schedule_unless_they_turn_it_off(): void
    {
        $project = $this->warmProject([['cron' => '* * * * *', 'min_scale' => 2]]);
        $staging = $this->environment($project, 'staging');
        $dev = $this->environment($project, 'dev', ['overrides' => ['scale_schedule' => []]]);
        foreach (['shop' => 'shop-00001', 'shop-staging' => 'shop-staging-00001', 'shop-dev' => 'shop-dev-00001'] as $service => $revision) {
            $this->serving($service, $revision);
        }
        $this->liveIn($project, $staging);
        $this->liveIn($project, $dev);

        app(WarmScaleService::class)->run();
        $patched = array_column($this->patches, 'value', 'revision');
        ksort($patched);
        $this->assertSame(['shop-00001' => '2', 'shop-staging-00001' => '2'], $patched, 'dev keeps no containers warm');
        $this->assertSame([2, null], [$staging->fresh()->warm_scale_applied, $dev->fresh()->warm_scale_applied]);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/environments/dev")
            ->assertJsonPath('data.scale_schedule', null)
            ->assertJsonPath('data.scale_schedule_inherited', false)
            ->assertJsonPath('data.overrides.scale_schedule', []);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/projects/{$project->id}/environments/staging")
            ->assertJsonPath('data.scale_schedule', [['cron' => '* * * * *', 'min_scale' => 2]])
            ->assertJsonPath('data.scale_schedule_inherited', true);

        // Turned off in staging too: its PodAutoscaler gets its min_scale back, once
        $this->patchStaging($project, ['scale_schedule' => []])->assertOk();
        $this->patches = [];
        app(WarmScaleService::class)->run();
        $this->assertSame([['revision' => 'shop-staging-00001', 'value' => '0']], $this->patches);
        $this->assertNull($staging->fresh()->warm_scale_applied);
        $this->patches = [];
        app(WarmScaleService::class)->run();
        $this->assertSame([], $this->patches);

        // No value: the project's schedule again
        $this->patchStaging($project, ['min_scale' => 0])->assertOk()->assertJsonPath('data.scale_schedule_inherited', true);
        app(WarmScaleService::class)->run();
        $this->assertSame([['revision' => 'shop-staging-00001', 'value' => '2']], $this->patches);
    }

    public function test_a_rollout_in_an_environment_keeps_both_revisions_warm(): void
    {
        $project = $this->warmProject(null);
        $staging = $this->environment($project, 'staging', ['overrides' => ['scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 2]]]]);
        $live = $this->liveIn($project, $staging);
        // a canary of staging at 10 %: both revisions take traffic
        $this->serving('shop-staging', 'shop-staging-00002', [
            ['revisionName' => 'shop-staging-00001', 'percent' => 90],
            ['revisionName' => 'shop-staging-00002', 'percent' => 10, 'tag' => 'canary'],
        ]);
        $this->autoscalers['shop-staging-00001'] = '0';

        $this->assertSame(2, app(WarmScaleService::class)->run());
        $patched = array_column($this->patches, 'value', 'revision');
        ksort($patched);
        $this->assertSame(['shop-staging-00001' => '2', 'shop-staging-00002' => '2'], $patched);

        // After a deploy the new revision of the environment gets the value of now at once
        $this->autoscalers['shop-staging-00003'] = '0';
        $this->patches = [];
        app(WarmScaleService::class)->afterReady($project, $live, ['metadata' => ['name' => 'shop-staging'], 'status' => ['latestReadyRevisionName' => 'shop-staging-00003']]);
        $this->assertSame([['revision' => 'shop-staging-00003', 'value' => '2']], $this->patches);
        $this->assertSame(2, $staging->fresh()->warm_scale_applied);
    }

    public function test_an_environment_s_schedule_is_checked_like_the_project_s(): void
    {
        $project = $this->warmProject(null);
        $this->environment($project, 'staging');

        // never above max_scale: the environment's, else the project's (3)
        $this->patchStaging($project, ['scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 5]]])
            ->assertUnprocessable()->assertJsonValidationErrors('overrides.scale_schedule.0.min_scale');
        $this->patchStaging($project, ['max_scale' => 10, 'scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 5]]])->assertOk();
        $this->patchStaging($project, ['scale_schedule' => [['cron' => 'every day', 'min_scale' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('overrides.scale_schedule.0.cron');

        // a schedule trigger runs a CronJob: nothing to keep warm
        $cron = $this->caasProject('nightly', $this->eu, ['trigger_type' => 'schedule', 'schedule' => '0 3 * * *']);
        $this->environment($cron, 'staging');
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/projects/{$cron->id}/environments/staging", [
            'overrides' => ['scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 1]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('overrides.scale_schedule');
    }

    public function test_overrides_saved_without_the_variables_keep_them_for_who_cannot_see_them(): void
    {
        $project = $this->warmProject(null);
        $staging = $this->environment($project, 'staging', ['overrides' => ['env_vars' => ['TOKEN' => 'staging-value']]]);

        // environments.manage without env.view: the variables are masked, a warmer change keeps them
        $this->customRole('env-manager', ['projects.view', 'environments.manage']);
        $manager = $this->userWithRole('env-manager');
        $this->actingAs($manager, 'sanctum')->patchJson("/api/projects/{$project->id}/environments/staging", [
            'overrides' => ['scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 1]]],
        ])->assertOk()->assertJsonPath('data.overrides.env_vars_hidden', true);
        $this->assertSame(['TOKEN' => 'staging-value'], $staging->fresh()->overrideValues()['env_vars']);

        // with env.view, sending them back unchanged needs no env.edit; changing them does
        $this->customRole('env-viewer', ['projects.view', 'environments.manage', 'env.view']);
        $viewer = $this->userWithRole('env-viewer');
        $this->actingAs($viewer, 'sanctum')->patchJson("/api/projects/{$project->id}/environments/staging", [
            'overrides' => ['env_vars' => ['TOKEN' => 'staging-value'], 'scale_schedule' => []],
        ])->assertOk();
        $this->actingAs($viewer, 'sanctum')->patchJson("/api/projects/{$project->id}/environments/staging", [
            'overrides' => ['env_vars' => ['TOKEN' => 'other'], 'scale_schedule' => []],
        ])->assertForbidden();
        $this->assertEquals(['env_vars' => ['TOKEN' => 'staging-value'], 'scale_schedule' => []], $staging->fresh()->overrideValues());
    }

    /** @param  list<array{cron: string, min_scale: int}>|null  $schedule */
    private function warmProject(?array $schedule): Project
    {
        $project = $this->caasProject('shop', $this->eu, ['scale_schedule' => $schedule]);
        $this->liveIn($project, null);

        return $project;
    }

    /** The live deployment of the project's own workload (null) or of an environment, as the deploy sized it. */
    private function liveIn(Project $project, ?ProjectEnvironment $environment): Deployment
    {
        $version = Version::query()->where('project_id', $project->id)->first()
            ?? Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = Deployment::query()->create([
            'project_id' => $project->id, 'version_id' => $version->id, 'environment_id' => $environment?->id,
            'status' => 'active', 'k8s_job_name' => 'run', 'deployed_by' => 'dev',
        ]);
        $deployment->forceFill(['ready_at' => now()->subHour(), 'sizing' => [
            'region_id' => $this->eu->id, 'cpu_m' => 250, 'memory_mb' => 128, 'trigger_type' => 'http',
            'min_scale' => 0, 'max_scale' => 10, 'scale_to_zero' => true, 'gpu_count' => 0,
        ]])->save();

        return $deployment->fresh();
    }

    /** A Knative Service of the region serving $revision (or split by $traffic), its PodAutoscaler at 0. */
    private function serving(string $service, string $revision, ?array $traffic = null): void
    {
        $this->services[$service] = array_filter(['latestReadyRevisionName' => $revision, 'latestCreatedRevisionName' => $revision, 'traffic' => $traffic]);
        $this->autoscalers[$revision] = '0';
    }

    /** @param  array<string, mixed>  $overrides */
    private function patchStaging(Project $project, array $overrides): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->patchJson("/api/projects/{$project->id}/environments/staging", ['overrides' => $overrides]);
    }
}
