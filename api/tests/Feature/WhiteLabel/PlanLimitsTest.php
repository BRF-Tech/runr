<?php

namespace Tests\Feature\WhiteLabel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Tenancy\Http\Middleware\EnforcePlanLimits;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Services\Plans\PlanLimitService;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\WhiteLabel\Concerns\WhiteLabelTenancy;
use Tests\TestCase;

/**
 * Plan limits beyond CPU / memory and feature flags (EnforcePlanLimits): projects, GPUs,
 * invocations per month (no new deploys once used up), storage (no new code once full), and the
 * custom_domains / private_connections / api_tokens / sso features. No-op while plans are off, for
 * tenants without a plan and for platform admins.
 */
class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;
    use WhiteLabelTenancy;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWhiteLabelTenancy(false);
        $this->enablePlans();

        // + GPU columns of the config tables and the model service tables (GPU quota)
        $this->tenant = $this->createTenantWithSchema('acme'.Str::lower(Str::random(5)), [
            ...self::WL_TENANT_MIGRATIONS,
            'Modules/Tenancy/Database/TenantMigrations/0045_add_gpu_columns.php',
            'Modules/Tenancy/Database/TenantMigrations/0046_create_ai_model_tables.php',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('git/'.$this->tenant->slug));
        Cache::flush();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function onPlan(array $attrs): Plan
    {
        $plan = $this->plan($attrs);
        TenantSubscription::forTenant($this->tenant->id)->forceFill(['plan_id' => $plan->id, 'status' => 'active'])->save();

        return $plan;
    }

    private function send(string $method, string $uri, array $input = [], bool $admin = false): Response
    {
        $request = Request::create($uri, $method, $input);
        $request->attributes->set('tenant', $this->tenant);
        $user = $admin ? $this->admin() : $this->member($this->tenant);
        $request->setUserResolver(fn () => $user);

        return app(EnforcePlanLimits::class)->handle($request, fn () => response('passed'));
    }

    /** @return list<string> project ids; $gpu: config.gpu.count of each project (FaaS) */
    private function projects(int $count, ?int $gpu = null, int $minScale = 0): array
    {
        return $this->tenant->run(function () use ($count, $gpu, $minScale) {
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $id = (string) Str::ulid();
                $slug = 'p'.Str::lower(Str::random(8));
                $ids[] = $id;
                DB::table('projects')->insert([
                    'id' => $id, 'name' => $slug, 'slug' => $slug, 'service_type' => 'faas',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('faas_configs')->insert([
                    'id' => (string) Str::ulid(), 'project_id' => $id, 'handler' => 'main.handler', 'min_scale' => $minScale,
                    'gpu' => $gpu ? json_encode(['count' => $gpu]) : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $ids;
        });
    }

    /** A model service on device gpu (stopped ones hold no GPUs). */
    private function gpuService(int $count, string $status = 'ready'): string
    {
        return $this->tenant->run(function () use ($count, $status) {
            $id = (string) Str::ulid();
            DB::table('ai_model_services')->insert([
                'id' => $id, 'ai_model_id' => (string) Str::ulid(), 'knative_region_id' => (string) Str::ulid(),
                'name' => 's'.Str::lower(Str::random(8)), 'service_name' => 'svc', 'engine' => 'vllm', 'device' => 'gpu',
                'gpu' => json_encode(['count' => $count]), 'served_name' => 'm', 'status' => $status,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    private function invocations(int $sessions, string $date): void
    {
        $this->tenant->run(fn () => DB::table('usage_records')->insert([
            'id' => (string) Str::ulid(), 'project_id' => (string) Str::ulid(), 'deployment_id' => (string) Str::ulid(),
            'region_id' => (string) Str::ulid(), 'record_date' => $date, 'scale_mode' => 'scale_to_zero', 'entry_point' => 'http',
            'session_count' => $sessions, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_nothing_is_checked_while_plans_are_off_or_without_a_plan(): void
    {
        $this->projects(3);

        $this->assertSame('passed', $this->send('POST', '/api/projects')->getContent());

        $this->onPlan(['max_projects' => 1]);
        $this->enablePlans(false);
        $this->assertSame('passed', $this->send('POST', '/api/projects')->getContent());
    }

    public function test_the_project_limit(): void
    {
        $this->onPlan(['max_projects' => 2]);
        $this->projects(1);

        $this->assertSame('passed', $this->send('POST', '/api/projects')->getContent());

        $this->projects(1);
        $response = $this->send('POST', '/api/projects');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['plan_limit', 'max_projects', 2, 2], [
            json_decode($response->getContent(), true)['code'],
            json_decode($response->getContent(), true)['limit'],
            json_decode($response->getContent(), true)['max'],
            json_decode($response->getContent(), true)['used'],
        ]);

        // reads and admins are never limited
        $this->assertSame('passed', $this->send('GET', '/api/projects')->getContent());
        $this->assertSame('passed', $this->send('POST', '/api/projects', [], true)->getContent());
    }

    public function test_gpus_need_the_gpu_feature_and_fit_the_gpu_quota(): void
    {
        $gpu = fn (int $count, int $minScale = 0) => ['config' => ['gpu' => ['count' => $count], 'min_scale' => $minScale]];

        $this->onPlan(['gpu_quota' => 2, 'features' => ['gpu' => false]]);
        $response = $this->send('POST', '/api/projects', $gpu(1));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('gpu', json_decode($response->getContent(), true)['feature']);

        $this->onPlan(['gpu_quota' => 2, 'features' => ['gpu' => true]]);
        [$first] = $this->projects(1, 1);
        $this->assertSame('passed', $this->send('POST', '/api/projects', $gpu(1))->getContent());
        // count × max(1, min_scale)
        $this->assertSame(422, $this->send('POST', '/api/projects', $gpu(1, 2))->getStatusCode());

        $this->projects(1, 1);
        $response = $this->send('POST', '/api/projects', $gpu(1));
        $this->assertSame('gpu_quota', json_decode($response->getContent(), true)['limit']);
        $this->assertSame(2, json_decode($response->getContent(), true)['used']);

        // a GPU size raised on an existing project is checked too, without its own current GPUs
        $this->assertSame(422, $this->send('PATCH', "/api/projects/{$first}", $gpu(2))->getStatusCode());
        $this->assertSame('passed', $this->send('PATCH', "/api/projects/{$first}", $gpu(1))->getContent());
        // projects without config.gpu are not GPU requests
        $this->assertSame('passed', $this->send('PATCH', "/api/projects/{$first}", ['config' => ['memory_mb' => 512]])->getContent());
    }

    public function test_model_services_on_gpu_count_toward_the_gpu_quota(): void
    {
        $this->onPlan(['gpu_quota' => 2, 'features' => ['gpu' => true]]);
        $this->gpuService(1, 'stopped');   // holds no GPUs
        $service = $this->gpuService(1);
        $this->projects(1);                // CPU project

        $this->assertSame(1, app(PlanLimitService::class)->usage($this->tenant)['gpu']);
        $this->assertSame('passed', $this->send('POST', '/api/model-services', ['device' => 'gpu'])->getContent());
        $this->assertSame(422, $this->send('POST', '/api/model-services', ['device' => 'gpu', 'gpu' => ['count' => 2]])->getStatusCode());
        $this->assertSame('passed', $this->send('POST', '/api/model-services', ['device' => 'cpu'])->getContent());
        // the service being changed is left out of the count
        $this->assertSame('passed', $this->send('PATCH', "/api/model-services/{$service}", ['gpu' => ['count' => 2]])->getContent());

        $this->projects(1, 1);
        $this->assertSame(2, app(PlanLimitService::class)->usage($this->tenant)['gpu']);
        $this->assertSame(422, $this->send('POST', '/api/model-services', ['device' => 'gpu'])->getStatusCode());
    }

    public function test_used_up_invocations_block_new_deploys(): void
    {
        $this->onPlan(['invocations_per_month' => 1000]);
        $this->invocations(5000, now()->subMonthNoOverflow()->startOfMonth()->toDateString());   // last month
        $this->invocations(400, now()->toDateString());

        $this->assertSame('passed', $this->send('POST', '/api/projects/abc/deploy')->getContent());

        $this->invocations(600, now()->toDateString());
        $response = $this->send('POST', '/api/projects/abc/deploy');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('invocations_per_month', json_decode($response->getContent(), true)['limit']);

        $this->assertSame(['projects' => 0, 'gpu' => 0, 'invocations_this_month' => 1000, 'storage_mb' => 0], app(PlanLimitService::class)->usage($this->tenant));
    }

    public function test_a_full_storage_blocks_new_code(): void
    {
        $this->onPlan(['storage_quota_mb' => 1]);
        $dir = storage_path('git/'.$this->tenant->slug);
        File::ensureDirectoryExists($dir);
        File::put($dir.'/blob', str_repeat('x', 2 * 1048576));

        $response = $this->send('POST', '/api/projects/abc/files');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('storage_quota_mb', json_decode($response->getContent(), true)['limit']);
        $this->assertSame(422, $this->send('POST', '/api/projects/abc/code')->getStatusCode());
    }

    public function test_feature_flags_switch_endpoints_off(): void
    {
        $this->onPlan(['features' => ['custom_domains' => false, 'private_connections' => false, 'api_tokens' => false, 'sso' => false]]);

        foreach ([
            '/api/projects/abc/domains' => 'custom_domains',
            '/api/projects/abc/private-connections' => 'private_connections',
            '/api/tokens' => 'api_tokens',
            '/api/auth-providers' => 'sso',
        ] as $uri => $feature) {
            $response = $this->send('POST', $uri);
            $this->assertSame(422, $response->getStatusCode(), $uri);
            $this->assertSame($feature, json_decode($response->getContent(), true)['feature']);
        }

        // a flag that is not set on the plan is allowed
        $this->onPlan(['features' => []]);
        $this->assertSame('passed', $this->send('POST', '/api/projects/abc/domains')->getContent());
    }

    public function test_a_canceled_subscription_has_no_plan_limits(): void
    {
        $this->onPlan(['max_projects' => 0]);
        TenantSubscription::findForTenant($this->tenant->id)->forceFill(['status' => 'canceled'])->save();

        $this->assertSame('passed', $this->send('POST', '/api/projects')->getContent());
    }
}
