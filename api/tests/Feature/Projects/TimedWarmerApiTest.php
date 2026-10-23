<?php

namespace Tests\Feature\Projects;

use App\Http\Requests\Project\DeployProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\XaasConfig;
use App\Services\Ai\Gpu\GpuQuota;
use App\Services\Helpers\ResourceReservation;
use App\Services\Helpers\WorkloadSizing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * The timed warmer through the API (config.scale_schedule, docs/scaling.md#timed-warmer) and the
 * scale limits around it:
 *  - rows of a five-field cron and a container count, at most 10, the count never above max_scale;
 *    [] or null turns the warmer off; the project shows what the warmer applied (warm_scale)
 *  - #627: a scale-to-zero project keeps its max_scale (it can burst), min_scale ≤ max_scale is
 *    checked on create, update and deploy
 *  - capacity and admission count the schedule's peak in place of min_scale
 */
class TimedWarmerApiTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private const OFFICE = [
        ['cron' => '0 9 * * 1-5', 'min_scale' => 3],
        ['cron' => '0 18 * * 1-5', 'min_scale' => 0],
    ];

    private Project $project;

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        // 1000m / 4096 MB for projects, no FaaS overcommit: reservations add up plainly
        $this->region = KnativeRegion::create([
            'name' => 'op', 'project_namespace' => 'op', 'is_active' => true, 'is_demo' => true,
            'total_cpu_m' => 1000, 'total_memory_mb' => 4096, 'faas_cpu_overcommit' => 1, 'faas_memory_overcommit' => 1,
        ]);

        $this->owner = $this->userWithRole('owner');
        $this->project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        FaasConfig::create(['project_id' => $this->project->id, 'handler' => 'main.handler', 'cpu' => '100m', 'memory_mb' => 128, 'max_scale' => 5]);
    }

    private function url(): string
    {
        return "/api/projects/{$this->project->id}";
    }

    /** Validation errors of a FormRequest for a payload (rules + withValidator), without HTTP. */
    private function errors(string $requestClass, array $payload, ?Project $routeProject = null): array
    {
        /** @var FormRequest $request */
        $request = $requestClass::create('/api/test', 'POST', $payload);
        $request->setContainer($this->app);

        if ($routeProject) {
            $route = (new Route('POST', 'api/test/{project}', []))->bind($request);
            $route->setParameter('project', $routeProject);
            $request->setRouteResolver(fn () => $route);
        }

        $validator = Validator::make($request->all(), $request->rules());
        if (method_exists($request, 'withValidator')) {
            $request->withValidator($validator);
        }

        return $validator->errors()->toArray();
    }

    private function storePayload(array $config): array
    {
        return [
            'name' => 'warm-shop',
            'service_type' => 'caas',
            'knative_region_id' => $this->region->id,
            'config' => array_merge(['image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128, 'trigger_type' => 'http'], $config),
        ];
    }

    // ── Saving and reading the schedule ─────────────────────────────────────

    public function test_a_schedule_is_saved_and_returned_in_its_stored_shape(): void
    {
        $this->signedIn($this->owner)->putJson($this->url(), ['config' => ['scale_schedule' => [
            ['cron' => ' 0  9 * *   1-5', 'min_scale' => 3],
            ['cron' => '0 18 * * 1-5', 'min_scale' => 0],
        ]]])
            ->assertOk()
            ->assertJsonPath('data.config.scale_schedule', self::OFFICE)
            ->assertJsonPath('data.warm_scale', null);

        $this->assertSame(self::OFFICE, $this->project->faasConfig()->first()->scale_schedule);

        // A change without the field keeps it
        $this->signedIn($this->owner)->putJson($this->url(), ['config' => ['memory_mb' => 256]])
            ->assertOk()
            ->assertJsonPath('data.config.scale_schedule', self::OFFICE);
    }

    public function test_an_empty_list_or_null_turns_the_warmer_off(): void
    {
        foreach ([[], null] as $off) {
            $this->project->faasConfig()->update(['scale_schedule' => json_encode(self::OFFICE)]);

            $this->signedIn($this->owner)->putJson($this->url(), ['config' => ['scale_schedule' => $off]])
                ->assertOk()
                ->assertJsonPath('data.config.scale_schedule', null);
            $this->assertNull($this->project->faasConfig()->first()->scale_schedule);
        }
    }

    public function test_a_new_project_can_start_with_a_warmer(): void
    {
        $this->signedIn($this->owner)->postJson('/api/projects', $this->storePayload([
            'max_scale' => 3, 'scale_schedule' => [['cron' => '0 9 * * MON-FRI', 'min_scale' => 2]],
        ]))
            ->assertCreated()
            // POST /api/projects answers the project itself (no data wrapper)
            ->assertJsonPath('config.scale_schedule', [['cron' => '0 9 * * MON-FRI', 'min_scale' => 2]])
            ->assertJsonPath('config.max_scale', 3);
    }

    public function test_the_project_shows_what_the_warmer_applied(): void
    {
        $this->project->forceFill(['warm_scale_applied' => 2, 'warm_scale_applied_at' => '2026-10-21 06:00:00'])->saveQuietly();

        $this->signedIn($this->owner)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.warm_scale.applied', 2)
            ->assertJsonPath('data.warm_scale.applied_at', '2026-10-21T06:00:00+00:00');
    }

    // ── Row validation ──────────────────────────────────────────────────────

    public function test_rows_are_validated_like_the_warmer_evaluates_them(): void
    {
        $cases = [
            'config.scale_schedule' => array_fill(0, 11, ['cron' => '0 9 * * *', 'min_scale' => 1]),
            'config.scale_schedule.0.cron' => [['cron' => '@daily', 'min_scale' => 1]],
            'config.scale_schedule.1.cron' => [['cron' => '0 9 * * *', 'min_scale' => 1], ['cron' => '0 0 9 * * *', 'min_scale' => 0]],
        ];
        foreach ($cases as $key => $rows) {
            $this->signedIn($this->owner)->putJson($this->url(), ['config' => ['scale_schedule' => $rows]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$key]);
        }

        foreach (['61 * * * *', '0 9 L * *', '0 9 * * 1-5 2026', 'TZ=UTC 0 9 * * *', ''] as $cron) {
            $this->signedIn($this->owner)->putJson($this->url(), ['config' => ['scale_schedule' => [['cron' => $cron, 'min_scale' => 1]]]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['config.scale_schedule.0.cron']);
        }
    }

    public function test_a_count_stays_within_ten_and_max_scale(): void
    {
        $count = fn (array $rows, array $extra = []) => $this->errors(UpdateProjectRequest::class, ['config' => $extra + ['scale_schedule' => $rows]], $this->project)['config.scale_schedule.0.min_scale'][0] ?? null;

        $this->assertStringContainsString('0 to 10', (string) $count([['cron' => '* * * * *', 'min_scale' => 11]]));
        $this->assertNotNull($count([['cron' => '* * * * *', 'min_scale' => -1]]));
        $this->assertNotNull($count([['cron' => '* * * * *', 'min_scale' => 'two']]));
        // stored max_scale 5
        $this->assertStringContainsString('(5)', (string) $count([['cron' => '* * * * *', 'min_scale' => 6]]));
        // the request's max_scale
        $this->assertStringContainsString('(2)', (string) $count([['cron' => '* * * * *', 'min_scale' => 3]], ['max_scale' => 2]));
        $this->assertNull($count([['cron' => '* * * * *', 'min_scale' => 5]]));
    }

    public function test_a_schedule_trigger_has_nothing_to_warm(): void
    {
        $errors = $this->errors(UpdateProjectRequest::class, ['config' => [
            'trigger_type' => 'schedule', 'schedule' => '*/5 * * * *', 'scale_schedule' => [['cron' => '* * * * *', 'min_scale' => 1]],
        ]], $this->project);

        $this->assertArrayHasKey('config.scale_schedule', $errors);
    }

    public function test_max_scale_may_not_drop_below_the_schedules_peak(): void
    {
        $this->project->faasConfig()->update(['scale_schedule' => json_encode(self::OFFICE)]);
        $project = $this->project->fresh();

        $this->assertArrayHasKey('config.max_scale', $this->errors(UpdateProjectRequest::class, ['config' => ['max_scale' => 2]], $project));
        $this->assertArrayNotHasKey('config.max_scale', $this->errors(UpdateProjectRequest::class, ['config' => ['max_scale' => 3]], $project));
        // ... and a deploy's max_scale override neither
        $this->assertArrayHasKey('deploy_config.max_scale', $this->errors(DeployProjectRequest::class, ['deploy_config' => ['max_scale' => 2]], $project));
    }

    // ── #627: min_scale ≤ max_scale, scale-to-zero projects keep their max ──

    public function test_a_scale_to_zero_project_keeps_its_max_scale(): void
    {
        $this->signedIn($this->owner)->postJson('/api/projects', $this->storePayload(['scale_to_zero' => true, 'min_scale' => 0, 'max_scale' => 8]))
            ->assertCreated()
            ->assertJsonPath('config.scale_to_zero', true)
            ->assertJsonPath('config.max_scale', 8);
    }

    public function test_min_scale_may_not_be_above_max_scale(): void
    {
        $store = fn (array $config) => $this->errors(StoreProjectRequest::class, $this->storePayload($config));

        $this->assertArrayHasKey('config.min_scale', $store(['scale_to_zero' => false, 'min_scale' => 3, 'max_scale' => 2]));
        $this->assertArrayHasKey('config.min_scale', $store(['scale_to_zero' => false, 'min_scale' => 2]), 'a new project has max_scale 1 unless it sends one');
        $this->assertArrayNotHasKey('config.min_scale', $store(['scale_to_zero' => false, 'min_scale' => 2, 'max_scale' => 2]));

        // update: with the stored values (min 0, max 5)
        // (a request binds the project afresh: its config as stored now)
        $update = fn (array $config) => $this->errors(UpdateProjectRequest::class, ['config' => $config], $this->project->fresh());
        $this->assertArrayHasKey('config.min_scale', $update(['min_scale' => 6]));
        $this->project->faasConfig()->update(['min_scale' => 4]);
        $this->assertArrayHasKey('config.max_scale', $update(['max_scale' => 3]));
        $this->assertSame([], $update(['memory_mb' => 256]), 'an unrelated change is not held up');

        // deploy: the overrides over the stored values (min 4, max 5)
        $deploy = fn (array $config) => $this->errors(DeployProjectRequest::class, ['deploy_config' => $config], $this->project->fresh());
        $this->assertArrayHasKey('deploy_config.max_scale', $deploy(['max_scale' => 2]));
        $this->assertArrayHasKey('deploy_config.min_scale', $deploy(['min_scale' => 6, 'max_scale' => 5]));
    }

    // ── Capacity and admission count the peak ───────────────────────────────

    public function test_the_region_capacity_counts_the_schedules_peak(): void
    {
        // (250m + 100m queue-proxy) × 3 = 1050m > 1000m; at min_scale 0 it fits
        $store = fn (array $config) => $this->errors(StoreProjectRequest::class, $this->storePayload($config));

        $this->assertArrayHasKey('config.cpu', $store(['max_scale' => 3, 'scale_schedule' => [['cron' => '0 9 * * *', 'min_scale' => 3], ['cron' => '0 18 * * *', 'min_scale' => 0]]]));
        $this->assertArrayNotHasKey('config.cpu', $store(['max_scale' => 3, 'scale_schedule' => [['cron' => '0 9 * * *', 'min_scale' => 2]]]));
        $this->assertArrayNotHasKey('config.cpu', $store(['max_scale' => 3, 'min_scale' => 0]));
    }

    public function test_reservation_sizing_and_gpus_count_the_peak(): void
    {
        $project = Project::create(['name' => 'peaky', 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        $config = XaasConfig::create([
            'project_id' => $project->id, 'filename' => 'main.py', 'handler' => 'handler', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'scale_to_zero' => true, 'min_scale' => 0, 'max_scale' => 4, 'scale_schedule' => self::OFFICE,
        ]);
        $project = $project->fresh();

        // always-on at the peak: (250 + 100) × 3
        $this->assertSame(['cpu_m' => 1050, 'memory_mb' => (128 + 100) * 3], ResourceReservation::forProject($project));
        $this->assertSame(3, WorkloadSizing::of($project)['min_scale']);
        $this->assertSame(0, WorkloadSizing::of($project, ['scale_schedule' => null])['min_scale'], 'turned off in a layer');
        $this->assertSame(4, WorkloadSizing::peak(WorkloadSizing::of($project))['pods'], 'the fixed-region worst case stays max_scale');

        $config->update(['gpu' => ['count' => 1]]);
        $this->assertSame(3, GpuQuota::projectReservation($project->fresh(), $config->fresh()));
    }
}
