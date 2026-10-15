<?php

namespace Tests\Feature;

use App\Http\Requests\Project\DeployProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Models\XaasConfig;
use App\Services\GitService;
use App\Services\ProjectService;
use App\Services\RegionDemoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Region capacity admission uses the effective size of a project — the size sent, else the
 * stored one, else the service type's default (micro 250m / 256 MiB for XaaS / FaaS, small
 * 500m / 512 MiB for CaaS), plus queue-proxy and the Kata overhead (ResourceReservation) — on
 * create, update and deploy, and for every creator (API, region demo, demo seeding).
 * OP, 0.4.1: projects created without config.cpu / config.memory_mb skipped the check and the
 * region reached 9100m used of 4505m.
 *
 * Existing projects that do not grow stay grandfathered.
 *
 * The regions here run without FaaS overcommit (factors 1: every reservation against the real
 * capacity), so the sizes add up as plainly as possible; the budgets themselves are covered by
 * RegionCapacityAdmissionTest.
 */
class ProjectCapacityAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        // 1000m / 4096 MB for projects
        $this->region = $this->region('op', 1000, 4096);
    }

    private function region(string $name, int $cpuM, int $memoryMb, array $attributes = []): KnativeRegion
    {
        return KnativeRegion::create($attributes + [
            'name' => $name,
            'project_namespace' => $name,
            'is_active' => true,
            'is_demo' => true, // no kubeconfig needed to pick it
            'total_cpu_m' => $cpuM,
            'total_memory_mb' => $memoryMb,
            'faas_cpu_overcommit' => 1,
            'faas_memory_overcommit' => 1,
        ]);
    }

    /** An XaaS project on a region: reserves cpu + 100m queue-proxy per instance. */
    private function project(string $name, string $cpu = '250m', int $minScale = 0, ?KnativeRegion $region = null): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => ($region ?? $this->region)->id])->saveQuietly();
        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => 'main.py',
            'handler' => 'handler',
            'cpu' => $cpu,
            'memory_mb' => 256,
            'trigger_type' => 'http',
            'min_scale' => $minScale,
            'max_scale' => 5,
        ]);

        return $project->fresh(['xaasConfig']);
    }

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
        $request->withValidator($validator);

        return $validator->errors()->toArray();
    }

    /** A create request without any size. */
    private function storePayload(string $name, string $serviceType = 'xaas', ?KnativeRegion $region = null, array $config = []): array
    {
        return [
            'name' => $name,
            'service_type' => $serviceType,
            'knative_region_id' => ($region ?? $this->region)->id,
            'config' => $config + ($serviceType === 'caas' ? ['image' => 'nginx:1.27-alpine'] : ['trigger_type' => 'http']),
        ];
    }

    private function version(Project $project): Version
    {
        return Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
    }

    // ── Create ──────────────────────────────────────────────────────────────

    public function test_a_create_without_size_is_admitted_at_the_default_size(): void
    {
        $this->project('taken', '550m'); // 650m used, 350m free

        // micro 250m + 100m queue-proxy = 350m: fits exactly
        $this->assertSame([], $this->errors(StoreProjectRequest::class, $this->storePayload('fits')));

        // CaaS defaults to small: 500m + 100m does not fit
        $errors = $this->errors(StoreProjectRequest::class, $this->storePayload('too-big', 'caas'));
        $this->assertSame(['CPU limit exceeded on the FaaS overcommit budget: this needs 600m, 350m of 1000m are free (region capacity 1000m × 1, minus 0m always-on).'], $errors['config.cpu']);
        $this->assertArrayNotHasKey('config.memory_mb', $errors, '512 + 100 MB fit');

        // min_scale 2 without a size: 2 × 350m
        $this->assertArrayHasKey('config.cpu', $this->errors(StoreProjectRequest::class, $this->storePayload('scaled', 'xaas', null, ['min_scale' => 2])));
    }

    public function test_a_create_without_size_over_capacity_is_rejected_by_the_api(): void
    {
        $this->project('taken', '700m'); // 800m used, 200m free

        $this->actingAs(User::factory()->create(['username' => 'dev']), 'sanctum')
            ->postJson('/api/projects', $this->storePayload('perf-1'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('config.cpu');

        $this->assertFalse(Project::where('name', 'perf-1')->exists());
    }

    public function test_the_memory_default_is_checked_too(): void
    {
        $region = $this->region('small-mem', 16000, 400);

        // 256 + 100 MB fits in 400 MB, the CaaS 512 + 100 MB does not
        $this->assertArrayNotHasKey('config.memory_mb', $this->errors(StoreProjectRequest::class, $this->storePayload('fn', 'xaas', $region)));
        $errors = $this->errors(StoreProjectRequest::class, $this->storePayload('ctr', 'caas', $region));
        $this->assertSame(['Memory limit exceeded on the FaaS overcommit budget: this needs 612 MB, 400 MB of 400 MB are free (region capacity 400 MB × 1, minus 0 MB always-on).'], $errors['config.memory_mb']);
    }

    public function test_the_kata_overhead_is_part_of_the_default_reservation(): void
    {
        // xaas on kata-clh: 250m + 100m queue-proxy + 250m pod overhead = 600m
        $kata = $this->region('kata', 600, 4096, ['sandbox_xaas_runtime' => 'kata-clh']);
        $this->assertSame([], $this->errors(StoreProjectRequest::class, $this->storePayload('fits', 'xaas', $kata)));

        $this->project('taken', '1m', 0, $kata); // 1m + 100m + 250m overhead
        $this->assertArrayHasKey('config.cpu', $this->errors(StoreProjectRequest::class, $this->storePayload('over', 'xaas', $kata)));
    }

    public function test_an_explicit_size_is_still_checked(): void
    {
        $this->assertSame([], $this->errors(StoreProjectRequest::class, $this->storePayload('fits', 'xaas', null, ['cpu' => '900m'])));
        $this->assertArrayHasKey('config.cpu', $this->errors(StoreProjectRequest::class, $this->storePayload('over', 'xaas', null, ['cpu' => '901m'])));
    }

    // ── Deploy ──────────────────────────────────────────────────────────────

    public function test_a_deploy_without_size_is_checked_with_the_stored_size(): void
    {
        $project = $this->project('fn');          // 350m
        $this->project('other', '400m');           // 500m → 150m free
        $version = $this->version($project);

        // Same reservation: allowed
        $this->assertSame([], $this->errors(DeployProjectRequest::class, ['version_id' => $version->id], $project));
        $this->assertSame([], $this->errors(DeployProjectRequest::class, ['version_id' => $version->id, 'deploy_config' => ['timeout_seconds' => 60]], $project));

        // min_scale 2 without a size: 2 × (250m + 100m) = 700m > 350m + 150m
        $errors = $this->errors(DeployProjectRequest::class, ['version_id' => $version->id, 'deploy_config' => ['min_scale' => 2]], $project);
        $this->assertSame(['CPU limit exceeded on the FaaS overcommit budget: this needs 700m, 500m of 1000m are free (region capacity 1000m × 1, minus 0m always-on).'], $errors['deploy_config.cpu']);
    }

    public function test_a_deploy_without_size_under_capacity_is_allowed(): void
    {
        $project = $this->project('fn');          // 350m, 650m free
        $version = $this->version($project);

        // 2 × 350m = 700m ≤ 1000m
        $this->assertSame([], $this->errors(DeployProjectRequest::class, ['version_id' => $version->id, 'deploy_config' => ['min_scale' => 2]], $project));
    }

    public function test_an_unchanged_deploy_is_grandfathered_on_a_region_over_capacity(): void
    {
        $project = $this->project('fn', '250m', 2); // 700m
        $this->project('other', '600m');             // 700m → 1400m of 1000m
        $version = $this->version($project);

        $this->assertSame([], $this->errors(DeployProjectRequest::class, ['version_id' => $version->id], $project));
        $this->assertSame([], $this->errors(DeployProjectRequest::class, ['version_id' => $version->id, 'deploy_config' => ['min_scale' => 1]], $project));
        $this->assertArrayHasKey('deploy_config.cpu', $this->errors(DeployProjectRequest::class, ['version_id' => $version->id, 'deploy_config' => ['min_scale' => 3]], $project));
    }

    // ── Update ──────────────────────────────────────────────────────────────

    public function test_an_update_without_size_is_checked_when_it_grows(): void
    {
        $project = $this->project('fn');          // 350m
        $this->project('other', '400m');           // 150m free

        $this->assertSame([], $this->errors(UpdateProjectRequest::class, ['description' => 'x'], $project));
        $this->assertSame([], $this->errors(UpdateProjectRequest::class, ['config' => ['env_vars' => ['A' => 'b']]], $project));
        $this->assertArrayHasKey('config.cpu', $this->errors(UpdateProjectRequest::class, ['config' => ['min_scale' => 2]], $project));

        // A move to a region without room for its stored size
        $full = $this->region('full', 300, 4096);
        $this->assertArrayHasKey('config.cpu', $this->errors(UpdateProjectRequest::class, ['knative_region_id' => $full->id], $project));
        $roomy = $this->region('roomy', 350, 4096);
        $this->assertSame([], $this->errors(UpdateProjectRequest::class, ['knative_region_id' => $roomy->id], $project));
    }

    // ── Other creators ──────────────────────────────────────────────────────

    public function test_every_creator_is_admitted_through_the_project_service(): void
    {
        $this->mock(GitService::class, function ($git) {
            $git->shouldReceive('initRepo');
            $git->shouldReceive('projectDir')->andReturn('projects/x');
        });
        $this->project('taken', '400m'); // 500m used, 500m free

        $service = app(ProjectService::class);
        $data = fn (string $name, array $config = []) => [
            'name' => $name,
            'service_type' => 'xaas',
            'runtime' => 'python',
            'knative_region_id' => $this->region->id,
            'config' => $config + ['trigger_type' => 'http'],
        ];

        $service->create($data('first'));  // 350m → 150m free

        try {
            $service->create($data('second'));
            $this->fail('a project without size over capacity must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('config.cpu', $e->errors());
        }
        $this->assertFalse(Project::where('name', 'second')->exists());

        $service->create($data('small', ['cpu' => '50m'])); // 150m
        $this->assertTrue(Project::where('name', 'small')->exists());
    }

    public function test_the_region_demo_needs_room_for_all_four_projects(): void
    {
        // frontend xaas 350m + backend faas 350m + email sender queue 350m + 50m consumer +
        // stats caas schedule 500m = 1600m; memory 356 + 356 + 420 + 512 = 1644 MB
        $service = app(RegionDemoService::class);

        $roomy = $this->region('roomy', 1600, 1644);
        $service->validateCapacity($roomy);

        $tight = $this->region('tight', 2000, 4096);
        $this->project('taken', '300m', 0, $tight); // 400m used, 1600m free
        $service->validateCapacity($tight);

        $this->project('more', '1m', 0, $tight); // 101m more
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough free capacity on this region for the demo projects. CPU limit exceeded on the FaaS overcommit budget: this needs 1600m, 1499m of 2000m are free');
        $service->validateCapacity($tight);
    }
}
