<?php

namespace Tests\Feature\Rightsizing;

use App\Jobs\Rightsizing\RightsizingJob;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ResourcePeak;
use App\Services\Rightsizing\PeakCollector;
use App\Services\Rightsizing\ResourcePeakQuery;
use App\Services\Rightsizing\RightsizingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The daily peaks (docs/monitoring.md#rightsizing): every run fills the days of the 14-day window
 * a running project has no row for, each with the size of the deployment that ran that day; a day
 * nothing ran is recorded without asking Prometheus, a region whose Prometheus does not answer is
 * tried again on the next run, demo regions and stopped projects are not read.
 */
class PeakCollectorTest extends TestCase
{
    use RefreshDatabase, RightsizingFixtures;

    /** @var list<array{project: string, trigger: string, until: string}> */
    private array $asked = [];

    /** project name => null (Prometheus does not answer) */
    private array $unreachable = [];

    /** Whether the mocked days report OOM kills. */
    private bool $oom = true;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 02:10:00');

        $this->mock(ResourcePeakQuery::class, function ($mock) {
            $mock->shouldReceive('day')->andReturnUsing(function (KnativeRegion $region, Project $project, string $trigger, Carbon $until) {
                $this->asked[] = ['project' => $project->name, 'trigger' => $trigger, 'until' => $until->format('Y-m-d H:i')];
                if (array_key_exists($project->name, $this->unreachable)) {
                    return null;
                }

                return [
                    'memory_max' => 141.37, 'memory_p95' => 120.5, 'cpu_max' => 230.2, 'cpu_p95' => 84.1,
                    'throttled' => 0.012345, 'oom_events' => $this->oom ? 1.0 : 0.0, 'oom_restarts' => $this->oom ? 2.0 : null, 'samples' => 3600.0, 'pods' => 6.0,
                ];
            });
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function collector(): PeakCollector
    {
        return app(PeakCollector::class);
    }

    public function test_the_window_is_read_with_the_size_that_ran_each_day(): void
    {
        $region = $this->realRegion();
        $project = $this->sizedProject('orders', $region, '250m', 256);
        // until midday of 2026-10-05 the project ran 512 MiB / 500m
        $project->activeDeployment->update(['ready_at' => '2026-10-05 12:00:00']);
        $this->runningDeployment($project, '2026-09-20 08:00:00', ['region_id' => $region->id, 'cpu_m' => 500, 'memory_mb' => 512, 'trigger_type' => 'http',
            'min_scale' => 0, 'max_scale' => 3, 'scale_to_zero' => true, 'gpu_count' => 0], 'shut_down', '2026-10-05 12:00:00');

        $this->assertSame(14, $this->collector()->collect());

        $rows = ResourcePeak::query()->where('project_id', $project->id)->orderBy('day')->get();
        $this->assertSame('2026-10-01', $rows->first()->day->toDateString());
        $this->assertSame('2026-10-14', $rows->last()->day->toDateString());
        $sizes = $rows->mapWithKeys(fn (ResourcePeak $p) => [$p->day->toDateString() => $p->memory_mb.'/'.$p->cpu_m]);
        $this->assertSame('512/500', $sizes['2026-10-04']);
        $this->assertSame('256/250', $sizes['2026-10-05'], 'the deployment that became ready that day');
        $this->assertSame('256/250', $sizes['2026-10-14']);

        $row = $rows->last();
        $this->assertSame($region->id, $row->region_id);
        $this->assertSame(141.37, $row->memory_max_mb);
        $this->assertSame(120.5, $row->memory_p95_mb);
        $this->assertSame(231, $row->cpu_max_m, 'millicores rounded up');
        $this->assertSame(85, $row->cpu_p95_m);
        $this->assertSame(0.0123, $row->throttled_ratio);
        $this->assertSame(2, $row->oom_kills, 'the higher of the two OOM signals');
        $this->assertSame(3600, $row->samples);
        $this->assertSame(6, $row->pods);

        // each day evaluated at its end, with the trigger of the size
        $this->assertSame(['project' => 'orders', 'trigger' => 'http', 'until' => '2026-10-02 00:00'], $this->asked[0]);
        $this->assertSame('2026-10-15 00:00', end($this->asked)['until']);

        // the next night reads only the new day
        $this->asked = [];
        Carbon::setTestNow('2026-10-16 02:10:00');
        $this->assertSame(1, $this->collector()->collect());
        $this->assertSame([['project' => 'orders', 'trigger' => 'http', 'until' => '2026-10-16 00:00']], $this->asked);
    }

    public function test_days_before_the_project_ran_are_recorded_without_reading(): void
    {
        $region = $this->realRegion();
        $late = $this->sizedProject('late', $region, readyAt: '2026-10-10 15:00:00');
        $late->forceFill(['created_at' => Carbon::parse('2026-10-08 09:00:00')])->saveQuietly();

        $this->collector()->collect();

        $rows = ResourcePeak::query()->where('project_id', $late->id)->orderBy('day')->get();
        $this->assertSame(['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'],
            $rows->map(fn (ResourcePeak $p) => $p->day->toDateString())->all(), 'from the day the project was created');
        $this->assertSame(0, $rows[0]->samples);
        $this->assertNull($rows[0]->memory_max_mb);
        $this->assertCount(5, $this->asked, 'only the days a deployment ran: 10 … 14');
    }

    public function test_demo_regions_and_stopped_projects_are_not_read(): void
    {
        $demo = $this->realRegion('demo', ['is_demo' => true]);
        $noCluster = $this->realRegion('bare', ['kubeconfig' => null]);
        $this->sizedProject('showcase', $demo);
        $this->sizedProject('detached', $noCluster);
        $stopped = $this->sizedProject('paused', $this->realRegion('eu-2'));
        $stopped->forceFill(['stopped_at' => now()->subDay()])->saveQuietly();

        $this->assertSame(0, $this->collector()->collect());
        $this->assertSame([], $this->asked);
        $this->assertSame(0, ResourcePeak::count());
    }

    public function test_a_region_whose_prometheus_does_not_answer_is_read_again_next_time(): void
    {
        $down = $this->realRegion('down');
        $first = $this->sizedProject('first', $down);
        $this->sizedProject('second', $down);
        $this->sizedProject('elsewhere', $this->realRegion('up'));
        $this->unreachable = ['first' => null, 'second' => null];

        $this->collector()->collect();

        $this->assertSame(0, ResourcePeak::query()->where('project_id', $first->id)->count(), 'nothing written: read again');
        $this->assertSame(1, collect($this->asked)->whereIn('project', ['first', 'second'])->count(), 'the region is not asked again in this run');
        $this->assertSame(14, collect($this->asked)->where('project', 'elsewhere')->count());

        $this->unreachable = [];
        $this->asked = [];
        $this->collector()->collect();
        $this->assertSame(14, ResourcePeak::query()->where('project_id', $first->id)->count());
    }

    public function test_peaks_past_the_retention_are_pruned(): void
    {
        $project = $this->sizedProject('orders', $this->realRegion());
        ResourcePeak::create(['project_id' => $project->id, 'day' => '2026-09-10']);
        ResourcePeak::create(['project_id' => $project->id, 'day' => '2026-09-20']);

        $this->assertSame(1, $this->collector()->prune());
        $this->assertSame(['2026-09-20'], ResourcePeak::pluck('day')->map(fn ($d) => Carbon::parse($d)->toDateString())->all());
    }

    public function test_the_nightly_job_reads_and_recommends(): void
    {
        $this->oom = false;
        $project = $this->sizedProject('orders', $this->realRegion(), alwaysOn: true);

        (new RightsizingJob)->handle(app(PeakCollector::class), app(RightsizingService::class));

        $this->assertSame(14, ResourcePeak::query()->where('project_id', $project->id)->count());
        $recommendation = app(RightsizingService::class)->current($project);
        $this->assertNotNull($recommendation, '141 MiB of 512 at most: smaller');
        $this->assertSame(256, $recommendation->recommended_memory_mb);
        $this->assertSame(125, $recommendation->recommended_cpu_m);
        $this->assertSame(['memory_over_provisioned', 'cpu_over_provisioned'], $recommendation->reasons);

        config(['rightsizing.enabled' => false]);
        $this->asked = [];
        (new RightsizingJob('2026-10-20 02:10:00'))->handle(app(PeakCollector::class), app(RightsizingService::class));
        $this->assertSame([], $this->asked, 'off: nothing is read');
    }
}
