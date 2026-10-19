<?php

namespace Tests\Feature;

use App\Http\Resources\KnativeRegionResource;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Services\Helpers\ResourceReservation;
use App\Services\Sandboxes\SandboxClassResolver;
use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\SandboxQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Http\Controllers\TenancySettingsController;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Http\Middleware\CheckTenantQuota;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantQuotaService;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Tenant quotas (multi-tenant edition):
 *  - Admission counts size × max(1, min_scale) (+ queue-proxy per instance), split like the
 *    region capacity (TenantQuotaService): always-on projects and the Kata pod overhead count in
 *    full against the quota, scale-to-zero projects one pod against quota × the region's
 *    overcommit factor (defaults 3 for CPU, 1.5 for memory; 1 makes the quota hard). Existing
 *    projects that do not grow are grandfathered.
 *  - With an organization ceiling a tenant's quota on a region defaults to the
 *    ceiling (bounded by what is still free), and the tenants' quotas on a region never add up
 *    to more than the ceiling or the region capacity: quotas are shares of the capacity.
 */
class TenantQuotaTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();

        // tenants / tenant_regions live on the "central" connection: point it at the test
        // database, then apply the Tenancy module's central migrations
        $this->setUpTenancy();

        config(['quota.cpu' => null, 'quota.memory_gib' => null]);

        $this->region = KnativeRegion::create([
            'name' => 'fn',
            'project_namespace' => 'fn',
            'is_active' => true,
            'total_cpu_m' => 16000,
            'total_memory_mb' => 32768,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function assign(Tenant $tenant, array $quota = []): JsonResponse
    {
        return app(TenantController::class)->assignRegion(
            Request::create('/api/tenants/'.$tenant->id.'/regions', 'POST', ['region_id' => $this->region->id] + $quota),
            $tenant->id,
        );
    }

    private function quotaOf(Tenant $tenant): array
    {
        $pivot = DB::connection('central')->table('tenant_regions')
            ->where('tenant_id', $tenant->id)->where('knative_region_id', $this->region->id)->first();

        return [(int) $pivot->cpu_quota_m, (int) $pivot->memory_quota_mb];
    }

    // ── Organization ceiling → tenant quota defaults / no overcommit ────────

    public function test_a_tenant_quota_defaults_to_the_organization_ceiling(): void
    {
        config(['quota.cpu' => '4', 'quota.memory_gib' => '8']);
        $acme = $this->tenant('acme');

        $this->assertSame(200, $this->assign($acme)->getStatusCode());
        $this->assertSame([4000, 8192], $this->quotaOf($acme));
    }

    public function test_the_default_is_bounded_by_what_the_other_tenants_left(): void
    {
        config(['quota.cpu' => '4', 'quota.memory_gib' => '8']);
        $acme = $this->tenant('acme');
        $globex = $this->tenant('globex');

        $this->assertSame(200, $this->assign($acme, ['cpu_quota_m' => 3000, 'memory_quota_mb' => 6144])->getStatusCode());
        $this->assertSame(200, $this->assign($globex)->getStatusCode());
        $this->assertSame([1000, 2048], $this->quotaOf($globex));

        // Nothing left: the default would be 0 (= unlimited), so it is refused
        $initech = $this->tenant('initech');
        $this->assertSame(422, $this->assign($initech)->getStatusCode());
    }

    public function test_the_default_is_bounded_by_the_region_capacity(): void
    {
        config(['quota.cpu' => '32', 'quota.memory_gib' => '64']);
        $acme = $this->tenant('acme');

        $this->assertSame(200, $this->assign($acme)->getStatusCode());
        $this->assertSame([16000, 32768], $this->quotaOf($acme));
    }

    public function test_quotas_are_required_without_a_ceiling(): void
    {
        $this->expectException(ValidationException::class);
        $this->assign($this->tenant('acme'));
    }

    public function test_tenant_quotas_may_not_add_up_to_more_than_the_ceiling(): void
    {
        config(['quota.cpu' => '4', 'quota.memory_gib' => '8']);
        $acme = $this->tenant('acme');
        $globex = $this->tenant('globex');

        $this->assertSame(200, $this->assign($acme, ['cpu_quota_m' => 3000, 'memory_quota_mb' => 4096])->getStatusCode());
        $this->assertSame(422, $this->assign($globex, ['cpu_quota_m' => 2000, 'memory_quota_mb' => 1024])->getStatusCode());
        $this->assertSame(422, $this->assign($globex, ['cpu_quota_m' => 500, 'memory_quota_mb' => 5000])->getStatusCode());
        $this->assertSame(200, $this->assign($globex, ['cpu_quota_m' => 1000, 'memory_quota_mb' => 4096])->getStatusCode());

        // Raising an assigned quota is held to the same bound (its own share is not counted twice)
        $update = fn (array $quota) => app(TenantController::class)->updateRegionQuota(
            Request::create('/', 'PATCH', $quota), $acme->id, $this->region->id,
        )->getStatusCode();
        $this->assertSame(422, $update(['cpu_quota_m' => 3500]));
        $this->assertSame(200, $update(['cpu_quota_m' => 3000]));
        $this->assertSame([3000, 4096], $this->quotaOf($acme));
    }

    public function test_tenant_quotas_may_not_add_up_to_more_than_the_region_capacity(): void
    {
        $acme = $this->tenant('acme');
        $globex = $this->tenant('globex');

        $this->assertSame(200, $this->assign($acme, ['cpu_quota_m' => 10000, 'memory_quota_mb' => 16384])->getStatusCode());
        $this->assertSame(422, $this->assign($globex, ['cpu_quota_m' => 7000, 'memory_quota_mb' => 1024])->getStatusCode());
        $this->assertSame(200, $this->assign($globex, ['cpu_quota_m' => 6000, 'memory_quota_mb' => 16384])->getStatusCode());
    }

    // ── Admission (CheckTenantQuota) ────────────────────────────────────────

    private function quota(Tenant $tenant, int $cpuM, int $memoryMb): void
    {
        $tenant->regions()->syncWithoutDetaching([$this->region->id => ['cpu_quota_m' => $cpuM, 'memory_quota_mb' => $memoryMb]]);
    }

    /** Runs CheckTenantQuota for a tenant (non-admin) request on an already bound route. */
    private function through(Tenant $tenant, Request $request, Route $route): Response
    {
        config(['tenancy.enabled' => true]);
        $request->setRouteResolver(fn () => $route);
        $request->attributes->set('tenant', $tenant);

        return app(CheckTenantQuota::class)->handle($request, fn () => response('passed', 200));
    }

    private function createProject(Tenant $tenant, array $config, ?KnativeRegion $region = null): Response
    {
        $request = Request::create('/api/projects', 'POST', [
            'name' => 'new-fn',
            'service_type' => 'xaas',
            'knative_region_id' => ($region ?? $this->region)->id,
            'config' => $config,
        ]);
        $route = (new Route('POST', 'api/projects', []))->name('projects.store')->bind($request);

        return $this->through($tenant, $request, $route);
    }

    private function onProject(Tenant $tenant, Project $project, string $uri, array $payload): Response
    {
        $request = Request::create('/'.$uri, 'POST', $payload);
        $route = (new Route('POST', $uri, []))->bind($request);
        $route->setParameter('project', $project);

        return $this->through($tenant, $request, $route);
    }

    private function existingProject(string $cpu = '250m', int $memoryMb = 256, int $minScale = 0, string $name = 'existing', bool $scaleToZero = true, ?KnativeRegion $region = null): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => ($region ?? $this->region)->id])->saveQuietly();
        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => 'main.py',
            'handler' => 'handler',
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'trigger_type' => 'http',
            'min_scale' => $minScale,
            'scale_to_zero' => $scaleToZero,
            'max_scale' => 5,
        ]);

        return $project->fresh(['xaasConfig', 'knativeRegion']);
    }

    /** Scale-to-zero projects of $cpu / 256 MiB: 250m → 350m / 356 MiB each with the queue-proxy. */
    private function scaleToZeroProjects(int $count, string $prefix = 's2z', ?KnativeRegion $region = null, string $cpu = '250m'): void
    {
        foreach (range(1, $count) as $i) {
            $this->existingProject($cpu, 256, 0, "{$prefix}-{$i}", true, $region);
        }
    }

    /** The overcommit factors of the test region (1 = the quota is hard for every workload). */
    private function factors(float $cpu, float $memory): void
    {
        $this->region->update(['faas_cpu_overcommit' => $cpu, 'faas_memory_overcommit' => $memory]);
    }

    /** @return array<string, mixed> the 422 body */
    private function refusal(Response $response): array
    {
        $this->assertSame(422, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true);
    }

    public function test_admission_counts_min_scale_instances(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 0); // 1 vCPU, memory unlimited

        // 250m + 100m queue-proxy per instance
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '250m', 'min_scale' => 0])->getStatusCode()); // 350m
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '250m', 'min_scale' => 1])->getStatusCode()); // 350m
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '250m', 'min_scale' => 2])->getStatusCode()); // 700m
        $this->assertSame(422, $this->createProject($acme, ['cpu' => '250m', 'min_scale' => 3])->getStatusCode()); // 1050m

        // max_scale is not admitted
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '250m', 'min_scale' => 0, 'max_scale' => 100])->getStatusCode());
    }

    public function test_a_new_project_without_size_is_admitted_at_the_micro_default(): void
    {
        // a hard quota: the micro default counts as it is
        $this->factors(1, 1);
        $acme = $this->tenant('acme');
        $this->quota($acme, 0, 356); // memory only: 256 MiB micro + 100 MiB queue-proxy

        $this->assertSame(200, $this->createProject($acme, [])->getStatusCode());

        $this->quota($acme, 0, 355);
        $this->assertSame(422, $this->createProject($acme, [])->getStatusCode());
    }

    public function test_an_unchanged_project_is_grandfathered_when_the_tenant_is_over_quota(): void
    {
        // a hard quota: the scale-to-zero project counts in full
        $this->factors(1, 1);
        $acme = $this->tenant('acme');
        $project = $this->existingProject();

        // Exactly at quota with the former 25m queue-proxy: 250m + 25m, 256 MiB + 100 MiB.
        // With the 100m queue-proxy the project now reserves 350m — the tenant is over quota.
        $this->quota($acme, 275, 356);

        // Redeploy, env edit, same size: allowed
        $this->assertSame(200, $this->onProject($acme, $project, "api/projects/{$project->id}/deploy", ['version_id' => 'v1'])->getStatusCode());
        $this->assertSame(200, $this->onProject($acme, $project, "api/projects/{$project->id}", ['config' => ['env_vars' => ['A' => 'b']]])->getStatusCode());
        $this->assertSame(200, $this->onProject($acme, $project, "api/projects/{$project->id}", ['config' => ['cpu' => '200m']])->getStatusCode());

        // Growing is checked against the remaining quota
        $this->assertSame(422, $this->onProject($acme, $project, "api/projects/{$project->id}", ['config' => ['cpu' => '300m']])->getStatusCode());
        $this->assertSame(422, $this->onProject($acme, $project, "api/projects/{$project->id}", ['config' => ['min_scale' => 2]])->getStatusCode());
        $this->assertSame(422, $this->onProject($acme, $project, "api/projects/{$project->id}/deploy", ['version_id' => 'v1', 'deploy_config' => ['min_scale' => 2]])->getStatusCode());

        // A new project is rejected
        $this->assertSame(422, $this->createProject($acme, ['cpu' => '125m', 'memory_mb' => 128])->getStatusCode());

        // The quota itself is not raised
        $this->assertSame([275, 356], $this->quotaOf($acme));
    }

    // ── Always-on and FaaS overcommit budgets of the quota ──────────────────

    public function test_always_on_projects_count_in_full_against_the_quota(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 0);
        $this->existingProject('250m', 256, 1, 'api'); // 350m always-on

        // min_scale 2: 700m more always-on, 1050m > the 1000m quota
        $body = $this->refusal($this->createProject($acme, ['cpu' => '250m', 'min_scale' => 2]));
        $this->assertSame('always_on', $body['budget']);
        $this->assertSame(650, $body['remaining_cpu_m']);
        $this->assertSame(
            "CPU quota exceeded on the organization's always-on budget (no overcommit): this needs 700m, 650m of the quota 1000m on this region are free. Always-on workloads, Kata pod overhead, queue consumers and sandboxes count in full.",
            $body['message'],
        );

        // scale to zero off keeps one pod: 350m always-on fits
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '250m', 'scale_to_zero' => false])->getStatusCode());
        // a scale-to-zero project larger than the quota still fits the FaaS overcommit budget:
        // 350m always-on + 2100m of 1000m × 3
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '2000m'])->getStatusCode());
    }

    public function test_scale_to_zero_projects_count_against_the_faas_overcommit_budget(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 0);

        // 5 × 350m = 1750m scale-to-zero: more than the quota, within 1000m × 3
        $this->scaleToZeroProjects(5);
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '250m'])->getStatusCode());

        // 8 × 350m = 2800m: one more needs 350m, 200m of the 3000m are left
        $this->scaleToZeroProjects(3, 'more');
        $body = $this->refusal($this->createProject($acme, ['cpu' => '250m']));
        $this->assertSame('faas', $body['budget']);
        $this->assertSame(200, $body['remaining_cpu_m']);
        $this->assertSame(
            "CPU quota exceeded on the organization's FaaS overcommit budget: this needs 350m, 200m of 3000m are free on this region (quota 1000m × 3, minus 0m always-on).",
            $body['message'],
        );

        // what the always-on budget holds is not left for scale-to-zero projects: with 350m
        // always-on the FaaS budget is 2650m, 2800m of it are taken
        $this->existingProject('250m', 256, 1, 'warm');
        $this->assertStringContainsString('0m of 2650m are free', $this->refusal($this->createProject($acme, ['cpu' => '10m']))['message']);
    }

    public function test_memory_overcommits_by_its_own_factor(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 0, 1000); // CPU unlimited

        // 3 × 356 MiB = 1068 MiB of 1000 MiB × 1.5: one more fits, the CPU without a quota is not checked
        $this->scaleToZeroProjects(3);
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '4000m', 'memory_mb' => 256])->getStatusCode());

        // 4 × 356 MiB = 1424 MiB: 76 MiB are left
        $this->existingProject('250m', 256, 0, 'fourth');
        $body = $this->refusal($this->createProject($acme, ['cpu' => '250m', 'memory_mb' => 256]));
        $this->assertSame('faas', $body['budget']);
        $this->assertSame(
            "Memory quota exceeded on the organization's FaaS overcommit budget: this needs 356 MB, 76 MB of 1500 MB are free on this region (quota 1000 MB × 1.5, minus 0 MB always-on).",
            $body['message'],
        );
        $this->assertSame(76, $body['remaining_memory_mb']);
        // the CPU has no quota: not named, nothing counted down
        $this->assertArrayNotHasKey('remaining_cpu_m', $body);
        $this->assertSame(['message', 'budget', 'remaining_memory_mb'], array_keys($body));
    }

    public function test_a_resource_without_a_quota_has_no_remaining_value(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 0); // memory unlimited
        $quotas = app(TenantQuotaService::class);
        $demand = ['hard' => ['cpu_m' => 350, 'memory_mb' => 356], 'burst' => ['cpu_m' => 0, 'memory_mb' => 0]];

        $admitted = $quotas->checkReservation($acme, $this->region, $demand);
        $this->assertSame(['allowed' => true, 'remaining_cpu_m' => 650], $admitted);

        $demand['hard']['cpu_m'] = 1200;
        $refused = $quotas->checkReservation($acme, $this->region, $demand);
        $this->assertSame(1000, $refused['remaining_cpu_m'] ?? null); // all of the always-on budget, 1200m needed
        $this->assertArrayNotHasKey('remaining_memory_mb', $refused);
        $this->assertStringStartsWith('CPU quota exceeded', $refused['message']);
        $this->assertStringNotContainsString('Memory', $refused['message']);

        // a region the organization has no quota on: refused without numbers
        $other = KnativeRegion::create(['name' => 'other', 'project_namespace' => 'other', 'is_active' => true, 'total_cpu_m' => 1000, 'total_memory_mb' => 1000]);
        $this->assertSame(['message' => 'Tenant has no access to this region.'], $this->refusal($this->createProject($acme, ['cpu' => '250m'], $other)));
    }

    public function test_a_sandbox_refusal_names_only_the_resources_with_a_quota(): void
    {
        $acme = $this->createTenantWithSchema('acme');
        $this->quota($acme, 0, 512); // CPU unlimited
        config(['tenancy.enabled' => true]);

        try {
            // 1024 MiB always-on > the 512 MiB quota; 8 CPUs are not limited
            app(SandboxQuotaService::class)->checkRegionQuota($acme->id, $this->region, 8000, 1024, SandboxClassResolver::RUNC);
            $this->fail('the sandbox fits the quota');
        } catch (SandboxException $e) {
            $this->assertSame('quota_region', $e->reason);
            $this->assertSame(['remaining_memory_mb' => 512], $e->extra);
            $this->assertStringStartsWith('Memory quota exceeded on the organization\'s always-on budget', $e->getMessage());
        }
    }

    public function test_a_factor_of_1_makes_the_quota_hard(): void
    {
        $this->factors(1, 1);
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 0);

        // 2 × 350m scale-to-zero + 350m = 1050m > 1000m × 1
        $this->scaleToZeroProjects(2);
        $body = $this->refusal($this->createProject($acme, ['cpu' => '250m']));
        $this->assertSame('faas', $body['budget']);
        $this->assertStringContainsString('this needs 350m, 300m of 1000m are free on this region (quota 1000m × 1, minus 0m always-on)', $body['message']);
        $this->assertSame(200, $this->createProject($acme, ['cpu' => '150m'])->getStatusCode()); // 250m fits
    }

    public function test_an_existing_project_that_keeps_pods_running_needs_room_on_the_always_on_budget(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 0);
        $api = $this->existingProject('250m', 256, 2, 'api'); // 700m always-on
        $project = $this->existingProject('250m', 256, 0, 'worker');

        // min_scale 1 or scale to zero off: 350m more always-on, 1050m > 1000m
        foreach ([['min_scale' => 1], ['scale_to_zero' => false]] as $config) {
            $body = $this->refusal($this->onProject($acme, $project, "api/projects/{$project->id}", ['config' => $config]));
            $this->assertSame('always_on', $body['budget'], (string) json_encode($config));
        }
        $this->assertSame(422, $this->onProject($acme, $project, "api/projects/{$project->id}/deploy", ['version_id' => 'v1', 'deploy_config' => ['min_scale' => 1]])->getStatusCode());

        // growing while it scales to zero is checked against the FaaS budget: 400m of 2300m
        $this->assertSame(200, $this->onProject($acme, $project, "api/projects/{$project->id}", ['config' => ['cpu' => '300m']])->getStatusCode());
        // dropping to scale to zero frees the always-on budget and always passes
        $this->assertSame(200, $this->onProject($acme, $api, "api/projects/{$api->id}", ['config' => ['min_scale' => 0]])->getStatusCode());
    }

    public function test_the_kata_overhead_of_scale_to_zero_projects_counts_against_the_quota(): void
    {
        $kata = KnativeRegion::create([
            'name' => 'kata', 'project_namespace' => 'kata', 'is_active' => true,
            'total_cpu_m' => 16000, 'total_memory_mb' => 32768, 'sandbox_xaas_runtime' => 'kata-clh',
        ]);
        $acme = $this->tenant('acme');
        $acme->regions()->syncWithoutDetaching([$kata->id => ['cpu_quota_m' => 1000, 'memory_quota_mb' => 0]]);

        // 4 scale-to-zero Kata projects: 250m overhead each always-on, 110m each against the FaaS budget
        $this->scaleToZeroProjects(4, 'k', $kata, '10m');

        $body = $this->refusal($this->createProject($acme, ['cpu' => '10m'], $kata));
        $this->assertSame('always_on', $body['budget']);
        $this->assertStringContainsString('this needs 250m, 0m of the quota 1000m on this region are free', $body['message']);
    }

    public function test_budgets_show_both_parts_of_the_quota(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 1000);
        $this->existingProject('250m', 256, 1, 'api'); // 350m / 356 MiB always-on
        $this->scaleToZeroProjects(2);                  // 700m / 712 MiB scale-to-zero

        $quotas = app(TenantQuotaService::class);
        $budgets = $quotas->budgets($this->region->fresh(), ['cpu_m' => 1000, 'memory_mb' => 1000]);

        $this->assertSame([
            'capacity' => 1000, 'always_on_used' => 350, 'always_on_available' => 650,
            'faas_overcommit' => 3.0, 'faas_budget' => 2650, 'faas_used' => 700, 'faas_available' => 1950,
        ], $budgets['cpu_m']);
        $this->assertSame([
            'capacity' => 1000, 'always_on_used' => 356, 'always_on_available' => 644,
            'faas_overcommit' => 1.5, 'faas_budget' => 1144, 'faas_used' => 712, 'faas_available' => 432,
        ], $budgets['memory_mb']);

        // currentUsage (billing summary, usage pages) keeps adding up both parts
        $this->assertSame(['cpu_m' => 1050, 'memory_mb' => 1068], $quotas->currentUsage($this->region));
    }

    public function test_the_settings_of_an_organization_carry_its_quota_budgets(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 2048);
        $this->existingProject('250m', 256, 1, 'api'); // 350m always-on
        $this->scaleToZeroProjects(2);                  // 700m scale-to-zero

        request()->attributes->set('tenant', $acme);
        $controller = new class extends TenancySettingsController
        {
            // the regions only: executor images and settings live in the tenant schema
            protected function baseBuildSettingsData(): array
            {
                return ['regions' => KnativeRegionResource::collection(KnativeRegion::orderBy('name')->get())];
            }
        };

        $region = collect($controller->index()->getData(true)['regions'])->firstWhere('id', $this->region->id);

        // the projects page draws the quota's two budgets in place of the region's
        $this->assertSame('quota', $region['capacity_scope']);
        $this->assertSame(1000, $region['total_cpu_m']);
        // (JSON: the factor 3.0 is the number 3)
        $this->assertSame([
            'capacity' => 1000, 'always_on_used' => 350, 'always_on_available' => 650,
            'faas_overcommit' => 3, 'faas_budget' => 2650, 'faas_used' => 700, 'faas_available' => 1950,
        ], $region['capacity']['cpu_m']);
        $this->assertSame(2048, $region['capacity']['memory_mb']['capacity']);
    }

    public function test_administrators_see_the_usage_of_the_organization_they_open(): void
    {
        $acme = $this->createTenantWithSchema('acme');
        $ops = $this->createTenantWithSchema('ops');
        $this->quota($acme, 1000, 2048);

        // acme's own schema: 350m always-on, 350m scale-to-zero
        $acme->run(function () {
            $this->existingProject('250m', 256, 1, 'api');
            $this->existingProject('250m', 256, 0, 'worker');
        });
        // other projects on the same region: of the organization the administrator works in, and central
        $ops->run(fn () => $this->existingProject('2000m', 1024, 2, 'ops-big'));
        $this->existingProject('4000m', 4096, 1, 'central-big');

        tenancy()->initialize($ops);
        $rows = app(TenantController::class)->regions($acme->id)->getData(true);
        // the administrator's context is back after counting
        $this->assertSame($ops->getTenantKey(), tenancy()->tenant?->getTenantKey());

        $row = collect($rows)->firstWhere('id', $this->region->id);
        $this->assertSame([700, 712], [$row['used_cpu_m'], $row['used_memory_mb']]);
        // (JSON: the factor 3.0 is the number 3)
        $this->assertSame([
            'capacity' => 1000, 'always_on_used' => 350, 'always_on_available' => 650,
            'faas_overcommit' => 3, 'faas_budget' => 2650, 'faas_used' => 350, 'faas_available' => 2300,
        ], $row['capacity']['cpu_m']);
        $this->assertSame([
            'capacity' => 2048, 'always_on_used' => 356, 'always_on_available' => 1692,
            'faas_overcommit' => 1.5, 'faas_budget' => 2716, 'faas_used' => 356, 'faas_available' => 2360,
        ], $row['capacity']['memory_mb']);
    }

    public function test_reservations_without_a_split_count_in_full(): void
    {
        $acme = $this->tenant('acme');
        $this->quota($acme, 1000, 0);
        $quotas = app(TenantQuotaService::class);

        // {cpu_m, memory_mb} (sandboxes) is always-on: 1200m > 1000m although 3000m × 3 would be left
        $result = $quotas->checkReservation($acme, $this->region, ['cpu_m' => 1200, 'memory_mb' => 0]);
        $this->assertFalse($result['allowed']);
        $this->assertSame('always_on', $result['budget']);
        $this->assertArrayHasKey('cpu_m', $result['problems']);

        $burst = ['hard' => ['cpu_m' => 0, 'memory_mb' => 0], 'burst' => ['cpu_m' => 1200, 'memory_mb' => 0]];
        $this->assertTrue($quotas->checkReservation($acme, $this->region, $burst)['allowed']);
    }

    // ── The organization's own regions ──────────────────────────────────────

    /** A region of the tenant's own (its schema, not synced), created the way the region API does. */
    private function ownRegion(Tenant $tenant, string $name = 'own', array $attributes = []): KnativeRegion
    {
        return $tenant->run(function () use ($name, $attributes) {
            $region = new KnativeRegion(['name' => $name, 'project_namespace' => $name, 'is_active' => true, 'total_cpu_m' => 4000, 'total_memory_mb' => 8192]);
            $region->forceFill(['is_synced' => false] + $attributes)->save();

            return $region;
        });
    }

    public function test_projects_on_the_organizations_own_region_need_no_quota(): void
    {
        $acme = $this->createTenantWithSchema('acme');
        $own = $this->ownRegion($acme);
        $quotas = app(TenantQuotaService::class);
        $demand = ['hard' => ['cpu_m' => 3000, 'memory_mb' => 4096], 'burst' => ResourceReservation::zero()];

        // A new project on it, as the request runs: in the tenant's context, no tenant_regions row
        $this->assertSame(200, $acme->run(fn () => $this->createProject($acme, ['cpu' => '2', 'min_scale' => 1], $own))->getStatusCode());
        // The tenant's copy as well as a model read outside the tenant (sandboxes, previews)
        $this->assertTrue($acme->run(fn () => $quotas->checkReservation($acme, KnativeRegion::find($own->id), $demand))['allowed']);
        $this->assertTrue($quotas->checkReservation($acme, $own, $demand)['allowed']);

        // A platform region the organization was not given stays closed — and so is another
        // organization's own region
        $refused = $quotas->checkReservation($acme, $this->region, $demand);
        $this->assertFalse($refused['allowed']);
        $this->assertSame(__('api.tenant.no_region_access'), $refused['message']);
        $ops = $this->createTenantWithSchema('ops');
        $this->assertFalse($quotas->checkReservation($ops, $own, $demand)['allowed']);
    }

    public function test_the_owner_of_a_region_decides_without_a_lookup(): void
    {
        $acme = $this->tenant('acme');
        $ops = $this->tenant('ops');
        $owned = new KnativeRegion(['name' => 'owned', 'project_namespace' => 'owned']);
        $owned->forceFill(['id' => (string) Str::ulid(), 'owner_tenant_id' => $acme->id]);
        $quotas = app(TenantQuotaService::class);

        $this->assertTrue($quotas->ownsRegion($acme, $owned));
        $this->assertFalse($quotas->ownsRegion($ops, $owned));
    }
}
