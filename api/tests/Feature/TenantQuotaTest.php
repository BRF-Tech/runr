<?php

namespace Tests\Feature;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\XaasConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Http\Controllers\TenantController;
use Modules\Tenancy\Http\Middleware\CheckTenantQuota;
use Modules\Tenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Tenant quotas (multi-tenant edition):
 *  - Admission counts size × max(1, min_scale) (+ queue-proxy per instance);
 *    existing projects that do not grow are grandfathered.
 *  - With an organization ceiling a tenant's quota on a region defaults to the
 *    ceiling (bounded by what is still free), and the tenants' quotas on a region never add up
 *    to more than the ceiling or the region capacity (no overcommit).
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

    private function createProject(Tenant $tenant, array $config): Response
    {
        $request = Request::create('/api/projects', 'POST', [
            'name' => 'new-fn',
            'service_type' => 'xaas',
            'knative_region_id' => $this->region->id,
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

    private function existingProject(string $cpu = '250m', int $memoryMb = 256, int $minScale = 0): Project
    {
        $project = Project::create(['name' => 'existing', 'service_type' => 'xaas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => 'main.py',
            'handler' => 'handler',
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'trigger_type' => 'http',
            'min_scale' => $minScale,
            'max_scale' => 5,
        ]);

        return $project->fresh(['xaasConfig', 'knativeRegion']);
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
        $acme = $this->tenant('acme');
        $this->quota($acme, 0, 356); // memory only: 256 MiB micro + 100 MiB queue-proxy

        $this->assertSame(200, $this->createProject($acme, [])->getStatusCode());

        $this->quota($acme, 0, 355);
        $this->assertSame(422, $this->createProject($acme, [])->getStatusCode());
    }

    public function test_an_unchanged_project_is_grandfathered_when_the_tenant_is_over_quota(): void
    {
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
}
