<?php

namespace Tests\Feature\SupplyChain;

use App\Jobs\RescanDeployedImagesJob;
use App\Jobs\StartSupplyChainJob;
use App\Models\ImageSecurityReport;
use App\Models\RuntimeBuild;
use App\Models\Version;
use App\Services\SupplyChain\RescanService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * Scheduled re-scans: images of live deployments (and ready runtime images) whose last scan is
 * older than the region interval are scanned again; the others are left alone.
 */
class RescanServiceTest extends TestCase
{
    use FakesSupplyChainCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Bus::fake([StartSupplyChainJob::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    private function scanned(Version $version, \DateTimeInterface $at, string $status = 'completed'): ImageSecurityReport
    {
        return ImageSecurityReport::create([
            'subject_type' => 'version', 'subject_id' => $version->id, 'project_id' => $version->project_id, 'version_id' => $version->id,
            'knative_region_id' => $version->project->knative_region_id, 'image_kind' => 'built', 'image_ref' => $version->build_image,
            'status' => $status, 'sbom_status' => 'ok', 'scan_status' => 'ok', 'scanned_at' => $at, 'steps' => ['sbom', 'scan'],
        ]);
    }

    public function test_only_deployed_and_stale_images_are_rescanned(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['rescan_interval_hours' => 24]);

        $stale = $this->scBuiltVersion($region);
        $recent = $this->scBuiltVersion($region);
        $idle = $this->scBuiltVersion($region);
        $running = $this->scBuiltVersion($region);
        foreach ([$stale, $recent, $running] as $v) {
            $v->project->deployments()->create(['version_id' => $v->id, 'status' => 'active']);
        }
        $idle->project->deployments()->create(['version_id' => $idle->id, 'status' => 'shut_down']);

        $this->scanned($stale, now()->subDays(2));
        $this->scanned($recent, now()->subHours(2));
        $this->scanned($idle, now()->subDays(5));
        $this->scanned($running, now()->subDays(5), 'running');

        $this->assertSame(1, app(RescanService::class)->rescanDue());

        Bus::assertDispatchedTimes(StartSupplyChainJob::class, 1);
        $report = ImageSecurityReport::forVersion($stale);
        $this->assertSame(['scan'], $report->steps);
        $this->assertSame('ok', $report->sbom_status, 'the SBOM is kept');
        $this->assertSame('pending', $report->scan_status);
    }

    public function test_interval_zero_turns_rescans_off(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['rescan_interval_hours' => 0]);
        $version = $this->scBuiltVersion($region);
        $version->project->deployments()->create(['version_id' => $version->id, 'status' => 'active']);
        $this->scanned($version, now()->subMonth());

        $this->assertSame(0, app(RescanService::class)->rescanDue());
    }

    public function test_runtime_images_are_rescanned_too(): void
    {
        $region = $this->scRegion();
        $build = RuntimeBuild::create([
            'runtime_id' => 'q', 'version' => '1.0', 'knative_region_id' => $region->id,
            'status' => 'ready', 'image' => self::SC_ZOT.'/runr-runtimes/q:1.0-x', 'digest' => str_repeat('d', 64),
        ]);
        ImageSecurityReport::create([
            'subject_type' => 'runtime_build', 'subject_id' => $build->id, 'knative_region_id' => $region->id,
            'image_kind' => 'runtime', 'image_ref' => $build->image, 'status' => 'completed', 'sbom_status' => 'ok',
            'scan_status' => 'ok', 'scanned_at' => now()->subDays(3),
        ]);

        $this->assertSame(1, app(RescanService::class)->rescanDue());
        $this->assertSame(['scan'], ImageSecurityReport::forRuntimeBuild($build)->steps);
    }

    public function test_the_rescan_is_scheduled_hourly(): void
    {
        $events = collect(app(Schedule::class)->events());

        $rescan = $events->first(fn ($e) => $e->description === 'supply-chain-rescan');
        $this->assertNotNull($rescan);
        $this->assertSame('0 * * * *', $rescan->expression);
        $this->assertNotNull($events->first(fn ($e) => $e->description === 'supply-chain-scanner-db-refresh'));

        (new RescanDeployedImagesJob)->handle(app(RescanService::class));
        $this->addToAssertionCount(1);
    }
}
