<?php

namespace Tests\Feature;

use App\Jobs\DownsampleMetricsJob;
use App\Jobs\SnapshotMetricsJob;
use App\Models\Deployment;
use App\Models\DeploymentSession;
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
use Tests\Concerns\ActsWithRoles;
use Tests\TestCase;

/**
 * Metrics history of a project: every minute the live metrics of the active deployments are
 * kept (SnapshotMetricsJob), hourly the fine samples are folded into coarser ones as they age
 * (DownsampleMetricsJob: 1m → 5m after 7 days, 5m → 1h after 30, 1h → 1d after 90), and the
 * console reads them back (/metrics/history, /metrics/summary with the session totals).
 */
class MetricsHistoryTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->region = KnativeRegion::create(['name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true, 'kubeconfig' => 'apiVersion: v1']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A FaaS project with a deployment in the region ($status active: it is running). */
    private function project(string $name, ?KnativeRegion $region = null, string $status = 'active'): Project
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas']);
        $project->forceFill(['knative_region_id' => ($region ?? $this->region)->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => $status]);

        return $project->fresh();
    }

    private function sample(Project $project, string $at, string $granularity, array $values = []): MetricSnapshot
    {
        return MetricSnapshot::create(array_merge([
            'project_id' => $project->id,
            'region_id' => $project->knative_region_id,
            'recorded_at' => $at,
            'granularity' => $granularity,
            'cpu_cores' => 0.1,
            'memory_mb' => 100,
            'rps' => 1,
            'concurrency' => 1,
            'pod_count' => 1,
            'cold_starts' => 0,
        ], $values));
    }

    /** @return list<array{at: string, cpu: float, memory: float, rps: float, pods: ?int, cold: int}> */
    private function samples(Project $project, string $granularity): array
    {
        return MetricSnapshot::where('project_id', $project->id)->where('granularity', $granularity)->orderBy('recorded_at')->get()
            ->map(fn (MetricSnapshot $s) => [
                'at' => $s->recorded_at->format('Y-m-d H:i'),
                'cpu' => round((float) $s->cpu_cores, 4),
                'memory' => round((float) $s->memory_mb, 2),
                'rps' => round((float) $s->rps, 3),
                'pods' => $s->pod_count,
                'cold' => $s->cold_starts,
            ])->all();
    }

    public function test_the_active_deployments_of_real_regions_are_sampled_every_minute(): void
    {
        $busy = $this->project('busy');
        $quiet = $this->project('quiet');       // no metric at all (scaled to zero, no scrape yet)
        $broken = $this->project('broken');     // Prometheus unreachable
        $this->project('stopped', status: 'failed');
        $demo = KnativeRegion::create(['name' => 'demo', 'project_namespace' => 'demo', 'is_active' => true, 'is_demo' => true, 'kubeconfig' => 'apiVersion: v1']);
        $this->project('showcase', $demo);
        $off = KnativeRegion::create(['name' => 'off', 'project_namespace' => 'off', 'is_active' => false, 'kubeconfig' => 'apiVersion: v1']);
        $this->project('parked', $off);

        $asked = [];
        $metrics = Mockery::mock(KnativeMetricsService::class);
        $metrics->shouldReceive('getDeploymentMetrics')->andReturnUsing(function (Project $project, Deployment $deployment, bool $quick) use (&$asked) {
            $asked[] = [$project->name, $deployment->status, $quick];

            return match ($project->name) {
                'busy' => ['cpu' => 0.25, 'memory' => 128.5, 'rps' => 3.5, 'concurrency' => 2, 'pod_count' => 2],
                'quiet' => ['cpu' => null, 'memory' => null, 'rps' => null],
                default => throw new \RuntimeException('prometheus unreachable'),
            };
        });
        $this->app->instance(KnativeMetricsService::class, $metrics);

        (new SnapshotMetricsJob)->handle(app(MetricsPersistenceService::class));

        // only the real, active region's running deployments — the quick (cached) metrics
        $this->assertEqualsCanonicalizing([['busy', 'active', true], ['quiet', 'active', true], ['broken', 'active', true]], $asked);
        $this->assertSame(1, MetricSnapshot::count(), 'nothing for a project without metrics or with a failed scrape');
        $row = MetricSnapshot::firstOrFail();
        $this->assertSame($busy->id, $row->project_id);
        $this->assertSame($busy->activeDeployment->id, $row->deployment_id);
        $this->assertSame($this->region->id, $row->region_id);
        $this->assertSame('1m', $row->granularity);
        $this->assertSame('2026-10-15 12:00:00', $row->recorded_at->format('Y-m-d H:i:s'));
        $this->assertEqualsWithDelta(0.25, (float) $row->cpu_cores, 1e-6);
        $this->assertEqualsWithDelta(128.5, (float) $row->memory_mb, 1e-6);
        $this->assertEqualsWithDelta(3.5, (float) $row->rps, 1e-6);
        $this->assertSame(2, $row->pod_count);
        $this->assertNotContains($quiet->id, MetricSnapshot::pluck('project_id'));
        $this->assertNotContains($broken->id, MetricSnapshot::pluck('project_id'));
    }

    public function test_aging_samples_are_folded_into_coarser_buckets(): void
    {
        $project = $this->project('orders');
        $other = $this->project('billing');

        // 1m older than 7 days → 5m buckets (averages, cold starts summed, pods rounded)
        $this->sample($project, '2026-10-01 10:00:00', '1m', ['cpu_cores' => 0.1, 'memory_mb' => 100, 'rps' => 1, 'pod_count' => 1, 'cold_starts' => 1]);
        $this->sample($project, '2026-10-01 10:01:00', '1m', ['cpu_cores' => 0.2, 'memory_mb' => 200, 'rps' => 2, 'pod_count' => 2, 'cold_starts' => 0]);
        $this->sample($project, '2026-10-01 10:04:59', '1m', ['cpu_cores' => 0.3, 'memory_mb' => 300, 'rps' => 3, 'pod_count' => 2, 'cold_starts' => 2]);
        $this->sample($project, '2026-10-01 10:05:00', '1m', ['cpu_cores' => 0.5, 'cold_starts' => 0]);
        $this->sample($other, '2026-10-01 10:02:00', '1m', ['cpu_cores' => 0.9]);  // another project: its own bucket
        $this->sample($project, '2026-10-14 09:00:00', '1m');                       // a day old: kept as it is

        // a 5m bucket that already exists (an earlier run): a late 1m sample of it is folded in
        $this->sample($project, '2026-10-02 08:00:00', '5m', ['cpu_cores' => 0.7]);
        $this->sample($project, '2026-10-02 08:03:00', '1m', ['cpu_cores' => 0.1]);

        // 5m older than 30 days → 1h; 1h older than 90 days → 1d
        $this->sample($project, '2026-09-01 10:00:00', '5m', ['cpu_cores' => 0.2, 'cold_starts' => 1]);
        $this->sample($project, '2026-09-01 10:55:00', '5m', ['cpu_cores' => 0.4, 'cold_starts' => 1]);
        $this->sample($project, '2026-07-01 03:00:00', '1h', ['memory_mb' => 300]);
        $this->sample($project, '2026-07-01 22:00:00', '1h', ['memory_mb' => 500]);

        (new DownsampleMetricsJob)->handle(app(MetricsPersistenceService::class));

        $this->assertSame([
            ['at' => '2026-10-14 09:00', 'cpu' => 0.1, 'memory' => 100.0, 'rps' => 1.0, 'pods' => 1, 'cold' => 0],
        ], $this->samples($project, '1m'));
        $this->assertSame([
            ['at' => '2026-10-01 10:00', 'cpu' => 0.2, 'memory' => 200.0, 'rps' => 2.0, 'pods' => 2, 'cold' => 3],
            ['at' => '2026-10-01 10:05', 'cpu' => 0.5, 'memory' => 100.0, 'rps' => 1.0, 'pods' => 1, 'cold' => 0],
            ['at' => '2026-10-02 08:00', 'cpu' => 0.4, 'memory' => 100.0, 'rps' => 1.0, 'pods' => 1, 'cold' => 0],
        ], $this->samples($project, '5m'));
        $this->assertSame([
            ['at' => '2026-09-01 10:00', 'cpu' => 0.3, 'memory' => 100.0, 'rps' => 1.0, 'pods' => 1, 'cold' => 2],
        ], $this->samples($project, '1h'));
        $this->assertSame([
            ['at' => '2026-07-01 00:00', 'cpu' => 0.1, 'memory' => 400.0, 'rps' => 1.0, 'pods' => 1, 'cold' => 0],
        ], $this->samples($project, '1d'));
        $this->assertSame([['at' => '2026-10-01 10:00', 'cpu' => 0.9, 'memory' => 100.0, 'rps' => 1.0, 'pods' => 1, 'cold' => 0]], $this->samples($other, '5m'));

        // a second run finds nothing left to fold
        (new DownsampleMetricsJob)->handle(app(MetricsPersistenceService::class));
        $this->assertSame(7, MetricSnapshot::count());
    }

    public function test_hours_that_age_into_an_existing_day_are_merged_by_their_samples(): void
    {
        $project = $this->project('orders');

        // the hourly run at 2026-07-17 00:10: the first hour of 2026-07-17 − 90 days is old enough
        Carbon::setTestNow('2026-10-15 00:10:00');
        $this->sample($project, '2026-07-17 00:00:00', '1h', ['cpu_cores' => 0.2, 'memory_mb' => 100, 'pod_count' => 1, 'cold_starts' => 1, 'metadata' => ['samples' => 60]]);
        $this->sample($project, '2026-07-17 01:00:00', '1h', ['cpu_cores' => 0.8, 'memory_mb' => 400, 'pod_count' => 3, 'cold_starts' => 2, 'metadata' => ['samples' => 20]]);
        app(MetricsPersistenceService::class)->downsample();

        $day = MetricSnapshot::where('granularity', '1d')->sole();
        $this->assertEqualsWithDelta(0.2, (float) $day->cpu_cores, 1e-6, 'only the first hour so far');
        $this->assertSame(60, $day->metadata['samples']);
        $this->assertSame(1, MetricSnapshot::where('granularity', '1h')->count());

        // an hour later the second hour follows into the same day: merged, weighted 60 : 20
        Carbon::setTestNow('2026-10-15 01:10:00');
        app(MetricsPersistenceService::class)->downsample();

        $day = MetricSnapshot::where('granularity', '1d')->sole();
        $this->assertSame('2026-07-17 00:00', $day->recorded_at->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta((0.2 * 60 + 0.8 * 20) / 80, (float) $day->cpu_cores, 1e-6);
        $this->assertEqualsWithDelta((100 * 60 + 400 * 20) / 80, (float) $day->memory_mb, 1e-2);
        $this->assertSame(2, $day->pod_count, '1.5 rounded');
        $this->assertSame(3, $day->cold_starts, 'cold starts add up');
        $this->assertSame(80, $day->metadata['samples']);
        $this->assertSame(0, MetricSnapshot::where('granularity', '1h')->count());

        // a bucket made before buckets counted their samples weighs one
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->sample($project, '2026-10-01 10:00:00', '5m', ['cpu_cores' => 0.6, 'rps' => null]);
        $this->sample($project, '2026-10-01 10:02:00', '1m', ['cpu_cores' => 0.3, 'rps' => 4]);
        $this->sample($project, '2026-10-01 10:03:00', '1m', ['cpu_cores' => 0.3, 'rps' => null]);
        app(MetricsPersistenceService::class)->downsample();

        $five = MetricSnapshot::where('granularity', '5m')->sole();
        $this->assertEqualsWithDelta(0.4, (float) $five->cpu_cores, 1e-6);
        $this->assertEqualsWithDelta(4.0, (float) $five->rps, 1e-6, 'a sample without a value does not count for it');
        $this->assertSame(3, $five->metadata['samples']);
    }

    public function test_the_history_of_a_project_in_a_time_range_and_granularity(): void
    {
        $project = $this->project('orders');
        $this->sample($project, '2026-10-15 09:55:00', '5m', ['cpu_cores' => 0.1]);   // before the range
        $this->sample($project, '2026-10-15 10:05:00', '5m', ['cpu_cores' => 0.3]);
        $this->sample($project, '2026-10-15 10:00:00', '5m', ['cpu_cores' => 0.2]);
        $this->sample($project, '2026-10-15 10:01:00', '1m', ['cpu_cores' => 0.9]);   // other granularity
        $this->sample($this->project('billing'), '2026-10-15 10:00:00', '5m');       // other project

        $this->signedIn($this->userWithRole('owner'));
        $response = $this->getJson("/api/projects/{$project->id}/metrics/history?from=2026-10-15T10:00:00Z&to=2026-10-15T11:00:00Z")
            ->assertOk()
            ->assertJsonPath('granularity', '5m')
            ->assertJsonPath('from', '2026-10-15T10:00:00+00:00')
            ->assertJsonPath('to', '2026-10-15T11:00:00+00:00');
        $this->assertSame([0.2, 0.3], array_map(fn ($row) => round((float) $row['cpu_cores'], 4), $response->json('data')));

        $minutes = $this->getJson("/api/projects/{$project->id}/metrics/history?from=2026-10-15T10:00:00Z&to=2026-10-15T11:00:00Z&granularity=1m")->assertOk();
        $this->assertCount(1, $minutes->json('data'));

        $this->getJson("/api/projects/{$project->id}/metrics/history?from=2026-10-15T11:00:00Z&to=2026-10-15T10:00:00Z")
            ->assertStatus(422)->assertJsonValidationErrors('to');
        $this->getJson("/api/projects/{$project->id}/metrics/history?from=2026-10-15T10:00:00Z&to=2026-10-15T11:00:00Z&granularity=2m")
            ->assertStatus(422)->assertJsonValidationErrors('granularity');
        $this->getJson("/api/projects/{$project->id}/metrics/history")
            ->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_the_summary_of_a_period_with_the_session_totals(): void
    {
        $project = $this->project('orders');
        $deployment = $project->activeDeployment;
        // Applied at 500m / 1 GiB: what metering counts for the sessions' pod time
        $deployment->forceFill(['sizing' => [
            'region_id' => $this->region->id, 'cpu_m' => 500, 'memory_mb' => 1024, 'trigger_type' => 'http',
            'min_scale' => 0, 'max_scale' => 3, 'scale_to_zero' => true, 'gpu_count' => 0,
        ]])->saveQuietly();
        $this->sample($project, '2026-10-14 10:00:00', '5m', ['cpu_cores' => 0.2, 'memory_mb' => 100, 'rps' => 2, 'cold_starts' => 1]);
        $this->sample($project, '2026-10-01 10:00:00', '1h', ['cpu_cores' => 0.6, 'memory_mb' => 300, 'rps' => 4, 'cold_starts' => 2]);
        $this->sample($project, '2026-08-01 10:00:00', '1d', ['cpu_cores' => 5, 'memory_mb' => 900, 'rps' => 50, 'cold_starts' => 9]); // outside 30 days

        $session = fn (string $start, ?int $ms, bool $cold) => DeploymentSession::create([
            'project_id' => $project->id, 'deployment_id' => $deployment->id, 'region_id' => $this->region->id, 'entry_point' => 'http',
            'started_at' => $start, 'ended_at' => $ms === null ? null : Carbon::parse($start)->addMilliseconds($ms),
            'duration_ms' => $ms, 'cold_start' => $cold, 'cpu_used_ms' => $ms === null ? null : 10, 'memory_used_mb_ms' => $ms === null ? null : 1000,
        ]);
        $session('2026-10-10 10:00:00', 3000, true);
        $session('2026-10-12 10:00:00', 1000, false);
        $session('2026-10-15 11:00:00', null, false);   // still open: not counted
        $session('2026-08-01 10:00:00', 9000, true);    // outside the period

        $this->signedIn($this->userWithRole('owner'));
        $this->getJson("/api/projects/{$project->id}/metrics/summary?period=30d")
            ->assertOk()
            ->assertJsonPath('metrics.period_days', 30)
            ->assertJsonPath('metrics.avg_cpu', 0.4)
            ->assertJsonPath('metrics.max_cpu', 0.6)
            ->assertJsonPath('metrics.avg_memory', 200)
            ->assertJsonPath('metrics.max_memory', 300)
            ->assertJsonPath('metrics.avg_rps', 3)
            ->assertJsonPath('metrics.max_rps', 4)
            ->assertJsonPath('metrics.total_cold_starts', 3)
            ->assertJsonPath('metrics.sample_count', 2)
            ->assertJsonPath('sessions.total_sessions', 2)
            ->assertJsonPath('sessions.total_duration_ms', 4000)
            // 4 s of pod time at 0.5 vCPU / 1 GiB, not the figures on the session rows
            ->assertJsonPath('sessions.total_cpu_ms', 2000)
            ->assertJsonPath('sessions.total_memory_mb_ms', 1024 * 4000)
            ->assertJsonPath('sessions.cold_starts', 1)
            ->assertJsonPath('sessions.avg_duration_ms', 2000);

        // default: the last 7 days; a project without samples has no averages
        $this->getJson("/api/projects/{$project->id}/metrics/summary")
            ->assertOk()
            ->assertJsonPath('metrics.period_days', 7)
            ->assertJsonPath('metrics.sample_count', 1);
        $empty = $this->project('empty');
        $this->getJson("/api/projects/{$empty->id}/metrics/summary")
            ->assertOk()
            ->assertJsonPath('metrics.avg_cpu', null)
            ->assertJsonPath('metrics.total_cold_starts', 0)
            ->assertJsonPath('sessions.total_sessions', 0);
    }

    public function test_samples_past_the_retention_go_except_the_daily_ones(): void
    {
        $project = $this->project('orders');
        $this->sample($project, '2026-06-01 10:00:00', '1h');
        $this->sample($project, '2026-06-01 00:00:00', '1d');
        $this->sample($project, '2026-10-01 10:00:00', '1h');

        $this->assertSame(1, app(MetricsPersistenceService::class)->cleanup(90));

        $this->assertSame(['1d', '1h'], MetricSnapshot::orderBy('granularity')->pluck('granularity')->all());
    }
}
