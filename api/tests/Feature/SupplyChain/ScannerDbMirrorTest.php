<?php

namespace Tests\Feature\SupplyChain;

use App\Events\SupplyChainAuditEvent;
use App\Jobs\RefreshScannerDbJob;
use App\Models\RegionSupplyChainSetting;
use App\Services\SupplyChain\ScannerDbMirrorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * Offline / air-gapped scanning: the Trivy DB copied into the region registry by a Job, its build
 * time recorded, the scheduler refreshing "mirror" regions only.
 */
class ScannerDbMirrorTest extends TestCase
{
    use FakesSupplyChainCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Event::fake([SupplyChainAuditEvent::class]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSupplyChain();
        parent::tearDown();
    }

    public function test_the_mirror_job_copies_the_db_and_records_its_build_time(): void
    {
        $region = $this->scRegion();
        $this->scSettings($region, ['scanner_db_mode' => 'mirror']);
        $this->podLog = implode("\n", [
            'RUNR_STEP mirror ok',
            'RUNR_STEP mirror-java ok',
            'RUNR_STEP verify ok',
            'RUNR_FILE trivy-version '.base64_encode(gzencode((string) file_get_contents(base_path('tests/Fixtures/supply-chain/trivy-version.json')))),
            'RUNR_DONE',
        ]);

        // sync queue: submit, then the first check finds the Job done
        app(ScannerDbMirrorService::class)->refresh($region);

        $job = $this->scCreated('/apis/batch/v1/namespaces/runr-supply-chain/jobs');
        $env = collect($job['spec']['template']['spec']['containers'][0]['env'])->pluck('value', 'name');
        $this->assertSame(['mirror-db'], $job['spec']['template']['spec']['containers'][0]['args']);
        $this->assertSame('mirror.gcr.io/aquasec/trivy-db:2', $env['RUNR_DB_SOURCE']);
        $this->assertSame(self::SC_ZOT.'/runr-supply-chain/trivy-db:2', $env['RUNR_DB_TARGET']);

        $settings = RegionSupplyChainSetting::forRegion($region);
        $this->assertSame('ready', $settings->scanner_db_status);
        $this->assertSame('2026-10-10 06:00:00', $settings->scanner_db_updated_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($settings->scanner_db_refreshed_at);
        $this->assertNull($settings->scanner_db_message);
        Event::assertDispatched(SupplyChainAuditEvent::class, fn ($e) => $e->action === 'region.scanner_db.refreshed');
    }

    public function test_a_failed_copy_and_a_failed_java_db(): void
    {
        $region = $this->scRegion();

        $this->podLog = 'RUNR_STEP mirror failed '.base64_encode('mirror.gcr.io: no route to host')."\nRUNR_DONE\n";
        app(ScannerDbMirrorService::class)->refresh($region);
        $settings = RegionSupplyChainSetting::forRegion($region);
        $this->assertSame('failed', $settings->scanner_db_status);
        $this->assertStringContainsString('no route to host', $settings->scanner_db_message);

        $this->podLog = "RUNR_STEP mirror ok\nRUNR_STEP mirror-java failed ".base64_encode('quota')."\nRUNR_STEP verify ok\nRUNR_DONE\n";
        app(ScannerDbMirrorService::class)->refresh($region);
        $settings = RegionSupplyChainSetting::forRegion($region);
        $this->assertSame('ready', $settings->scanner_db_status, 'the vulnerability DB is what scans need');
        $this->assertStringContainsString('Java DB: quota', $settings->scanner_db_message);
    }

    public function test_the_scheduler_refreshes_due_mirror_regions_only(): void
    {
        Bus::fake([RefreshScannerDbJob::class]);
        $due = $this->scRegion();
        $fresh = $this->scRegion();
        $offline = $this->scRegion();
        $online = $this->scRegion();
        $this->scSettings($due, ['scanner_db_mode' => 'mirror', 'scanner_db_refreshed_at' => now()->subDay()]);
        $this->scSettings($fresh, ['scanner_db_mode' => 'mirror', 'scanner_db_refreshed_at' => now()->subHour()]);
        $this->scSettings($offline, ['scanner_db_mode' => 'offline']);
        $this->scSettings($online, ['scanner_db_mode' => 'online']);

        $this->assertSame(1, app(ScannerDbMirrorService::class)->refreshDue());

        Bus::assertDispatchedTimes(RefreshScannerDbJob::class, 1);
        Bus::assertDispatched(RefreshScannerDbJob::class, fn ($job) => $job->region->is($due));
        $this->assertSame('refreshing', RegionSupplyChainSetting::forRegion($due)->scanner_db_status);
    }

    public function test_a_running_refresh_is_not_started_twice(): void
    {
        Bus::fake([RefreshScannerDbJob::class]);
        $region = $this->scRegion();
        $this->scSettings($region, ['scanner_db_status' => 'refreshing']);

        app(ScannerDbMirrorService::class)->refresh($region);

        Bus::assertNotDispatched(RefreshScannerDbJob::class);
    }
}
