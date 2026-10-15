<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Jobs\UsageAggregationJob;
use Modules\Tenancy\Models\CostConfig;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\CostCalculationService;
use Tests\TestCase;

/**
 * Without a cost config the aggregation passed currency = null and every run
 * failed on usage_records.currency NOT NULL — usage metering was lost. Usage is now recorded at
 * zero cost in the default currency (tenancy.billing.default_currency).
 */
class UsageCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CostConfig lives on the "central" connection: point it at the test database
        config(['database.connections.central' => config('database.connections.'.config('database.default'))]);
        DB::connection('central')->setPdo(DB::connection()->getPdo());

        // cost_configs (central) and usage_records (tenant) come from the Tenancy module's migrations
        foreach ([
            'Modules/Tenancy/Database/Migrations/2026_03_14_000001_create_cost_configs_table.php',
            'Modules/Tenancy/Database/Migrations/2026_03_18_000001_add_monthly_prices_to_cost_configs.php',
            'Modules/Tenancy/Database/TenantMigrations/0009_create_usage_records_table.php',
        ] as $migration) {
            (require base_path($migration))->up();
        }

        // The GPU usage columns of TenantMigrations/0045 (its region and config columns exist in
        // this central schema already, so only its usage_records part applies here)
        Schema::table('usage_records', function (Blueprint $table) {
            $table->string('source', 16)->default('project');
            $table->unsignedInteger('gpu_count')->default(0);
            $table->unsignedBigInteger('total_gpu_unit_ms')->default(0);
        });
    }

    public function test_without_a_cost_config_usage_is_unpriced_in_the_default_currency(): void
    {
        $cost = app(CostCalculationService::class)->calculateDailyCost(1, 250, 128, 'http', null);

        $this->assertSame('USD', $cost['currency']);
        $this->assertEquals(0, $cost['final_cost']);
        $this->assertEquals(1.0, $cost['multiplier']);

        config(['tenancy.billing.default_currency' => 'eur']);
        $this->assertSame('EUR', app(CostCalculationService::class)->calculateSessionCost(1000, 1000, 'queue', null)['currency']);

        config(['tenancy.billing.default_currency' => 'not-a-currency']);
        $this->assertSame('USD', CostConfig::defaultCurrency());
    }

    public function test_a_cost_config_keeps_its_currency(): void
    {
        CostConfig::create(['region_id' => null, 'currency' => 'TRY', 'is_active' => true]);

        $cost = app(CostCalculationService::class)->calculateDailyCost(1, 1000, 1024, 'http', null);

        $this->assertSame('TRY', $cost['currency']);
        $this->assertGreaterThan(0, $cost['final_cost']);
    }

    public function test_custom_scale_usage_is_recorded_without_a_cost_config(): void
    {
        $region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
        $project = Project::create(['name' => 'hello-caas', 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '0.5',
            'memory_mb' => 256,
            'trigger_type' => 'http',
            'scale_to_zero' => false,
            'min_scale' => 2,
        ]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => 'run-hello-caas-v1', 'deployed_by' => 'dev']);

        $job = new UsageAggregationJob;
        $aggregate = new \ReflectionMethod($job, 'aggregateProject');
        $aggregate->invoke($job, $project->fresh()->load('activeDeployment'), now()->toDateString(), app(CostCalculationService::class));

        $record = UsageRecord::sole();
        $this->assertSame('USD', $record->currency);
        $this->assertSame('custom_scale', $record->scale_mode);
        $this->assertSame(500, (int) $record->reserved_cpu_m, 'cpu "0.5" = 500 millicores');
        $this->assertSame(2, (int) $record->min_scale);
        $this->assertEquals(0, (float) $record->final_cost);

        $this->assertSame(1, $job->tries, 'a failing run is not repeated immediately; the next scheduled run catches up');
    }
}
