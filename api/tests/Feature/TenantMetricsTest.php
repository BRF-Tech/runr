<?php

namespace Tests\Feature;

use App\Jobs\DownsampleMetricsJob;
use App\Jobs\SnapshotMetricsJob;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Version;
use App\Services\KnativeMetricsService;
use App\Services\MetricsPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Multi-tenant installs: tenant projects keep their metric snapshots in the tenant's schema. The
 * minute samples (SnapshotMetricsJob) and the downsampling (DownsampleMetricsJob) run in the
 * central schema and in every tenant schema — on a tenant's copy of a platform region too, whose
 * kubeconfig is read from central.
 */
class TenantMetricsTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private KnativeRegion $region;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);
        Carbon::setTestNow('2026-10-15 12:00:00');

        $this->region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true, 'kubeconfig' => 'apiVersion: v1']);
        // a synced copy reads its kubeconfig from central (TenancyServiceProvider's resolver)
        KnativeRegion::setKubeconfigResolver(fn (KnativeRegion $region) => ($region->getAttributes()['is_synced'] ?? false)
            ? KnativeRegion::on('central')->whereKey($region->getKey())->value('kubeconfig')
            : null);

        $this->tenant = $this->createTenantWithSchema('acme', [
            ...self::TENANT_REGION_MIGRATIONS,
            'Modules/Tenancy/Database/TenantMigrations/0013_create_metric_snapshots_table.php',
            'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        ]);
        $this->tenant->run(function () {
            $copy = new KnativeRegion(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
            $copy->forceFill(['id' => $this->region->id, 'is_synced' => true])->saveQuietly();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    /** A FaaS project with an active deployment in the current schema. */
    private function project(string $name): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);

        return $project;
    }

    /** @return list<array{name: string, cpu: float}> the current schema's snapshots */
    private function snapshots(string $granularity): array
    {
        return MetricSnapshot::where('granularity', $granularity)->with('project')->get()
            ->map(fn (MetricSnapshot $s) => ['name' => $s->project->name, 'cpu' => round((float) $s->cpu_cores, 4)])
            ->sortBy('name')->values()->all();
    }

    public function test_tenant_projects_are_sampled_into_the_tenant_schema(): void
    {
        $this->project('platform-app');
        $this->tenant->run(fn () => $this->project('orders-acme'));

        $metrics = Mockery::mock(KnativeMetricsService::class);
        $metrics->shouldReceive('getDeploymentMetrics')->andReturnUsing(fn (Project $project) => match ($project->name) {
            'orders-acme' => ['cpu' => 0.25, 'memory' => 64.0, 'rps' => 2.0],
            default => ['cpu' => 0.5, 'memory' => 128.0, 'rps' => 1.0],
        });
        $this->app->instance(KnativeMetricsService::class, $metrics);

        (new SnapshotMetricsJob)->handle(app(MetricsPersistenceService::class));

        $this->assertSame([['name' => 'platform-app', 'cpu' => 0.5]], $this->snapshots('1m'));
        $this->assertSame([['name' => 'orders-acme', 'cpu' => 0.25]], $this->tenant->run(fn () => $this->snapshots('1m')));
    }

    public function test_tenant_snapshots_are_downsampled_in_the_tenant_schema(): void
    {
        $sample = function (Project $project, string $at, float $cpu) {
            MetricSnapshot::create([
                'project_id' => $project->id, 'region_id' => $this->region->id,
                'recorded_at' => $at, 'granularity' => '1m', 'cpu_cores' => $cpu,
            ]);
        };
        $central = $this->project('platform-app');
        $sample($central, '2026-10-01 10:00:00', 0.1);
        $sample($central, '2026-10-01 10:01:00', 0.3);
        $this->tenant->run(function () use ($sample) {
            $project = $this->project('orders-acme');
            $sample($project, '2026-10-01 10:00:00', 0.5);
            $sample($project, '2026-10-01 10:04:00', 0.7);
            $sample($project, '2026-10-14 10:00:00', 0.9);   // a day old: stays a minute sample
        });

        (new DownsampleMetricsJob)->handle(app(MetricsPersistenceService::class));

        $this->assertSame([['name' => 'platform-app', 'cpu' => 0.2]], $this->snapshots('5m'));
        $this->assertSame([], $this->snapshots('1m'));
        $this->assertSame([['name' => 'orders-acme', 'cpu' => 0.6]], $this->tenant->run(fn () => $this->snapshots('5m')));
        $this->assertSame([['name' => 'orders-acme', 'cpu' => 0.9]], $this->tenant->run(fn () => $this->snapshots('1m')));
    }
}
