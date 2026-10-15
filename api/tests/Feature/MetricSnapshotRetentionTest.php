<?php

namespace Tests\Feature;

use App\Jobs\PruneMetricSnapshotsJob;
use App\Models\KnativeRegion;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Services\MetricsPersistenceService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Metric snapshot retention (metric-snapshot-retention, daily): snapshots older than 90 days are
 * removed, the daily ones kept — in the central schema and in every tenant schema.
 */
class MetricSnapshotRetentionTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    /** Snapshots around the 90-day retention (now: 2026-10-15 12:00) */
    private const ROWS = [
        'expired hour' => ['2026-07-17 11:00:00', '1h'],      // 90 days and an hour old
        'expired minute' => ['2026-06-01 10:00:00', '1m'],
        'old day' => ['2026-06-01 00:00:00', '1d'],           // daily snapshots are kept
        'within retention' => ['2026-07-17 12:30:00', '1h'],  // just under 90 days
        'recent' => ['2026-10-14 10:00:00', '1m'],
    ];

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /** One snapshot per [recorded_at, granularity] for a project of the current schema. @return array<string, string> label → id */
    private function snapshots(array $rows): array
    {
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

        $ids = [];
        foreach ($rows as $label => [$at, $granularity]) {
            $ids[$label] = MetricSnapshot::create([
                'project_id' => $project->id, 'region_id' => $this->region->id,
                'recorded_at' => $at, 'granularity' => $granularity, 'cpu_cores' => 0.1,
            ])->id;
        }

        return $ids;
    }

    public function test_the_retention_runs_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === 'metric-snapshot-retention');

        $this->assertNotNull($event, 'scheduled task metric-snapshot-retention');
        $this->assertSame('15 4 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_only_snapshots_past_the_retention_are_removed_except_the_daily_ones(): void
    {
        $ids = $this->snapshots(self::ROWS);

        (new PruneMetricSnapshotsJob)->handle(app(MetricsPersistenceService::class));

        $this->assertEqualsCanonicalizing(
            [$ids['old day'], $ids['within retention'], $ids['recent']],
            MetricSnapshot::pluck('id')->all(),
        );
    }

    public function test_tenant_schemas_are_pruned_too(): void
    {
        config(['tenancy.enabled' => true]);
        $tenant = $this->createTenantWithSchema('acme', [
            ...self::TENANT_REGION_MIGRATIONS,
            'Modules/Tenancy/Database/TenantMigrations/0013_create_metric_snapshots_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        ]);
        $tenantIds = $tenant->run(function () {
            $copy = new KnativeRegion(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
            $copy->forceFill(['id' => $this->region->id, 'is_synced' => true])->saveQuietly();

            return $this->snapshots(self::ROWS);
        });
        $centralIds = $this->snapshots(self::ROWS);

        (new PruneMetricSnapshotsJob)->handle(app(MetricsPersistenceService::class));

        $kept = fn (array $ids) => [$ids['old day'], $ids['within retention'], $ids['recent']];
        $this->assertEqualsCanonicalizing($kept($centralIds), MetricSnapshot::pluck('id')->all());
        $this->assertEqualsCanonicalizing($kept($tenantIds), $tenant->run(fn () => MetricSnapshot::pluck('id')->all()));
    }
}
