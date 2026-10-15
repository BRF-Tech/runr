<?php

namespace Tests\Feature\Ai;

use App\Models\AiModel;
use App\Models\AiModelService;
use App\Models\AiModelServiceUsage;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\Ai\Models\ModelUsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Jobs\UsageAggregationJob;
use Modules\Tenancy\Models\CostConfig;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\CostCalculationService;
use Modules\Tenancy\Services\TenantGpuQuota;
use Modules\Tenancy\Services\TenantQuotaService;
use Tests\Concerns\BuildsAiFixtures;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Tenant GPU quota (tenant_regions.gpu_quota; null = no limit, 0 = no GPUs): projects and model
 * services reserve count × max(1, min_scale), stopped services nothing, platform admins are
 * exempt, infinite regions unlimited; model services count into the CPU / memory quota too.
 * Billing: GPU-unit ms × gpu_price_per_unit_ms — sessions × GPUs (scale to zero), min_scale ×
 * GPUs × a day (custom scale), metered model service pods (source "model").
 */
class GpuQuotaAndUsageTest extends TestCase
{
    use BuildsAiFixtures, RefreshDatabase, WiresTenancy;

    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0009_create_usage_records_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0014_create_deployment_sessions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0022_add_agent_token_hash_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0024_add_function_tls_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0045_add_gpu_columns.php',
        'Modules/Tenancy/Database/TenantMigrations/0046_create_ai_model_tables.php',
    ];

    private Tenant $tenant;

    private string $regionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        $central = KnativeRegion::create(['name' => 'gpu', 'project_namespace' => 'fn', 'is_active' => true, 'total_cpu_m' => 64000, 'total_memory_mb' => 262144]);
        $this->regionId = $central->id;

        $this->tenant = $this->createTenantWithSchema('acme', self::TENANT_MIGRATIONS);
        DB::connection('central')->table('tenant_regions')->insert([
            'tenant_id' => $this->tenant->id, 'knative_region_id' => $central->id,
            'cpu_quota_m' => 0, 'memory_quota_mb' => 0, 'gpu_quota' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // the tenant's copy of the region
        $this->tenant->run(function () use ($central) {
            $copy = new KnativeRegion(['name' => 'gpu', 'project_namespace' => 'fn', 'is_active' => true]);
            $copy->forceFill(['id' => $central->id, 'is_synced' => true])->save();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function inTenant(\Closure $fn): mixed
    {
        return $this->tenant->run($fn);
    }

    private function gpuProject(string $name, int $gpus, int $minScale = 0, bool $scaleToZero = true): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->regionId])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id, 'image' => 'ghcr.io/acme/infer:1', 'cpu' => '1', 'memory_mb' => 1024,
            'trigger_type' => 'http', 'min_scale' => $minScale, 'scale_to_zero' => $scaleToZero, 'gpu' => ['count' => $gpus],
        ]);

        return $project->fresh(['containerConfig']);
    }

    private function gpuService(int $gpus, int $minScale, string $status = AiModelService::STATUS_READY): AiModelService
    {
        $region = KnativeRegion::find($this->regionId);
        $model = AiModel::firstOrCreate(['name' => 'llm'], ['source' => 'hub', 'hub_id' => 'acme/llm', 'task' => 'text-generation']);

        return $this->modelService($model, $region, [
            'engine' => 'vllm', 'device' => 'gpu', 'gpu' => ['count' => $gpus], 'min_scale' => $minScale,
            'cpu' => '2', 'memory_mb' => 8192, 'status' => $status,
        ]);
    }

    public function test_projects_and_running_model_services_use_the_gpu_quota(): void
    {
        $this->inTenant(function () {
            $quota = app(TenantGpuQuota::class);
            $region = KnativeRegion::find($this->regionId);

            $this->assertSame(2, $quota->quota($region));

            $this->gpuProject('infer', 1);                      // 1 × max(1, 0) = 1
            $this->gpuService(1, 0, AiModelService::STATUS_STOPPED); // stopped: 0
            $this->assertSame(1, $quota->used($region));

            $this->assertTrue($quota->check($region, 1)['allowed']);
            $denied = $quota->check($region, 2);
            $this->assertFalse($denied['allowed']);
            $this->assertSame(1, $denied['remaining']);

            $this->gpuService(1, 1);                             // running, min_scale 1: 1
            $this->assertSame(2, $quota->used($region));
            $this->assertFalse($quota->check($region, 1)['allowed']);

            // a project's own reservation does not count against its update
            $scaled = $this->gpuProject('scaled', 1, 2);         // 1 × 2
            $this->assertSame(4, $quota->used($region));
            $this->assertSame(2, $quota->used($region, $scaled->id));
        });
    }

    public function test_platform_admins_and_infinite_regions_have_no_gpu_limit(): void
    {
        // users live in the central database here
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true]);

        $this->inTenant(function () use ($admin) {
            $quota = app(TenantGpuQuota::class);
            $region = KnativeRegion::find($this->regionId);
            $this->gpuProject('infer', 2);

            $this->assertFalse($quota->check($region, 1)['allowed']);

            $this->actingAs($admin);
            $this->assertTrue($quota->check($region, 5)['allowed']);
            auth()->logout();

            $region->forceFill(['region_mode' => 'infinite'])->save();
            $this->assertNull($quota->quota($region->fresh()));
        });

        DB::connection('central')->table('tenant_regions')->update(['gpu_quota' => null]);
        $this->inTenant(fn () => $this->assertNull(app(TenantGpuQuota::class)->quota(KnativeRegion::find($this->regionId))));

        DB::connection('central')->table('tenant_regions')->update(['gpu_quota' => 0]);
        $this->inTenant(function () {
            $region = KnativeRegion::find($this->regionId);
            $region->forceFill(['region_mode' => 'managed'])->save();
            $this->assertFalse(app(TenantGpuQuota::class)->check($region->fresh(), 1)['allowed'], '0 = no GPUs');
        });
    }

    public function test_model_services_count_into_the_cpu_and_memory_quota(): void
    {
        $this->inTenant(function () {
            $region = KnativeRegion::find($this->regionId);
            $before = app(TenantQuotaService::class)->currentUsage($region);

            $this->gpuService(1, 1);   // 2000m + queue-proxy, 8192 MiB + queue-proxy, × 1
            $after = app(TenantQuotaService::class)->currentUsage($region);

            $this->assertGreaterThanOrEqual(2000, $after['cpu_m'] - $before['cpu_m']);
            $this->assertGreaterThanOrEqual(8192, $after['memory_mb'] - $before['memory_mb']);
        });
    }

    public function test_usage_aggregation_prices_gpu_time(): void
    {
        // multipliers of 1: the entry point surcharge (HTTP × 5 by default) is not what this test prices
        CostConfig::create(['region_id' => null, 'currency' => 'USD', 'is_active' => true, 'gpu_price_per_unit_ms' => '0.000001', 'cpu_price_per_mc_ms' => 0, 'memory_price_per_mib_ms' => 0, 'multiplier_http' => 1, 'multiplier_queue' => 1, 'multiplier_schedule' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00'));

        $ids = $this->inTenant(function () {
            // scale to zero: two sessions of 60 s, one with 2 pods → (60 000 + 120 000) ms × 1 GPU
            $stz = $this->gpuProject('stz', 1);
            $v = Version::create(['project_id' => $stz->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
            $d = Deployment::create(['project_id' => $stz->id, 'version_id' => $v->id, 'status' => 'active']);
            foreach ([[60_000, 1], [60_000, 2]] as [$ms, $pods]) {
                DeploymentSession::create([
                    'project_id' => $stz->id, 'deployment_id' => $d->id, 'region_id' => $this->regionId, 'entry_point' => 'http',
                    'started_at' => now()->subMinutes(5), 'ended_at' => now(), 'duration_ms' => $ms, 'pod_count' => $pods,
                    'cpu_used_ms' => 0, 'memory_used_mb_ms' => 0,
                ]);
            }

            // custom scale: min_scale 2 × 1 GPU × a day
            $cs = $this->gpuProject('always-on', 1, 2, false);
            $v2 = Version::create(['project_id' => $cs->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
            Deployment::create(['project_id' => $cs->id, 'version_id' => $v2->id, 'status' => 'active']);

            // model service: 30 GPU-seconds metered today
            $service = $this->gpuService(1, 0);
            AiModelServiceUsage::create([
                'ai_model_service_id' => $service->id, 'knative_region_id' => $this->regionId, 'record_date' => now()->toDateString(),
                'replica_ms' => 30_000, 'gpu_unit_ms' => 30_000, 'cpu_mc_ms' => 60_000_000, 'memory_mib_ms' => 30_000 * 8192,
            ]);

            return [$stz->id, $cs->id, $service->id];
        });

        (new UsageAggregationJob)->handle(app(CostCalculationService::class));

        $this->inTenant(function () use ($ids) {
            [$stz, $cs, $service] = $ids;

            $stzRecord = UsageRecord::where('project_id', $stz)->first();
            $this->assertSame(180_000, (int) $stzRecord->total_gpu_unit_ms);
            $this->assertSame(1, (int) $stzRecord->gpu_count);
            $this->assertEqualsWithDelta(0.18, (float) $stzRecord->final_cost, 0.000001);

            $csRecord = UsageRecord::where('project_id', $cs)->first();
            $this->assertSame(2 * 86_400_000, (int) $csRecord->total_gpu_unit_ms);
            $this->assertEqualsWithDelta(172.8, (float) $csRecord->final_cost, 0.0001);

            $modelRecord = UsageRecord::where('project_id', $service)->first();
            $this->assertSame('model', $modelRecord->source);
            $this->assertSame(30_000, (int) $modelRecord->total_gpu_unit_ms);
            $this->assertEqualsWithDelta(0.03, (float) $modelRecord->final_cost, 0.000001);
        });

        Carbon::setTestNow();
    }

    public function test_the_meter_samples_running_model_pods(): void
    {
        $this->inTenant(function () {
            $service = $this->gpuService(2, 0);
            $now = Carbon::parse('2026-10-11 12:00:00');
            $pods = [
                $this->gpuPod('m-a', 'gpu-a', 2, [AiModelService::LABEL => $service->id]),
                $this->gpuPod('m-b', 'gpu-a', 2, [AiModelService::LABEL => $service->id], 'Pending'),
                $this->gpuPod('other', 'gpu-a', 1, [AiModelService::LABEL => 'someone-else']),
            ];
            $pods[0]['status']['startTime'] = $now->copy()->subSeconds(30)->toIso8601String();

            // first sample: from the pod's start (30 s)
            $delta = ModelUsageMeter::sample($service, $pods, $now, null);
            $this->assertSame(30_000, $delta['replica_ms']);
            $this->assertSame(60_000, $delta['gpu_unit_ms'], '2 GPUs');
            $this->assertSame(30_000 * 2000, $delta['cpu_mc_ms']);
            $this->assertSame(30_000 * 8192, $delta['memory_mib_ms']);

            // later samples: since the previous one, at most two intervals
            $pods[0]['status']['startTime'] = $now->copy()->subHour()->toIso8601String();
            $this->assertSame(60_000, ModelUsageMeter::sample($service, $pods, $now, $now->copy()->subMinute())['replica_ms']);
            $this->assertSame(120_000, ModelUsageMeter::sample($service, $pods, $now, $now->copy()->subMinutes(30))['replica_ms']);

            app(ModelUsageMeter::class)->meterService($service, $pods, $now);
            app(ModelUsageMeter::class)->meterService($service, $pods, $now->copy()->addMinute());
            // the first sample has no previous one: since the pod's start (an hour ago), at most two
            // intervals (120 s); the second one 60 s after it — one row for the day
            $this->assertSame(1, AiModelServiceUsage::where('ai_model_service_id', $service->id)->count());
            $usage = AiModelServiceUsage::where('ai_model_service_id', $service->id)->first();
            $this->assertSame(180_000, (int) $usage->replica_ms, '120 s + 60 s');
            $this->assertSame(360_000, (int) $usage->gpu_unit_ms, '2 GPUs');
        });
    }
}
