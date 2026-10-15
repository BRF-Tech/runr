<?php

namespace Tests\Feature;

use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Jobs\UsageAggregationJob;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\CostCalculationService;
use Tests\TestCase;

/**
 * Every run (every 5 minutes) re-aggregates the whole day into one usage record per project,
 * deployment, region, scale mode and entry point. The record was looked up with an equality on
 * the date string while the date cast stores "Y-m-d 00:00:00" where the column is text (SQLite):
 * each run added another record of the day, and billing summed them all.
 */
class UsageAggregationDailyRecordTest extends TestCase
{
    use RefreshDatabase;

    private KnativeRegion $region;

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

        // The GPU usage columns of TenantMigrations/0045 (as in UsageCurrencyTest)
        Schema::table('usage_records', function (Blueprint $table) {
            $table->string('source', 16)->default('project');
            $table->unsignedInteger('gpu_count')->default(0);
            $table->unsignedBigInteger('total_gpu_unit_ms')->default(0);
        });

        Carbon::setTestNow(Carbon::parse('2026-10-11 12:00:00'));
        $this->region = KnativeRegion::create(['name' => 'fn', 'project_namespace' => 'fn', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_repeated_runs_update_the_days_custom_scale_record(): void
    {
        [$project] = $this->project('always-on', scaleToZero: false, minScale: 1);

        $this->aggregate($project);
        $project->containerConfig->update(['min_scale' => 3]);
        $this->aggregate($project);

        $record = UsageRecord::sole();
        $this->assertSame(3, (int) $record->min_scale, 'the second run updated the record');
        $this->assertSame('2026-10-11', $record->record_date->toDateString());
    }

    public function test_repeated_runs_update_the_days_scale_to_zero_record(): void
    {
        [$project, $deployment] = $this->project('on-demand', scaleToZero: true);

        $this->endedSession($project, $deployment, 60_000);
        $this->aggregate($project);
        $this->endedSession($project, $deployment, 30_000);
        $this->aggregate($project);

        $record = UsageRecord::sole();
        $this->assertSame(2, (int) $record->session_count);
        $this->assertSame(90_000, (int) $record->total_duration_ms);
    }

    public function test_the_next_day_gets_its_own_record(): void
    {
        [$project] = $this->project('always-on', scaleToZero: false, minScale: 1);

        $this->aggregate($project);
        Carbon::setTestNow(Carbon::parse('2026-10-12 00:05:00'));
        $this->aggregate($project);

        $this->assertSame(
            ['2026-10-11', '2026-10-12'],
            UsageRecord::orderBy('record_date')->get()->map(fn ($r) => $r->record_date->toDateString())->all(),
        );
    }

    /** @return array{Project, Deployment} */
    private function project(string $name, bool $scaleToZero, int $minScale = 0): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => 'nginx:1.27-alpine',
            'cpu' => '250m',
            'memory_mb' => 128,
            'trigger_type' => 'http',
            'scale_to_zero' => $scaleToZero,
            'min_scale' => $minScale,
        ]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        $deployment = $project->deployments()->create(['version_id' => $version->id, 'status' => 'active', 'k8s_job_name' => "run-{$name}-v1", 'deployed_by' => 'dev']);

        return [$project, $deployment];
    }

    private function endedSession(Project $project, Deployment $deployment, int $ms): void
    {
        DeploymentSession::create([
            'project_id' => $project->id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id,
            'entry_point' => 'http', 'started_at' => now()->subMinutes(2), 'ended_at' => now(),
            'duration_ms' => $ms, 'cpu_used_ms' => 1000, 'memory_used_mb_ms' => 1000,
        ]);
    }

    /** One project's part of a run, for the clock's current day. */
    private function aggregate(Project $project): void
    {
        $job = new UsageAggregationJob;
        (new \ReflectionMethod($job, 'aggregateProject'))
            ->invoke($job, $project->fresh()->load('activeDeployment'), now()->toDateString(), app(CostCalculationService::class));
    }
}
