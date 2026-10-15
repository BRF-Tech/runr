<?php

namespace Tests\Feature\SupplyChain;

use App\Jobs\StartSupplyChainJob;
use App\Models\RegionSupplyChainSetting;
use App\Services\SupplyChain\SupplyChainPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\FakesSupplyChainCluster;
use Tests\TestCase;

/**
 * An upgrade never starts supply-chain Jobs by itself: regions that existed before the supply
 * chain keep SBOM and scan off until an administrator enables them; regions created afterwards
 * start with both on.
 */
class SupplyChainUpgradeDefaultsTest extends TestCase
{
    use FakesSupplyChainCluster, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeSupplyChainCluster();
        Bus::fake([StartSupplyChainJob::class]);
    }

    private function runUpgradeMigration(): void
    {
        (require base_path('database/migrations/2026_10_10_400001_keep_supply_chain_off_for_existing_regions.php'))->up();
    }

    public function test_existing_regions_keep_sbom_and_scan_off(): void
    {
        $existing = $this->scRegion(['name' => 'before-upgrade']);
        $this->runUpgradeMigration();
        $this->runUpgradeMigration(); // idempotent

        $settings = RegionSupplyChainSetting::forRegion($existing);
        $this->assertTrue($settings->exists);
        $this->assertFalse($settings->sbom_enabled);
        $this->assertFalse($settings->scan_enabled);
        $this->assertSame(24, $settings->rescan_interval_hours);
        $this->assertSame(1, RegionSupplyChainSetting::count());

        // Nothing runs after a build there
        $this->assertNull(app(SupplyChainPipeline::class)->queueForVersion($this->scBuiltVersion($existing)));
        Bus::assertNotDispatched(StartSupplyChainJob::class);
    }

    public function test_new_regions_start_with_sbom_and_scan_on(): void
    {
        $this->runUpgradeMigration();
        $new = $this->scRegion(['name' => 'after-upgrade']);

        $settings = RegionSupplyChainSetting::forRegion($new);
        $this->assertFalse($settings->exists, 'no row: the defaults apply');
        $this->assertTrue($settings->sbom_enabled);
        $this->assertTrue($settings->scan_enabled);

        $this->assertSame(['sbom', 'attach', 'scan'], app(SupplyChainPipeline::class)->queueForVersion($this->scBuiltVersion($new))->steps);
    }

    public function test_an_administrator_turns_an_existing_region_on(): void
    {
        $existing = $this->scRegion(['name' => 'before-upgrade']);
        $this->runUpgradeMigration();

        RegionSupplyChainSetting::forRegion($existing)->forceFill(['sbom_enabled' => true, 'scan_enabled' => true])->save();

        $this->assertSame(['sbom', 'attach', 'scan'], app(SupplyChainPipeline::class)->queueForVersion($this->scBuiltVersion($existing))->steps);
    }
}
